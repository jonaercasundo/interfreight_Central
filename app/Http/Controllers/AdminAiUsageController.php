<?php

namespace App\Http\Controllers;

use App\Models\AiExchangeRate;
use App\Models\AiModelPrice;
use App\Models\AiMonthlyBudget;
use App\Models\AiUsageEvent;
use App\Services\AiCredentialService;
use App\Services\AuditLogger;
use App\Services\LiveFxRateProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminAiUsageController extends Controller
{
    public function page(Request $request)
    {
        $this->authorizeAdmin($request, 'admin.ai-usage.view');

        return view('app', ['page' => 'ai-usage']);
    }

    public function summary(Request $request): array
    {
        $this->authorizeAdmin($request, 'admin.ai-usage.view');
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        $query = AiUsageEvent::query()->whereBetween('created_at', [$from, now()]);
        $stats = (clone $query)->selectRaw('COUNT(*) as requests, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as successful, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed, SUM(input_tokens) as input_tokens, SUM(output_tokens) as output_tokens, SUM(estimated_cost_php) as known_cost_php, SUM(CASE WHEN estimated_cost_php IS NOT NULL THEN 1 ELSE 0 END) as costed_requests', ['success', 'error'])->first();
        $budget = AiMonthlyBudget::query()->where('year', now()->year)->where('month', now()->month)->first();
        $spend = $stats->known_cost_php === null ? null : (float) $stats->known_cost_php;
        $alerts = [];
        if ($budget && $spend !== null && (float) $budget->budget_php > 0) {
            foreach ([(int) $budget->warning_percent, (int) $budget->critical_percent, 100] as $threshold) {
                if ($spend >= (float) $budget->budget_php * $threshold / 100) {
                    DB::table('ai_budget_alerts')->insertOrIgnore(['year' => now()->year, 'month' => now()->month, 'threshold_percent' => $threshold, 'spend_php' => number_format($spend, 2, '.', ''), 'created_at' => now()]);
                }
            }
            $alerts = DB::table('ai_budget_alerts')->where('year', now()->year)->where('month', now()->month)->orderBy('threshold_percent')->get()->toArray();
        }
        $latestPrice = AiModelPrice::query()->where('model', app(AiCredentialService::class)->model())->where('provider', 'openai')->where('effective_at', '<=', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('effective_at')->first();
        $fx = AiExchangeRate::query()->where('base_currency', 'USD')->where('target_currency', 'PHP')->where('effective_at', '<=', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('effective_at')->first();
        $departments = (clone $query)->selectRaw("COALESCE(department, 'Unassigned') as department, COUNT(*) as requests, SUM(estimated_cost_php) as cost_php")
            ->groupBy('department')->orderByDesc('requests')->get()->map(fn ($row) => ['department' => $row->department, 'requests' => (int) $row->requests, 'cost_php' => $row->cost_php])->values();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => now()->toDateString()],
            'requests' => (int) ($stats->requests ?? 0), 'successful' => (int) ($stats->successful ?? 0), 'failed' => (int) ($stats->failed ?? 0),
            'input_tokens' => (int) ($stats->input_tokens ?? 0), 'output_tokens' => (int) ($stats->output_tokens ?? 0),
            'estimated_cost_php' => $spend, 'costed_requests' => (int) ($stats->costed_requests ?? 0),
            'cost_coverage' => (int) ($stats->requests ?? 0) === 0 ? 'no_usage' : ($spend === null ? 'unavailable' : ((int) $stats->costed_requests < (int) $stats->requests ? 'partial' : 'complete')),
            'current_model' => app(AiCredentialService::class)->model(),
            'pricing_available' => (bool) $latestPrice, 'pricing_verified_at' => $latestPrice?->verified_at?->toIso8601String(),
            'fx_available' => (bool) $fx, 'usd_php_rate' => $fx?->rate, 'fx_source' => $fx?->source, 'fx_verified_at' => $fx?->verified_at?->toIso8601String(),
            'budget_php' => $budget?->budget_php, 'budget_warning_percent' => $budget?->warning_percent, 'budget_critical_percent' => $budget?->critical_percent,
            'budget_alerts' => $alerts,
            'departments' => $departments,
            'modules' => (clone $query)->selectRaw("COALESCE(module, 'Unspecified') as module, COUNT(*) as requests, SUM(estimated_cost_php) as cost_php")
                ->groupBy('module')->orderByDesc('requests')->get()->map(fn ($row) => ['module' => $row->module, 'requests' => (int) $row->requests, 'cost_php' => $row->cost_php])->values(),
            'pricing_history' => AiModelPrice::query()->where('provider', 'openai')->latest('effective_at')->limit(8)->get(['model', 'input_usd_per_million', 'cached_input_usd_per_million', 'output_usd_per_million', 'source_url', 'verified_at', 'effective_at']),
            'fx_history' => AiExchangeRate::query()->where('base_currency', 'USD')->where('target_currency', 'PHP')->latest('effective_at')->limit(8)->get(['rate', 'source', 'source_url', 'override_reason', 'verified_at', 'effective_at']),
            'daily' => (clone $query)->selectRaw('DATE(created_at) as day, COUNT(*) as requests, SUM(estimated_cost_php) as cost_php')->groupBy('day')->orderBy('day')->get()->map(fn ($row) => ['day' => $row->day, 'requests' => (int) $row->requests, 'cost_php' => $row->cost_php])->values(),
        ];
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request, 'admin.ai-usage.view');
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'status' => ['nullable', Rule::in(['success', 'error'])], 'model' => ['nullable', 'string', 'max:100'], 'department' => ['nullable', 'string', 'max:40'], 'module' => ['nullable', 'string', 'max:60'], 'request_type' => ['nullable', 'string', 'max:40'], 'search' => ['nullable', 'string', 'max:120'], 'per_page' => ['nullable', 'integer', 'min:10', 'max:100']]);
        $query = AiUsageEvent::query()->with('user:id,name,email');
        $this->applyFilters($query, $filters);

        return response()->json(['data' => $query->latest('created_at')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString(), 'filters' => $filters]);
    }

    public function export(Request $request)
    {
        $this->authorizeAdmin($request, 'admin.ai-usage.export');
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'status' => ['nullable', Rule::in(['success', 'error'])], 'model' => ['nullable', 'string', 'max:100'], 'department' => ['nullable', 'string', 'max:40'], 'module' => ['nullable', 'string', 'max:60'], 'request_type' => ['nullable', 'string', 'max:40']]);
        $query = AiUsageEvent::query()->with('user:id,name,email');
        $this->applyFilters($query, $filters);
        $rows = $query->latest('created_at')->limit(10000)->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Timestamp', 'User', 'Email', 'Department', 'Module', 'Request type', 'Provider', 'Model', 'Status', 'Input tokens', 'Output tokens', 'Estimated cost (PHP)', 'Cost status', 'USD/PHP rate', 'Latency ms', 'Correlation ID']);
            foreach ($rows as $row) {
                fputcsv($out, [$row->created_at?->toIso8601String(), $this->csvCell($row->user?->name), $this->csvCell($row->user?->email), $this->csvCell($row->department), $this->csvCell($row->module), $this->csvCell($row->request_type), $this->csvCell($row->provider), $this->csvCell($row->model), $this->csvCell($row->status), $row->input_tokens, $row->output_tokens, $row->estimated_cost_php, $this->csvCell($row->cost_status), $row->usd_php_rate, $row->latency_ms, $this->csvCell($row->correlation_id)]);
            }
            fclose($out);
        }, 'ifc-ai-usage-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storePrice(Request $request, AuditLogger $audit)
    {
        $this->authorizeAdmin($request, 'admin.ai-pricing.manage');
        $data = $request->validate(['model' => ['required', 'string', 'max:100'], 'input_usd_per_million' => ['required', 'numeric', 'min:0'], 'cached_input_usd_per_million' => ['nullable', 'numeric', 'min:0'], 'output_usd_per_million' => ['required', 'numeric', 'min:0'], 'source_url' => ['required', 'url', 'max:500'], 'verified_at' => ['required', 'date'], 'effective_at' => ['required', 'date'], 'expires_at' => ['nullable', 'date', 'after:effective_at']]);
        $data['created_by'] = $request->user()->id;
        $data['provider'] = 'openai';
        $price = AiModelPrice::query()->create($data);
        $audit->record('ai.usage.pricing_created', $price, ['model' => $price->model, 'source_url' => $price->source_url, 'effective_at' => $price->effective_at->toIso8601String()]);

        return response()->json(['message' => 'Verified model price recorded.', 'price' => $price], 201);
    }

    public function storeRate(Request $request, AuditLogger $audit)
    {
        $this->authorizeAdmin($request, 'admin.ai-fx.manage');
        $data = $request->validate(['rate' => ['required', 'numeric', 'gt:0', 'max:100000'], 'source' => ['required', 'string', 'max:120'], 'source_url' => ['nullable', 'url', 'max:500'], 'override_reason' => ['required', 'string', 'min:8', 'max:1000'], 'verified_at' => ['required', 'date'], 'effective_at' => ['required', 'date'], 'expires_at' => ['nullable', 'date', 'after:effective_at']]);
        $data += ['base_currency' => 'USD', 'target_currency' => 'PHP', 'entered_by' => $request->user()->id];
        $rate = AiExchangeRate::query()->create($data);
        $audit->record('ai.usage.fx_rate_created', $rate, ['source' => $rate->source, 'source_url' => $rate->source_url, 'rate' => $rate->rate, 'effective_at' => $rate->effective_at->toIso8601String(), 'override_reason' => $rate->override_reason]);

        return response()->json(['message' => 'Verified USD/PHP rate recorded.', 'rate' => $rate], 201);
    }

    public function latestRate(Request $request, LiveFxRateProvider $provider)
    {
        $this->authorizeAdmin($request, 'admin.ai-fx.manage');

        try {
            return response()->json(['rate' => $provider->usdToPhp(), 'kind' => 'daily_reference']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function saveBudget(Request $request, AuditLogger $audit)
    {
        $this->authorizeAdmin($request, 'admin.ai-budget.manage');
        $data = $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100'], 'month' => ['required', 'integer', 'min:1', 'max:12'], 'budget_php' => ['required', 'numeric', 'gt:0', 'max:999999999999'], 'warning_percent' => ['required', 'integer', 'min:1', 'max:99'], 'critical_percent' => ['required', 'integer', 'min:2', 'max:100', 'gt:warning_percent']]);
        $data['updated_by'] = $request->user()->id;
        $budget = AiMonthlyBudget::query()->updateOrCreate(['year' => $data['year'], 'month' => $data['month']], $data);
        $audit->record('ai.usage.budget_updated', $budget, ['year' => $budget->year, 'month' => $budget->month, 'budget_php' => $budget->budget_php, 'warning_percent' => $budget->warning_percent, 'critical_percent' => $budget->critical_percent]);

        return response()->json(['message' => 'Monthly AI budget saved.', 'budget' => $budget]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        foreach (['status', 'model', 'department', 'module', 'request_type'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (! empty($filters['search'])) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('email', 'like', '%'.$filters['search'].'%'));
        }
    }

    private function authorizeAdmin(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasRole('Administrator') && $request->user()->can($permission), 403);
    }

    private function csvCell(?string $value): ?string
    {
        return $value !== null && preg_match('/^[\s]*[=+@-]/', $value) ? "'".$value : $value;
    }
}
