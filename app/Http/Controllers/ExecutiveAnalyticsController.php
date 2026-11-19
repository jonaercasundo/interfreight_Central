<?php

namespace App\Http\Controllers;

use App\Services\AiServiceClient;
use App\Services\AuditLogger;
use App\Services\ExecutiveAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ExecutiveAnalyticsController extends Controller
{
    public function page(Request $request)
    {
        abort_unless($request->user()->hasRole('Executive') && ! $request->user()->hasRole('Administrator'), 403);

        return view('app', ['page' => 'executive']);
    }

    public function dashboard(Request $request, ExecutiveAnalyticsService $analytics)
    {
        abort_unless($request->user()->hasRole('Executive') && ! $request->user()->hasRole('Administrator'), 403);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $data['month'] ?? now()->format('Y-m');

        return response()->json($analytics->dashboard($month));
    }

    public function forecast(Request $request, AiServiceClient $ai, AuditLogger $audit)
    {
        abort_unless($request->user()->hasRole('Executive') && ! $request->user()->hasRole('Administrator') && $request->user()->can('ai.forecasts.generate'), 403);
        $data = $request->validate(['horizon_months' => ['required', 'integer', 'between:1,6']]);
        $lastMonth = now()->startOfMonth()->subMonth();
        $firstMonth = $lastMonth->copy()->subMonths(35);
        $actuals = DB::table('shipments')->whereNull('archived_at')->whereNotIn('master_status', ['cancelled'])
            ->whereBetween('planned_departure', [$firstMonth->toDateString(), $lastMonth->endOfMonth()->toDateString()])
            ->selectRaw('SUBSTRING(planned_departure, 1, 7) as month')->selectRaw('COUNT(DISTINCT id) as total')->groupByRaw('SUBSTRING(planned_departure, 1, 7)')->pluck('total', 'month');
        $history = [];
        for ($month = $firstMonth->copy(); $month <= $lastMonth; $month->addMonth()) {
            $history[] = ['month' => $month->format('Y-m-01'), 'actual_shipments' => (int) ($actuals[$month->format('Y-m')] ?? 0)];
        }

        try {
            $result = $ai->forecastShipments(['history' => $history, 'horizon_months' => (int) $data['horizon_months']]);
        } catch (Throwable $exception) {
            $correlation = (string) Str::uuid();
            Log::warning('Executive shipment forecast could not be completed.', ['exception' => get_class($exception), 'correlation_id' => $correlation]);

            return response()->json(['detail' => 'The forecasting service is unavailable. No forecast was generated.', 'correlation_id' => $correlation], 503)->header('X-Correlation-ID', $correlation);
        }

        $requestId = (string) Str::uuid();
        DB::table('ai_prediction_runs')->insert([
            'request_id' => $requestId, 'service_correlation_id' => $result['correlation_id'], 'target' => $result['target'], 'model_version' => $result['model_version'],
            'training_cutoff' => $result['training_cutoff'], 'input_start' => $result['input_start'], 'input_end' => $result['input_end'],
            'horizon_months' => $result['horizon_months'], 'status' => $result['status'],
            'source_aggregate' => json_encode(['kind' => 'monthly_distinct_master_shipments_by_planned_departure', 'history' => $history], JSON_THROW_ON_ERROR),
            'forecasts' => json_encode($result['forecasts'], JSON_THROW_ON_ERROR),
            'evaluation' => isset($result['evaluation']) ? json_encode($result['evaluation'], JSON_THROW_ON_ERROR) : null,
            'limitation' => $result['limitation'] ?? null, 'generated_by' => $request->user()->id,
            'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $audit->record('ai.forecast.shipments.generated', null, ['request_id' => $requestId, 'model_version' => $result['model_version'], 'status' => $result['status'], 'horizon_months' => $result['horizon_months']]);

        return response()->json(['run_id' => $requestId, ...$result])->header('X-Correlation-ID', $result['correlation_id']);
    }
}
