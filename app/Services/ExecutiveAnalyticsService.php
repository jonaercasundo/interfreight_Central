<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only, source-backed metrics for the Executive Command Center. */
class ExecutiveAnalyticsService
{
    public function dashboard(string $month): array
    {
        try {
            $period = CarbonImmutable::createFromFormat('!Y-m', $month);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['month' => 'Choose a valid reporting month.']);
        }
        if (! $period || $period->format('Y-m') !== $month) {
            throw ValidationException::withMessages(['month' => 'Choose a valid reporting month.']);
        }

        $start = $period->startOfMonth();
        $end = $period->endOfMonth();
        $previousStart = $start->subMonth();
        $previousEnd = $previousStart->endOfMonth();
        $forecast = DB::table('shipment_forecasts')->where('status', 'active')->whereDate('forecast_month', $start->toDateString())->sum('expected_shipment_count');
        $actual = DB::table('shipments')->whereNull('archived_at')->whereNotIn('master_status', ['cancelled'])->whereBetween('created_at', [$start, $end])->distinct('id')->count('id');
        $operationallyCompleted = DB::table('shipment_completion_milestones')->where('dimension', 'operations')->where('status', 'completed')->whereBetween('verified_at', [$start, $end])->distinct('shipment_id')->count('shipment_id');
        $financiallyClosed = DB::table('financial_closing_requests')->where('status', 'closed')->whereBetween('closed_at', [$start, $end])->distinct('shipment_id')->count('shipment_id');

        $previousForecast = DB::table('shipment_forecasts')->where('status', 'active')->whereDate('forecast_month', $previousStart->toDateString())->sum('expected_shipment_count');
        $previousActual = DB::table('shipments')->whereNull('archived_at')->whereNotIn('master_status', ['cancelled'])->whereBetween('planned_departure', [$previousStart->toDateString(), $previousEnd->toDateString()])->distinct('id')->count('id');
        $forecastAccuracy = $previousForecast > 0
            ? round(max(0, 1 - (abs($previousForecast - $previousActual) / $previousForecast)) * 100, 1)
            : null;

        $latestBudgets = DB::table('monthly_budget_plans')->select('budget_month', 'department', 'currency')->selectRaw('MAX(version) as version')->groupBy('budget_month', 'department', 'currency');
        $budgets = DB::table('monthly_budget_plans as plans')->joinSub($latestBudgets, 'latest', fn ($join) => $join->on('plans.budget_month', '=', 'latest.budget_month')->on('plans.department', '=', 'latest.department')->on('plans.currency', '=', 'latest.currency')->on('plans.version', '=', 'latest.version'))
            ->where('plans.status', 'approved')->whereDate('plans.budget_month', $start->toDateString())
            ->select('plans.currency')->selectRaw('SUM(plans.approved_total) as amount')->groupBy('plans.currency')->orderBy('plans.currency')->get();
        $expenses = DB::table('actual_expenses')->where('status', 'verified')->whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
            ->select('currency')->selectRaw('SUM(amount) as amount')->groupBy('currency')->orderBy('currency')->get();
        $budgetUtilization = $budgets->map(function ($budget) use ($expenses): array {
            $expense = $expenses->firstWhere('currency', $budget->currency)?->amount ?? '0.00';
            $approved = (float) $budget->amount;

            return ['currency' => $budget->currency, 'approved' => (string) $budget->amount, 'verified_expenses' => (string) $expense, 'utilization_percent' => $approved > 0 ? round(((float) $expense / $approved) * 100, 1) : null];
        })->values();
        $invoiced = DB::table('billing_invoices')->where('workflow_status', 'issued')->whereBetween('issued_at', [$start, $end])
            ->select('currency')->selectRaw('SUM(total) as amount')->groupBy('currency')->orderBy('currency')->get();
        $collected = DB::table('collection_payment_receipts')->where('status', 'reconciled')->whereBetween('reconciled_at', [$start, $end])
            ->select('currency')->selectRaw('SUM(amount) as amount')->groupBy('currency')->orderBy('currency')->get();
        $nonrecoverable = DB::table('actual_expenses')->where('status', 'verified')->where('recoverability', 'company_nonrecoverable')->whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
            ->select('currency')->selectRaw('SUM(amount) as amount')->groupBy('currency')->orderBy('currency')->get();

        $receivables = $this->receivables();
        $departmentActivity = DB::table('shipment_department_assignments')->select('department')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_assignments")
            ->selectRaw("SUM(CASE WHEN status IN ('pending', 'assigned', 'in_progress') THEN 1 ELSE 0 END) as open_assignments")
            ->whereBetween('updated_at', [$start, $end])->groupBy('department')->orderBy('department')->get();
        $monthly = $this->monthlySeries($start->subMonths(11), $end);
        $exceptions = DB::table('billing_exceptions')->where('status', 'open')->count()
            + DB::table('expense_exceptions')->where('status', 'open')->count();
        $previousMonthActual = DB::table('shipments')->whereNull('archived_at')->whereNotIn('master_status', ['cancelled'])->whereBetween('created_at', [$previousStart, $previousEnd])->distinct('id')->count('id');

