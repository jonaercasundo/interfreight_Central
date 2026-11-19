<?php

namespace App\Services;

use App\Models\AiConfiguration;
use App\Models\AiExchangeRate;
use App\Models\AiModelPrice;
use App\Models\AiUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiCredentialService
{
    public function configuration(): ?AiConfiguration
    {
        if (! $this->databaseReady()) {
            return null;
        }

        return AiConfiguration::query()->first();
    }

    public function databaseReady(): bool
    {
        return Schema::hasTable('ai_configurations')
            && Schema::hasColumn('ai_configurations', 'timeout_seconds')
            && Schema::hasColumn('ai_configurations', 'max_output_tokens');
    }

    public function usageReady(): bool
    {
        return Schema::hasTable('ai_usage_events');
    }

    public function enabled(): bool
    {
        return (bool) ($this->configuration()?->enabled ?? config('ifc_ai.enabled', false));
    }

    public function tutorialsEnabled(): bool
    {
        return (bool) ($this->configuration()?->tutorials_enabled ?? config('ifc_ai.tutorials_enabled', true));
    }

    public function credential(): ?string
    {
        $configured = $this->configuration()?->api_key_ciphertext;
        $key = is_string($configured) && $configured !== '' ? $configured : config('ifc_ai.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function model(): string
    {
        return (string) ($this->configuration()?->model ?: config('ifc_ai.model', 'gpt-5.4-mini'));
    }

    /** Never includes the saved credential itself. */
    public function publicState(): array
    {
        $configuration = $this->configuration();
        $databaseKey = $configuration?->api_key_ciphertext;
        $fallback = config('ifc_ai.api_key');
        $effective = is_string($databaseKey) && $databaseKey !== '' ? $databaseKey : (is_string($fallback) ? $fallback : null);
        $source = is_string($databaseKey) && $databaseKey !== '' ? 'administrator' : (($fallback ?? '') !== '' ? 'environment' : 'missing');

        return [
            'provider' => 'openai',
            'enabled' => $this->enabled(),
            'tutorials_enabled' => $this->tutorialsEnabled(),
            'model' => $this->model(),
            'timeout_seconds' => (int) ($configuration?->timeout_seconds ?? config('ifc_ai.request_timeout', 20)),
            'max_output_tokens' => (int) ($configuration?->max_output_tokens ?? config('ifc_ai.max_output_tokens', 500)),
            'database_ready' => $this->databaseReady(),
            'usage_ready' => $this->usageReady(),
            'credential_source' => $source,
            'credential_configured' => $effective !== null,
            'masked_credential' => $effective ? (strlen($effective) > 8 ? '••••••••'.substr($effective, -4) : 'Configured') : null,
            'last_test_status' => $configuration?->last_test_status ?? 'not_tested',
            'last_tested_at' => $configuration?->last_tested_at?->toIso8601String(),
            'last_successful_test_at' => $configuration?->last_successful_test_at?->toIso8601String(),
        ];
    }

    public function usageSummary(): array
    {
        if (! $this->usageReady()) {
            return [
                'period_days' => 30, 'requests' => 0, 'successful' => 0, 'failed' => 0,
                'input_tokens' => 0, 'output_tokens' => 0, 'estimated_cost_php' => null, 'costed_requests' => 0, 'recent' => [],
            ];
        }

        $since = now()->subDays(30);
        $query = AiUsageEvent::query()->where('created_at', '>=', $since);
        $summary = (clone $query)->selectRaw('COUNT(*) as requests, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as successful, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens'.(Schema::hasColumn('ai_usage_events', 'estimated_cost_php') ? ', SUM(estimated_cost_php) as estimated_cost_php, SUM(CASE WHEN estimated_cost_php IS NOT NULL THEN 1 ELSE 0 END) as costed_requests' : ''), ['success', 'error'])->first();

        return [
            'period_days' => 30,
            'requests' => (int) ($summary->requests ?? 0),
            'successful' => (int) ($summary->successful ?? 0),
            'failed' => (int) ($summary->failed ?? 0),
            'input_tokens' => (int) ($summary->input_tokens ?? 0),
            'output_tokens' => (int) ($summary->output_tokens ?? 0),
            'estimated_cost_php' => $summary->estimated_cost_php ?? null,
            'costed_requests' => (int) ($summary->costed_requests ?? 0),
            'recent' => $query->latest('created_at')->limit(10)->get(array_values(array_filter(['provider', 'model', 'status', 'error_category', 'input_tokens', 'output_tokens', 'latency_ms', 'created_at', 'request_type', 'department', 'estimated_cost_usd', 'estimated_cost_php', 'cost_status'], fn ($column) => Schema::hasColumn('ai_usage_events', $column))))->toArray(),
        ];
    }

    public function recordUsage(?User $user, string $model, string $status, ?string $error, ?int $input, ?int $output, ?int $latency, array $metadata = []): void
    {
        if (! $this->usageReady()) {
            return;
        }

        $createdAt = now();
        $attributes = [
            'user_id' => $user?->id, 'provider' => 'openai', 'model' => $model,
            'status' => $status, 'error_category' => $error, 'input_tokens' => $input,
            'output_tokens' => $output, 'latency_ms' => $latency, 'created_at' => now(),
        ];
        if (Schema::hasColumn('ai_usage_events', 'request_type')) {
            $attributes += [
                'request_id' => $metadata['request_id'] ?? (string) Str::uuid(),
                'correlation_id' => $metadata['correlation_id'] ?? null,
                'request_type' => $metadata['request_type'] ?? 'assistant',
                'module' => $metadata['module'] ?? 'assistant',
                'department' => $metadata['department'] ?? $this->departmentFor($user),
                'cached_input_tokens' => $metadata['cached_input_tokens'] ?? null,
                'total_tokens' => $input !== null || $output !== null ? ($input ?? 0) + ($output ?? 0) : null,
                'cost_status' => 'pricing_unavailable',
            ];
            if ($status === 'success' && $input !== null && $output !== null && $model !== '') {
                $price = AiModelPrice::query()->where('provider', 'openai')->where('model', $model)->where('effective_at', '<=', $createdAt)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $createdAt))->latest('effective_at')->first();
                if ($price) {
                    $rate = AiExchangeRate::query()->where('base_currency', 'USD')->where('target_currency', 'PHP')->where('effective_at', '<=', $createdAt)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $createdAt))->latest('effective_at')->first();
                    if ($rate) {
                        $cached = min($input, max(0, (int) ($metadata['cached_input_tokens'] ?? 0)));
                        $regularInput = $input - $cached;
                        $inputCost = ($regularInput * (float) $price->input_usd_per_million + $cached * (float) ($price->cached_input_usd_per_million ?? $price->input_usd_per_million)) / 1000000;
                        $usd = $inputCost + ($output * (float) $price->output_usd_per_million / 1000000);
                        $attributes['estimated_cost_usd'] = number_format($usd, 12, '.', '');
                        $attributes['estimated_cost_php'] = number_format($usd * (float) $rate->rate, 8, '.', '');
                        $attributes['usd_php_rate'] = $rate->rate;
                        $attributes['cost_status'] = 'estimated';
                    } else {
                        $attributes['cost_status'] = 'fx_unavailable';
                    }
                }
            }
        }
        AiUsageEvent::query()->create($attributes);
    }

    private function departmentFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        foreach ($user->getRoleNames() as $role) {
            if (in_array($role, ['Sales', 'Sales Manager'], true)) {
                return 'Sales';
            }
            if (in_array($role, ['Operations', 'Transport', 'Forwarding', 'Accounting', 'Billing', 'Finance', 'Executive'], true)) {
                return $role;
            }
        }

        return null;
    }
}
