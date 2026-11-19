<?php

namespace App\Http\Controllers;

use App\Models\AiConfiguration;
use App\Services\AiConnectionFailure;
use App\Services\AiCredentialService;
use App\Services\AiServiceClient;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiConfigurationController extends Controller
{
    public function page(AiCredentialService $credentials)
    {
        $this->authorizeAdministrator(request(), ['admin.ai-config.view', 'ai.configuration.view']);

        return view('app', ['page' => 'ai-configuration', 'aiConfiguration' => [
            'settings' => $credentials->publicState(), 'usage' => $credentials->usageSummary(),
        ]]);
    }

    public function show(AiCredentialService $credentials)
    {
        $this->authorizeAdministrator(request(), ['admin.ai-config.view', 'ai.configuration.view']);

        return response()->json(['settings' => $credentials->publicState(), 'usage' => $credentials->usageSummary()]);
    }

    public function update(Request $request, AiCredentialService $credentials, AuditLogger $audit)
    {
        $this->authorizeAdministrator($request, ['admin.ai-config.manage', 'ai.configuration.manage']);
        abort_unless($credentials->databaseReady(), 503, 'AI settings database tables are not installed. Apply the pending IFC migrations, then reload this page.');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'tutorials_enabled' => ['required', 'boolean'],
            'model' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:60'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:64', 'max:1000'],
            'api_key' => ['nullable', 'string', 'min:20', 'max:500'],
            'confirm_credential_change' => ['nullable', 'accepted'],
        ]);
        $newKey = trim((string) ($data['api_key'] ?? ''));
        if ($newKey !== '' && ! ($data['confirm_credential_change'] ?? false)) {
            throw ValidationException::withMessages(['confirm_credential_change' => 'Confirm the credential replacement before saving.']);
        }
        if ($data['enabled'] && $newKey === '' && ! $credentials->credential()) {
            throw ValidationException::withMessages(['enabled' => 'Configure an administrator-managed or environment-managed API key before enabling AI.']);
        }

        $configuration = AiConfiguration::query()->firstOrNew(['id' => 1]);
        $before = $this->safeSnapshot($configuration, $credentials);
        $configuration->provider = 'openai';
        if ($configuration->model && $configuration->model !== $data['model']) {
            $configuration->last_test_status = 'not_tested';
            $configuration->last_tested_at = null;
        }
        $configuration->model = $data['model'];
        $configuration->timeout_seconds = (int) ($data['timeout_seconds'] ?? $configuration->timeout_seconds ?? config('ifc_ai.request_timeout', 20));
        $configuration->max_output_tokens = (int) ($data['max_output_tokens'] ?? $configuration->max_output_tokens ?? config('ifc_ai.max_output_tokens', 500));
        $configuration->enabled = (bool) $data['enabled'];
        $configuration->tutorials_enabled = (bool) $data['tutorials_enabled'];
        $configuration->updated_by = $request->user()->id;
        if ($newKey !== '') {
            $configuration->api_key_ciphertext = $newKey;
            $configuration->last_test_status = 'not_tested';
            $configuration->last_tested_at = null;
            $configuration->last_successful_test_at = null;
        }
        $configuration->save();

        $audit->record('ai.configuration.updated', $configuration, [
            'before' => $before,
            'after' => $this->safeSnapshot($configuration, $credentials),
            'credential_replaced' => $newKey !== '',
        ]);

        return response()->json(['message' => 'AI configuration saved.', 'settings' => $credentials->publicState()]);
    }

    public function removeCredential(Request $request, AiCredentialService $credentials, AuditLogger $audit)
    {
        $this->authorizeAdministrator($request, ['admin.ai-config.manage', 'ai.configuration.manage']);
        abort_unless($credentials->databaseReady(), 503, 'AI settings database tables are not installed. Apply the pending IFC migrations, then reload this page.');

        $request->validate(['confirmation' => ['required', 'in:REMOVE']]);
        $configuration = AiConfiguration::query()->firstOrNew(['id' => 1]);
        $before = $this->safeSnapshot($configuration, $credentials);
        $configuration->api_key_ciphertext = null;
        $configuration->last_test_status = 'not_tested';
        $configuration->last_tested_at = null;
        $configuration->last_successful_test_at = null;
        $configuration->updated_by = $request->user()->id;
        $configuration->save();
        $audit->record('ai.configuration.credential_removed', $configuration, ['before' => $before, 'credential_removed' => true]);

        return response()->json(['message' => 'The administrator-managed credential reference was removed.', 'settings' => $credentials->publicState()]);
    }

    public function testConnection(Request $request, AiCredentialService $credentials, AiServiceClient $client, AuditLogger $audit)
    {
        $this->authorizeAdministrator($request, ['admin.ai-config.test', 'ai.configuration.test']);
        abort_unless($credentials->databaseReady(), 503, 'AI settings database tables are not installed. Apply the pending IFC migrations, then reload this page.');

        abort_unless($credentials->enabled(), 409, 'Enable the assistant before testing the OpenAI connection.');
        abort_unless($credentials->credential(), 409, 'Configure an API key before testing the OpenAI connection.');
        $configuration = AiConfiguration::query()->firstOrNew(['id' => 1]);
        $started = microtime(true);
        try {
            $result = $client->testOpenAi($credentials->model());
            $status = 'connected';
            $category = null;
            $configuration->last_successful_test_at = now();
        } catch (Throwable $exception) {
            $status = 'error';
            $category = $exception instanceof AiConnectionFailure ? $exception->category : $this->errorCategory($exception->getCode());
            $result = ['status' => $status, 'category' => $category, 'message' => $this->errorMessage($category)];
        }
        $configuration->last_test_status = $status === 'connected' ? 'connected' : $category;
        $configuration->last_tested_at = now();
        $configuration->updated_by = $request->user()->id;
        $configuration->save();
        $credentials->recordUsage($request->user(), $credentials->model(), $status === 'connected' ? 'success' : 'error', $category, isset($result['input_tokens']) ? (int) $result['input_tokens'] : null, isset($result['output_tokens']) ? (int) $result['output_tokens'] : null, (int) round((microtime(true) - $started) * 1000), ['request_type' => 'connection_test', 'module' => 'admin_ai_configuration', 'department' => 'Administrator', 'correlation_id' => $result['correlation_id'] ?? null, 'cached_input_tokens' => $result['cached_input_tokens'] ?? null]);
        $audit->record('ai.configuration.connection_tested', $configuration, ['status' => $status, 'category' => $category, 'model' => $credentials->model()]);

        return response()->json(['result' => $result, 'settings' => $credentials->publicState(), 'usage' => $credentials->usageSummary()]);
    }

    /** Signed machine-only retrieval; credential is never exposed to a browser user. */
    public function internalCredential(AiCredentialService $credentials)
    {
        if (! $credentials->enabled()) {
            return response()->json(['enabled' => false], 409)->header('Cache-Control', 'no-store');
        }
        $apiKey = $credentials->credential();
        abort_if(! $apiKey, 503, 'OpenAI credential is not configured.');

        return response()->json([
            'enabled' => true,
            'tutorials_enabled' => $credentials->tutorialsEnabled(),
            'provider' => 'openai',
            'model' => $credentials->model(),
            'api_key' => $apiKey,
            'timeout_seconds' => (int) ($credentials->publicState()['timeout_seconds'] ?? config('ifc_ai.request_timeout', 20)),
            'max_output_tokens' => (int) ($credentials->publicState()['max_output_tokens'] ?? config('ifc_ai.max_output_tokens', 500)),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    private function safeSnapshot(AiConfiguration $configuration, AiCredentialService $credentials): array
    {
        return [
            'enabled' => (bool) $configuration->enabled,
            'tutorials_enabled' => (bool) $configuration->tutorials_enabled,
            'provider' => 'openai', 'model' => $configuration->model ?: config('ifc_ai.model'),
            'credential_source' => $credentials->publicState()['credential_source'],
        ];
    }

    private function errorCategory(int $status): string
    {
        return match ($status) {
            401 => 'invalid_api_key', 403 => 'model_access_denied', 404 => 'model_not_found',
            402 => 'insufficient_quota', 408, 504 => 'connection_timeout', 429 => 'rate_limited',
            502 => 'invalid_provider_response', 503 => 'provider_unavailable', default => 'invalid_provider_response',
        };
    }

    private function errorMessage(string $category): string
    {
        return match ($category) {
            'configuration_missing' => 'Configure AI_SERVICE_URL and AI_SERVICE_TOKEN in Laravel, match AI_SERVICE_TOKEN in FastAPI, set AI_LARAVEL_CREDENTIAL_URL to Laravel’s signed credential endpoint, then restart both services.',
            'invalid_api_key' => 'OpenAI rejected the saved API key. Replace it with a valid project key.',
            'model_not_found' => 'The configured model was not found. Check its exact model identifier.',
            'model_access_denied' => 'The configured OpenAI project cannot access this model.',
            'insufficient_quota' => 'The OpenAI project has insufficient API quota or billing is unavailable.',
            'rate_limited' => 'OpenAI rate limited the test. Wait briefly and retry.',
            'ssl_certificate_error' => 'TLS certificate verification failed. Check the PHP or Python CA bundle; verification remains enabled.',
            'connection_timeout' => 'The OpenAI or internal AI service connection timed out.',
            'dns_error' => 'DNS could not resolve the OpenAI or internal AI service host.',
            'provider_unavailable' => 'The OpenAI or internal AI service is unavailable. Check service health and retry.',
            default => 'The AI provider returned an invalid response. Check the model and service configuration.',
        };
    }

    private function authorizeAdministrator(Request $request, array $permissions): void
    {
        $user = $request->user();
        abort_unless($user?->hasRole('Administrator') && collect($permissions)->contains(fn (string $permission): bool => $user->can($permission)), 403);
    }
}