        return [
            'period' => ['month' => $start->format('Y-m'), 'label' => $start->format('F Y'), 'timezone' => config('app.timezone'), 'currency_scope' => 'Amounts remain grouped by source currency; no FX conversion is configured.', 'previous_month' => $previousStart->format('Y-m')],
            'metrics' => [
                'forecasted_shipments' => (int) $forecast,
                'actual_master_shipments' => $actual,
                'operationally_completed' => $operationallyCompleted,
                'financially_closed' => $financiallyClosed,
                'forecast_accuracy_previous_month_percent' => $forecastAccuracy,
                'approved_budget' => $this->currencyRows($budgets),
                'actual_verified_expenses' => $this->currencyRows($expenses),
                'budget_utilization' => $budgetUtilization->all(),
                'issued_invoices' => $this->currencyRows($invoiced),
                'verified_collections' => $this->currencyRows($collected),
                'company_nonrecoverable_expenses' => $this->currencyRows($nonrecoverable),
                'authorized_commercial_revenue' => ['status' => 'not_available', 'reason' => 'No enterprise-wide commercial revenue recognition source is configured.'],
                'recognized_revenue' => ['status' => 'not_available', 'reason' => 'Phase 10 has no approved revenue-recognition policy or event.'],
                'gross_profit' => ['status' => 'not_available', 'reason' => 'Cannot be derived without recognized revenue and a complete cost basis.'],
                'accounts_receivable' => $receivables['outstanding'],
                'overdue_receivables' => $receivables['overdue'],
                'open_financial_exceptions' => $exceptions,
            ],
            'series' => $monthly,
            'comparison' => ['previous_month' => $previousStart->format('Y-m'), 'forecast_units' => (int) $previousForecast, 'actual_master_shipments_created' => $previousMonthActual],
            'department_activity' => $departmentActivity->map(fn ($row) => ['department' => $row->department, 'completed_assignments' => (int) $row->completed_assignments, 'open_assignments' => (int) $row->open_assignments, 'definition' => 'Department assignments updated during the selected month; these are work items, not distinct master shipments.'])->values(),
            'freshness' => ['as_of' => now()->toIso8601String(), 'source' => 'Live read-only source queries'],
            'definitions' => [
                'actual_master_shipments' => 'Distinct non-archived master shipment records created in the selected month; canceled records excluded.',
                'forecasted_shipments' => 'Sum of active Sales forecast units for the selected month.',
                'forecast_accuracy_previous_month_percent' => '100 × (1 − absolute forecast error ÷ forecast), using active forecast units and distinct master shipments with planned departure in the prior month. Not available when no forecast exists.',
                'operationally_completed' => 'Distinct shipments whose Operations completion milestone was verified in the selected month.',
                'financially_closed' => 'Distinct shipments with a closed financial closing request in the selected month.',
                'verified_expenses' => 'Actual expense rows with status verified, grouped by original currency; no exchange rate is applied.',
                'budget_utilization' => 'Latest approved budget version per month, department and currency compared with verified expenses incurred in the month, grouped by currency.',
                'receivables' => 'Issued invoice total less reconciled, non-reversed allocated receipts; grouped by invoice currency.',
            ],
        ];
    }

    private function monthlySeries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfMonth();
        $end = $to->endOfMonth();
        $forecastByMonth = DB::table('shipment_forecasts')->where('status', 'active')
            ->whereBetween('forecast_month', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('SUBSTRING(forecast_month, 1, 7) as month')->selectRaw('SUM(expected_shipment_count) as total')
            ->groupByRaw('SUBSTRING(forecast_month, 1, 7)')->pluck('total', 'month');
        $actualByMonth = DB::table('shipments')->whereNull('archived_at')->whereNotIn('master_status', ['cancelled'])
            ->whereBetween('planned_departure', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('SUBSTRING(planned_departure, 1, 7) as month')->selectRaw('COUNT(DISTINCT id) as total')
            ->groupByRaw('SUBSTRING(planned_departure, 1, 7)')->pluck('total', 'month');
        $rows = [];
        for ($month = $start; $month <= $end; $month = $month->addMonth()) {
            $monthStart = $month->startOfMonth();
            $rows[] = [
                'month' => $monthStart->format('Y-m'),
                'forecast' => (int) ($forecastByMonth[$monthStart->format('Y-m')] ?? 0),
                'actual' => (int) ($actualByMonth[$monthStart->format('Y-m')] ?? 0),
            ];
        }

        return $rows;
    }

    private function receivables(): array
    {
        $rows = DB::table('billing_invoices as i')
            ->leftJoinSub(DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->leftJoin('collection_payment_reversals as x', 'x.receipt_id', '=', 'r.id')->where('r.status', 'reconciled')->whereNull('x.id')->select('a.invoice_id')->selectRaw('SUM(a.amount) as paid')->groupBy('a.invoice_id'), 'paid', fn ($join) => $join->on('paid.invoice_id', '=', 'i.id'))
            ->where('i.workflow_status', 'issued')->select('i.currency')->selectRaw('SUM(CASE WHEN COALESCE(i.total, 0) > COALESCE(paid.paid, 0) THEN i.total - COALESCE(paid.paid, 0) ELSE 0 END) as amount')
            ->selectRaw('SUM(CASE WHEN i.due_date < ? AND COALESCE(i.total, 0) > COALESCE(paid.paid, 0) THEN i.total - COALESCE(paid.paid, 0) ELSE 0 END) as overdue', [now()->toDateString()])
            ->groupBy('i.currency')->orderBy('i.currency')->get();

        return ['outstanding' => $rows->map(fn ($row) => ['currency' => $row->currency, 'amount' => (string) $row->amount])->values(), 'overdue' => $rows->map(fn ($row) => ['currency' => $row->currency, 'amount' => (string) $row->overdue])->values()];
    }

    private function currencyRows($rows): array
    {
        return $rows->map(fn ($row) => ['currency' => $row->currency, 'amount' => (string) $row->amount])->values()->all();
    }
}
