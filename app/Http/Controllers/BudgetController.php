<?php

namespace App\Http\Controllers;

use App\Models\MonthlyBudgetPlan;
use App\Models\ShipmentForecast;
use App\Services\AuditLogger;
use App\Services\CommercialCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BudgetController
{
    private const CATEGORIES = CommercialCalculator::COST_CATEGORIES;

    public function page(Request $request)
    {
        $page = $request->route('page', 'accounting-dashboard');
        $this->assertFinancialScope($request);

        $permission = match ($page) {
            'accounting-dashboard' => 'accounting.dashboard.view',
            'overview', 'budget-plans' => 'budgets.view',
            'shipment-demand' => 'forecasts.accounting.view',
            'funding-requirements' => 'funding-requirements.view',
            'budget-allocations' => 'budgets.allocate',
            'cash-forecast' => 'cash-forecasts.view',
            'commitments-actuals' => 'commitments.view',
            'budget-variance' => 'budget-variances.view',
            'budget-history' => 'budgets.history.view',
            default => null,
        };
        abort_unless($permission && $request->user()->can($permission), 403);

        if ($page !== 'accounting-dashboard' && ! str_starts_with($page, 'accounting-')) {
            $page = 'accounting-'.$page;
        }

        return view('app', ['page' => $page]);
    }

    public function dashboard(Request $request)
    {
        abort_unless($request->user()->can('accounting.dashboard.view'), 403);
        $this->assertFinancialScope($request);
        $month = now()->startOfMonth()->toDateString();
        $nextMonth = now()->addMonth()->startOfMonth()->toDateString();
        $plans = DB::table('monthly_budget_plans')->whereIn('status', ['submitted', 'under_review', 'approved', 'closed'])->get();
        $amounts = fn (string $date, string $column) => $plans->where('budget_month', $date)->groupBy('currency')->map(fn ($rows) => (string) $rows->reduce(fn (BigDecimal $sum, $row) => $row->{$column} === null ? $sum : $sum->plus((string) $row->{$column}), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp))->all();
        $monthRequirements = $this->fundingRequirements($request, ['month' => now()->format('Y-m')]);
        $nextRequirements = $this->fundingRequirements($request, ['month' => now()->addMonth()->format('Y-m')]);
        $available = $this->availableAuthority($plans, $month);

        return response()->json([
            'period' => $month,
            'currency' => 'Amounts are reported separately by currency; no FX conversion is configured.',
            'demand' => $this->summarizeRequirements($monthRequirements),
            'next_month_demand' => $this->summarizeRequirements($nextRequirements),
            'requested_budget' => $amounts($month, 'requested_total'),
            'approved_budget' => $amounts($month, 'approved_total'),
            'pending_approvals' => $plans->whereIn('status', ['submitted', 'under_review'])->count(),
            'committed' => 'Not integrated', 'actual_expenses' => 'Not integrated', 'cash_position' => 'Not recorded',
            'forecast_shortfall' => $this->coverageShortfall($monthRequirements),
            'available_to_allocate' => $available,
            'requested_by_currency' => $amounts($month, 'requested_total'), 'approved_by_currency' => $amounts($month, 'approved_total'),
        ]);
    }

    public function requirements(Request $request)
    {
        abort_unless($request->user()->can('funding-requirements.view'), 403);
        $this->assertFinancialScope($request);
        $filters = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'client' => ['nullable', 'string', 'max:180'], 'mode' => ['nullable', 'string', 'max:16'], 'category' => ['nullable', 'string', 'max:48'], 'status' => ['nullable', Rule::in(['provisional', 'approved_estimate'])]]);

        return response()->json(['data' => $this->fundingRequirements($request, $filters)]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('budgets.view'), 403);
        $this->assertFinancialScope($request);
        $allocationTotals = DB::table('budget_allocations')->select('budget_plan_id', DB::raw('SUM(amount) as allocated_total'))->groupBy('budget_plan_id');
        $plans = DB::table('monthly_budget_plans as p')
            ->leftJoin('budget_lines as l', 'l.budget_plan_id', '=', 'p.id')
            ->leftJoinSub($allocationTotals, 'a', fn ($join) => $join->on('a.budget_plan_id', '=', 'p.id'))
            ->select('p.*', DB::raw('COUNT(DISTINCT l.id) as line_count'), DB::raw('COALESCE(a.allocated_total, 0) as allocated_total'))
            ->groupBy('p.id')->orderByDesc('p.budget_month')->orderByDesc('p.version')->paginate(30);
        $plans->through(function (object $plan): array {
            $approved = $plan->approved_total === null ? null : BigDecimal::of((string) $plan->approved_total);
            $requested = BigDecimal::of((string) $plan->requested_total);
            $allocated = BigDecimal::of((string) $plan->allocated_total);
            $requestVariance = $approved?->minus($requested);
            $allocationVariance = $approved?->minus($allocated);
            $requestPercent = $requested->isZero() ? null : $requestVariance->multipliedBy(100)->dividedBy($requested, 4, RoundingMode::HalfUp);

            return [
                ...((array) $plan),
                'approved_request_variance' => $requestVariance === null ? null : (string) $requestVariance->toScale(2, RoundingMode::HalfUp),
                'approval_variance_percent' => $requestPercent === null ? null : (string) $requestPercent,
                'approved_allocation_variance' => $allocationVariance === null ? null : (string) $allocationVariance->toScale(2, RoundingMode::HalfUp),
                'commitment_actual_variance' => 'Not integrated',
            ];
        });

        return response()->json($plans);
    }

    public function show(Request $request, int $plan)
    {
        abort_unless($request->user()->can('budgets.view'), 403);
        $this->assertFinancialScope($request);
        $row = DB::table('monthly_budget_plans')->where('id', $plan)->first();
        abort_if(! $row, 404);

        return response()->json(['data' => $row, 'lines' => DB::table('budget_lines')->where('budget_plan_id', $plan)->orderBy('category')->get(), 'approvals' => DB::table('budget_approval_steps')->where('budget_plan_id', $plan)->orderBy('acted_at')->get(), 'allocations' => DB::table('budget_allocations')->where('budget_plan_id', $plan)->orderByDesc('allocated_on')->get()]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('budgets.create'), 403);
        $this->assertAccounting($request);
        $data = $this->validatedPlan($request, true);
        $plan = DB::transaction(function () use ($data, $request, $audit) {
            $reference = 'BUD-'.str_replace('-', '', $data['budget_month']).'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $id = DB::table('monthly_budget_plans')->insertGetId(['reference' => $reference, 'budget_month' => $data['budget_month'].'-01', 'fiscal_year' => (int) substr($data['budget_month'], 0, 4), 'department' => 'Accounting', 'currency' => $data['currency'], 'owner_id' => $request->user()->id, 'version' => 1, 'status' => 'draft', 'explanation' => $data['explanation'] ?? null, 'requested_total' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $this->saveLines($id, $data['lines']);
            $this->syncRequestedTotal($id);
            DB::table('budget_audit_history')->insert(['budget_plan_id' => $id, 'actor_id' => $request->user()->id, 'action' => 'created', 'after_values' => json_encode(['reference' => $reference, 'budget_month' => $data['budget_month'], 'currency' => $data['currency'], 'lines' => $data['lines']]), 'created_at' => now()]);
            $audit->record('budgets.created', MonthlyBudgetPlan::find($id), ['reference' => $reference, 'month' => $data['budget_month'], 'currency' => $data['currency']]);

            return DB::table('monthly_budget_plans')->where('id', $id)->first();
        });

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, int $plan, AuditLogger $audit)
    {
        abort_unless($request->user()->can('budgets.update'), 403);
        $this->assertAccounting($request);
        $data = $this->validatedPlan($request, false);
        DB::transaction(function () use ($request, $plan, $data, $audit) {
            $row = DB::table('monthly_budget_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_if(! $row, 404);
            abort_unless($row->status === 'draft' || $row->status === 'revision_requested', 409, 'Submitted or approved budgets are locked. Create a revision instead.');
            $before = ['explanation' => $row->explanation, 'lines' => DB::table('budget_lines')->where('budget_plan_id', $plan)->get()->toArray()];
            DB::table('monthly_budget_plans')->where('id', $plan)->update(['explanation' => $data['explanation'] ?? $row->explanation, 'updated_at' => now()]);
            if (isset($data['lines'])) {
                DB::table('budget_lines')->where('budget_plan_id', $plan)->delete();
                $this->saveLines($plan, $data['lines']);
            }
            $this->syncRequestedTotal($plan);
            $after = ['explanation' => $data['explanation'] ?? $row->explanation, 'lines' => DB::table('budget_lines')->where('budget_plan_id', $plan)->get()->toArray()];
            DB::table('budget_audit_history')->insert(['budget_plan_id' => $plan, 'actor_id' => $request->user()->id, 'action' => 'updated', 'before_values' => json_encode($before), 'after_values' => json_encode($after), 'reason' => $data['reason'] ?? null, 'created_at' => now()]);
            $audit->record('budgets.updated', MonthlyBudgetPlan::find($plan), ['reference' => $row->reference, 'before' => ['status' => $row->status], 'after' => ['line_count' => count($data['lines'] ?? [])], 'reason' => $data['reason'] ?? null]);
        });

        return $this->show($request, $plan);
    }

    public function submit(Request $request, int $plan, AuditLogger $audit)
    {
        abort_unless($request->user()->can('budgets.submit'), 403);
        $this->assertAccounting($request);
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);

        return $this->transition($request, $plan, 'submitted', 'submitted', $data['remarks'] ?? null, $audit);
    }

    public function revise(Request $request, int $plan, AuditLogger $audit)
    {
        abort_unless($request->user()->can('budgets.revise'), 403);
        $this->assertAccounting($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $new = DB::transaction(function () use ($request, $plan, $data, $audit) {
            $previous = DB::table('monthly_budget_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_if(! $previous, 404);
            abort_unless(in_array($previous->status, ['approved', 'rejected', 'revision_requested'], true), 409, 'Only an approved or returned budget can be revised.');
            $version = (int) DB::table('monthly_budget_plans')->where('budget_month', $previous->budget_month)->where('department', $previous->department)->where('currency', $previous->currency)->max('version') + 1;
            $reference = $previous->reference.'-V'.$version;
            $id = DB::table('monthly_budget_plans')->insertGetId(['reference' => $reference, 'budget_month' => $previous->budget_month, 'fiscal_year' => $previous->fiscal_year, 'department' => $previous->department, 'currency' => $previous->currency, 'owner_id' => $request->user()->id, 'parent_budget_plan_id' => $plan, 'version' => $version, 'status' => 'draft', 'explanation' => $previous->explanation, 'requested_total' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('budget_lines')->where('budget_plan_id', $plan)->get() as $line) {
                DB::table('budget_lines')->insert(['budget_plan_id' => $id, 'category' => $line->category, 'description' => $line->description, 'requested_amount' => $line->approved_amount ?? $line->requested_amount, 'approved_amount' => null, 'adjustment_reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->syncRequestedTotal($id);
            if ($previous->status === 'approved') {
                DB::table('monthly_budget_plans')->where('id', $plan)->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            DB::table('budget_audit_history')->insert(['budget_plan_id' => $plan, 'actor_id' => $request->user()->id, 'action' => 'superseded', 'after_values' => json_encode(['superseded_by' => $reference]), 'reason' => $data['reason'], 'created_at' => now()]);
            DB::table('budget_audit_history')->insert(['budget_plan_id' => $id, 'actor_id' => $request->user()->id, 'action' => 'revision_created', 'before_values' => json_encode(['parent_reference' => $previous->reference]), 'after_values' => json_encode(['reference' => $reference, 'version' => $version]), 'reason' => $data['reason'], 'created_at' => now()]);
            $audit->record('budgets.revised', MonthlyBudgetPlan::find($id), ['reference' => $reference, 'parent_reference' => $previous->reference, 'version' => $version, 'reason' => $data['reason']]);

            return DB::table('monthly_budget_plans')->where('id', $id)->first();
        });

        return response()->json(['data' => $new], 201);
    }

    public function close(Request $request, int $plan, AuditLogger $audit)
    {
        abort_unless($request->user()->can('budgets.close'), 403);
        $this->assertAccounting($request);
        $data = $request->validate(['remarks' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->transition($request, $plan, 'closed', 'closed', $data['remarks'], $audit);
    }

    public function review(Request $request, int $plan, AuditLogger $audit)
    {
        abort_unless($request->user()->can('budgets.review') || $request->user()->can('budgets.approve'), 403);
        $this->assertFinancialScope($request);
        $data = $request->validate(['decision' => ['required', Rule::in(['under_review', 'approved', 'rejected', 'revision_requested'])], 'remarks' => ['required', 'string', 'min:3', 'max:2000']]);
        if ($data['decision'] === 'approved') {
            abort_unless($request->user()->can('budgets.approve') && $request->user()->hasAnyRole(config('ifc_budget.approval_roles', ['Finance'])), 403);
        }

        return $this->transition($request, $plan, $data['decision'], $data['decision'], $data['remarks'], $audit);
    }

    public function allocate(Request $request, int $plan, AuditLogger $audit)
    {
        abort_unless($request->user()->can('budgets.allocate'), 403);
        $this->assertAccounting($request);
        $data = $request->validate(['budget_line_id' => ['required', 'integer'], 'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:999999999999.99'], 'requirement_source' => ['required', 'string', 'max:80'], 'purpose' => ['required', 'string', 'min:3', 'max:1000']]);

        return response()->json(['data' => DB::transaction(function () use ($request, $plan, $data, $audit) {
            $budget = DB::table('monthly_budget_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_if(! $budget, 404);
            abort_unless($budget->status === 'approved', 409, 'Allocations require an approved budget.');
            $line = DB::table('budget_lines')->where('id', $data['budget_line_id'])->where('budget_plan_id', $plan)->lockForUpdate()->first();
            abort_if(! $line, 422, 'The budget line does not belong to this plan.');
            $requirement = collect($this->fundingRequirements($request, []))->firstWhere('source_reference', $data['requirement_source']);
            abort_if(! $requirement || $requirement['source_status'] !== 'approved_estimate', 422, 'Select a current approved estimate funding requirement.');
            abort_unless($requirement['currency'] === $budget->currency && $requirement['category'] === $line->category && $requirement['forecast_month'] === substr($budget->budget_month, 0, 7), 422, 'Requirement month, currency, or category must match the approved budget line.');
            $allocated = DB::table('budget_allocations')->where('budget_line_id', $line->id)->get(['amount'])->reduce(fn (BigDecimal $sum, object $row) => $sum->plus((string) $row->amount), BigDecimal::zero());
            abort_if($allocated->plus($data['amount'])->isGreaterThan($line->approved_amount), 422, 'Allocation exceeds approved available authority.');
            $sourceAllocated = DB::table('budget_allocations')->where('requirement_source', $data['requirement_source'])->get(['amount'])->reduce(fn (BigDecimal $sum, object $row) => $sum->plus((string) $row->amount), BigDecimal::zero());
            abort_if($sourceAllocated->plus($data['amount'])->isGreaterThan($requirement['estimated_amount']), 422, 'Allocation exceeds the remaining estimate funding requirement.');
            $reference = 'ALC-'.strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            $id = DB::table('budget_allocations')->insertGetId(['reference' => $reference, 'budget_plan_id' => $plan, 'budget_line_id' => $line->id, 'requirement_source' => $data['requirement_source'] ?? null, 'amount' => $data['amount'], 'currency' => $budget->currency, 'allocated_on' => now()->toDateString(), 'allocated_by' => $request->user()->id, 'purpose' => $data['purpose'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('budget_audit_history')->insert(['budget_plan_id' => $plan, 'actor_id' => $request->user()->id, 'action' => 'allocation_created', 'after_values' => json_encode(['reference' => $reference, 'line_id' => $line->id, 'amount' => $data['amount'], 'currency' => $budget->currency, 'purpose' => $data['purpose']]), 'reason' => $data['purpose'], 'created_at' => now()]);
            $audit->record('budgets.allocated', MonthlyBudgetPlan::find($plan), ['reference' => $reference, 'budget_reference' => $budget->reference, 'amount' => $data['amount'], 'currency' => $budget->currency]);

            return DB::table('budget_allocations')->where('id', $id)->first();
        })], 201);
    }

    public function history(Request $request, int $plan)
    {
        abort_unless($request->user()->can('budgets.history.view'), 403);
        $this->assertFinancialScope($request);

        return response()->json(['data' => DB::table('budget_audit_history')->where('budget_plan_id', $plan)->orderByDesc('created_at')->get()]);
    }

    private function fundingRequirements(Request $request, array $filters): array
    {
        $query = ShipmentForecast::query()->with(['inquiry.client:id,company_name', 'inquiry.costEstimates.lines'])
            ->where('status', 'active')->whereIn('confidence', ['tentative', 'probable', 'confirmed'])
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->whereDate('forecast_month', $month.'-01'))
            ->when($filters['mode'] ?? null, fn ($q, $mode) => $q->whereHas('inquiry', fn ($i) => $i->where('transport_mode', $mode)))
            ->when($filters['client'] ?? null, fn ($q, $client) => $q->whereHas('inquiry.client', fn ($c) => $c->where('company_name', 'like', '%'.$client.'%')))
            ->orderBy('forecast_month');
        $forecasts = $query->get();
        $items = [];
        foreach ($forecasts as $forecast) {
            $estimates = $forecast->inquiry->costEstimates->filter(fn ($e) => $e->forecast_id === null || $e->forecast_id === $forecast->id);
            $approvedEstimate = $estimates->where('status', 'approved')->sortByDesc('version')->first();
            $provisionalEstimate = $estimates->where('status', '!=', 'approved')->sortByDesc('version')->first();
            if ($approvedEstimate) {
                foreach ($approvedEstimate->lines->where('funding_required', true) as $line) {
                    if (($filters['category'] ?? null) && $line->category !== $filters['category']) {
                        continue;
                    }
                    $items[] = ['source_type' => 'approved_cost_estimate', 'source_reference' => $approvedEstimate->reference.'-v'.$approvedEstimate->version.'-L'.$line->id, 'client' => $forecast->inquiry->client->company_name, 'inquiry_reference' => $forecast->inquiry->reference, 'transport_mode' => $forecast->inquiry->transport_mode, 'forecast_month' => $forecast->forecast_month->format('Y-m'), 'expected_shipments' => $forecast->expected_shipment_count, 'category' => $line->category, 'estimated_amount' => (string) BigDecimal::of((string) $line->estimate_currency_total)->multipliedBy($forecast->expected_shipment_count)->toScale(2, RoundingMode::HalfUp), 'currency' => $approvedEstimate->currency, 'due_date' => null, 'confidence' => $forecast->confidence, 'source_status' => 'approved_estimate', 'priority' => $forecast->confidence === 'confirmed' ? 'confirmed' : 'normal', 'updated_at' => $approvedEstimate->updated_at];
                }
            }
            if ($provisionalEstimate && (! $approvedEstimate || $provisionalEstimate->version > $approvedEstimate->version)) {
                foreach ($provisionalEstimate->lines->where('funding_required', true) as $line) {
                    if (($filters['category'] ?? null) && $line->category !== $filters['category']) {
                        continue;
                    }
                    $items[] = ['source_type' => 'provisional_cost_estimate', 'source_reference' => $provisionalEstimate->reference.'-v'.$provisionalEstimate->version.'-L'.$line->id.'-provisional', 'client' => $forecast->inquiry->client->company_name, 'inquiry_reference' => $forecast->inquiry->reference, 'transport_mode' => $forecast->inquiry->transport_mode, 'forecast_month' => $forecast->forecast_month->format('Y-m'), 'expected_shipments' => $forecast->expected_shipment_count, 'category' => $line->category, 'estimated_amount' => (string) BigDecimal::of((string) $line->estimate_currency_total)->multipliedBy($forecast->expected_shipment_count)->toScale(2, RoundingMode::HalfUp), 'currency' => $provisionalEstimate->currency, 'due_date' => null, 'confidence' => $forecast->confidence, 'source_status' => 'provisional_estimate', 'priority' => 'provisional', 'updated_at' => $provisionalEstimate->updated_at];
                }
            }
            if (! $approvedEstimate && ! $provisionalEstimate) {
                $items[] = ['source_type' => 'shipment_forecast', 'source_reference' => 'FCT-'.$forecast->id.'-r'.$forecast->revision, 'client' => $forecast->inquiry->client->company_name, 'inquiry_reference' => $forecast->inquiry->reference, 'transport_mode' => $forecast->inquiry->transport_mode, 'forecast_month' => $forecast->forecast_month->format('Y-m'), 'expected_shipments' => $forecast->expected_shipment_count, 'category' => 'unestimated', 'estimated_amount' => null, 'currency' => null, 'due_date' => null, 'confidence' => $forecast->confidence, 'source_status' => 'provisional', 'priority' => 'unpriced', 'updated_at' => $forecast->updated_at];
            }
        }

        return array_values(array_filter($items, fn ($item) => ! isset($filters['status']) || $filters['status'] === $item['source_status']));
    }

    private function summarizeRequirements(array $items): array
    {
        $totals = [];
        $groups = [];
        $provisionalTotals = [];
        $provisionalGroups = [];
        foreach ($items as $item) {
            if ($item['estimated_amount'] === null || $item['currency'] === null) {
                continue;
            }
            $currency = $item['currency'];
            $category = $item['category'];
            $provisional = str_starts_with($item['source_status'], 'provisional');
            $targetTotals = $provisional ? 'provisionalTotals' : 'totals';
            $targetGroups = $provisional ? 'provisionalGroups' : 'groups';
            $$targetTotals[$currency] = ($$targetTotals[$currency] ?? BigDecimal::zero())->plus($item['estimated_amount']);
            $key = $currency.'|'.$category;
            $$targetGroups[$key] ??= ['currency' => $currency, 'category' => $category, 'amount' => BigDecimal::zero()];
            $$targetGroups[$key]['amount'] = $$targetGroups[$key]['amount']->plus($item['estimated_amount']);
        }
        $grandByCurrency = [];
        foreach ($totals as $currency => $amount) {
            $grandByCurrency[$currency] = $amount;
        }
        $rows = array_map(fn ($group) => ['currency' => $group['currency'], 'category' => $group['category'], 'amount' => (string) $group['amount']->toScale(2, RoundingMode::HalfUp)], array_values($groups));
        $provisionalRows = array_map(fn ($group) => ['currency' => $group['currency'], 'category' => $group['category'], 'amount' => (string) $group['amount']->toScale(2, RoundingMode::HalfUp)], array_values($provisionalGroups));
        foreach ($rows as &$row) {
            $total = $grandByCurrency[$row['currency']];
            $row['percent'] = $total->isZero() ? 0 : (float) $row['amount'] / (float) (string) $total * 100;
        }
        unset($row);

        return ['rows' => $items, 'totals_by_currency' => array_map(fn ($currency, $amount) => ['currency' => $currency, 'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp)], array_keys($totals), array_values($totals)), 'by_category' => $rows, 'provisional_totals_by_currency' => array_map(fn ($currency, $amount) => ['currency' => $currency, 'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp)], array_keys($provisionalTotals), array_values($provisionalTotals)), 'provisional_by_category' => $provisionalRows];
    }

    private function coverageShortfall(array $requirements): array|string
    {
        $totals = [];
        $sourceKeys = [];
        foreach ($requirements as $row) {
            if ($row['source_status'] !== 'approved_estimate' || $row['estimated_amount'] === null || $row['currency'] === null) {
                continue;
            }
            $totals[$row['currency']] = ($totals[$row['currency']] ?? BigDecimal::zero())->plus($row['estimated_amount']);
            $sourceKeys[] = $row['source_reference'];
        }
        if (! $totals) {
            return 'Not recorded';
        }
        $allocatedBySource = DB::table('budget_allocations')->whereIn('requirement_source', $sourceKeys)->get(['requirement_source', 'amount'])->groupBy('requirement_source');
        $result = [];
        foreach ($totals as $currency => $required) {
            $allocated = BigDecimal::zero();
            foreach ($requirements as $row) {
                if ($row['currency'] !== $currency || ! $row['source_reference']) {
                    continue;
                }
                foreach ($allocatedBySource->get($row['source_reference'], collect()) as $allocation) {
                    $allocated = $allocated->plus((string) $allocation->amount);
                }
            }
            $shortfall = $required->minus($allocated);
            $result[] = ['currency' => $currency, 'required' => (string) $required->toScale(2, RoundingMode::HalfUp), 'allocated' => (string) $allocated->toScale(2, RoundingMode::HalfUp), 'shortfall' => (string) ($shortfall->isNegative() ? BigDecimal::zero() : $shortfall)->toScale(2, RoundingMode::HalfUp)];
        }

        return $result;
    }

    private function availableAuthority($plans, string $month): array
    {
        $activePlans = $plans->where('budget_month', $month)->where('status', 'approved');
        if ($activePlans->isEmpty()) {
            return [];
        }
        $planIds = $activePlans->pluck('id');
        $lines = DB::table('budget_lines')->whereIn('budget_plan_id', $planIds)->get(['id', 'budget_plan_id', 'approved_amount']);
        $allocations = DB::table('budget_allocations')->whereIn('budget_line_id', $lines->pluck('id'))->get(['budget_line_id', 'amount'])->groupBy('budget_line_id');
        $byCurrency = [];
        foreach ($activePlans as $plan) {
            $remaining = $lines->where('budget_plan_id', $plan->id)->reduce(function (BigDecimal $sum, object $line) use ($allocations) {
                $allocated = $allocations->get($line->id, collect())->reduce(fn (BigDecimal $acc, object $row) => $acc->plus((string) $row->amount), BigDecimal::zero());
                $lineRemaining = BigDecimal::of((string) ($line->approved_amount ?? '0'))->minus($allocated);

                return $sum->plus($lineRemaining->isNegative() ? '0' : (string) $lineRemaining);
            }, BigDecimal::zero());
            $byCurrency[$plan->currency] = ($byCurrency[$plan->currency] ?? BigDecimal::zero())->plus($remaining);
        }

        return array_map(fn ($currency, $amount) => ['currency' => $currency, 'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp)], array_keys($byCurrency), array_values($byCurrency));
    }

    private function validatedPlan(Request $request, bool $create): array
    {
        return $request->validate([
            'budget_month' => [$create ? 'required' : 'sometimes', 'date_format:Y-m'],
            'currency' => [$create ? 'required' : 'sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'lines' => [$create ? 'required' : 'sometimes', 'array', 'min:1', 'max:30'],
            'lines.*.category' => ['required_with:lines', Rule::in(self::CATEGORIES)],
            'lines.*.description' => ['nullable', 'string', 'max:180'],
            'lines.*.requested_amount' => ['required_with:lines', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'lines.*.adjustment_reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function saveLines(int $id, array $lines): void
    {
        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line['category']])) {
                throw ValidationException::withMessages(['lines' => 'A category can appear only once in a budget plan.']);
            }
            $seen[$line['category']] = true;
            DB::table('budget_lines')->insert(['budget_plan_id' => $id, 'category' => $line['category'], 'description' => $line['description'] ?? null, 'requested_amount' => $line['requested_amount'], 'approved_amount' => null, 'adjustment_reason' => $line['adjustment_reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function syncRequestedTotal(int $id): void
    {
        $total = DB::table('budget_lines')->where('budget_plan_id', $id)->get(['requested_amount'])->reduce(fn (BigDecimal $sum, object $line) => $sum->plus((string) $line->requested_amount), BigDecimal::zero());
        DB::table('monthly_budget_plans')->where('id', $id)->update(['requested_total' => (string) $total->toScale(2, RoundingMode::HalfUp), 'updated_at' => now()]);
    }

    private function transition(Request $request, int $id, string $action, string $status, ?string $remarks, AuditLogger $audit)
    {
        $row = DB::transaction(function () use ($request, $id, $action, $status, $remarks, $audit) {
            $plan = DB::table('monthly_budget_plans')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $plan, 404);
            if ($action === 'submitted') {
                abort_unless(in_array($plan->status, ['draft', 'revision_requested'], true), 409, 'Only draft or revision-requested plans can be submitted.');
                abort_if(BigDecimal::of((string) $plan->requested_total)->isNegativeOrZero(), 422, 'Budget total must be greater than zero.');
                abort_if(DB::table('budget_lines')->where('budget_plan_id', $id)->where('requested_amount', '>', 0)->doesntExist(), 422, 'Add a budget line before submitting.');
            } elseif ($action === 'closed') {
                abort_unless($plan->status === 'approved', 409, 'Only approved budgets can be closed.');
            } else {
                abort_unless(in_array($plan->status, ['submitted', 'under_review'], true), 409, 'Only submitted plans may be reviewed.');
                abort_if($plan->submitted_by === $request->user()->id && config('ifc_budget.prevent_self_approval', true), 403, 'Submitter cannot approve or review their own budget.');
            }
            $before = $plan->status;
            $changes = ['status' => $status, 'updated_at' => now()];
            if ($action === 'submitted') {
                $changes += ['submitted_by' => $request->user()->id, 'submitted_at' => now()];
            }
            if ($action === 'approved') {
                $changes += ['approved_total' => $plan->requested_total, 'approved_by' => $request->user()->id, 'approved_at' => now()];
                DB::table('budget_lines')->where('budget_plan_id', $id)->update(['approved_amount' => DB::raw('requested_amount'), 'updated_at' => now()]);
            }
            DB::table('monthly_budget_plans')->where('id', $id)->update($changes);
            DB::table('budget_approval_steps')->insert(['budget_plan_id' => $id, 'actor_id' => $request->user()->id, 'action' => $action, 'from_status' => $before, 'to_status' => $status, 'remarks' => $remarks, 'acted_at' => now()]);
            DB::table('budget_audit_history')->insert(['budget_plan_id' => $id, 'actor_id' => $request->user()->id, 'action' => $action, 'before_values' => json_encode(['status' => $before]), 'after_values' => json_encode(['status' => $status, 'approved_total' => $action === 'approved' ? $plan->requested_total : null]), 'reason' => $remarks, 'created_at' => now()]);
            $audit->record('budgets.'.$action, MonthlyBudgetPlan::find($id), ['reference' => $plan->reference, 'before' => ['status' => $before], 'after' => ['status' => $status], 'reason' => $remarks]);

            return DB::table('monthly_budget_plans')->where('id', $id)->first();
        });

        return response()->json(['data' => $row]);
    }

    private function assertAccounting(Request $request): void
    {
        abort_unless($request->user()->hasRole('Accounting'), 403);
    }

    private function assertFinancialScope(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['Accounting', 'Finance', 'Executive']), 403);
    }
}
