<?php

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\CashAdvance;
use App\Models\CashAdvanceDisbursement;
use App\Models\CashAdvanceLiquidation;
use App\Models\CashAdvanceReturn;
use App\Models\ExpenseAdjustment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseDocument;
use App\Models\ExpenseEvent;
use App\Models\ExpenseException;
use App\Models\Shipment;
use App\Services\AuditLogger;
use App\Services\DocumentScanService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExpenseWorkspaceController extends Controller
{
    private const DEPARTMENTS = ['Operations', 'Transport', 'Forwarding'];

    public function page(Request $request)
    {
        return response()->json(['page' => $request->route('page')]);
    }

    public function options(Request $request)
    {
        abort_unless($request->user()->can('expenses.create') || $request->user()->can('cash-advances.request'), 403);
        $role = $this->department($request);
        abort_unless($role !== null, 403, 'Expense entry options are limited to operational department roles.');
        $shipments = Shipment::query()->with('assignments')->when($role, fn ($q) => $q->whereHas('assignments', fn ($a) => $a->where('department', $role)->where(fn ($x) => $x->whereNull('assigned_user_id')->orWhere('assigned_user_id', $request->user()->id))))->whereNull('archived_at')->orderByDesc('id')->limit(300)->get(['id', 'job_number', 'customer_name', 'currency', 'master_status']);

        $allocations = DB::table('budget_allocations')->join('budget_lines', 'budget_lines.id', '=', 'budget_allocations.budget_line_id')->join('monthly_budget_plans', 'monthly_budget_plans.id', '=', 'budget_allocations.budget_plan_id')->where('monthly_budget_plans.status', 'approved')->when($role, fn ($q) => $q->where('monthly_budget_plans.department', $role))->orderByDesc('budget_allocations.id')->limit(500)->get(['budget_allocations.id', 'budget_allocations.reference', 'budget_allocations.amount', 'budget_allocations.currency', 'budget_allocations.purpose', 'budget_lines.category', 'monthly_budget_plans.department']);

        return response()->json(['categories' => ExpenseCategory::where('active', true)->orderBy('label')->get(['id', 'code', 'label', 'estimate_category', 'budget_category']), 'shipments' => $shipments, 'allocations' => $allocations]);
    }

    public function dashboard(Request $request)
    {
        abort_unless($request->user()->can('expenses.view') || $request->user()->can('budgets.utilization.view'), 403);
        $query = $this->visibleExpenses($request);
        $month = $request->date('from')?->startOfMonth() ?? now()->startOfMonth();
        $to = $request->date('to') ?? now()->endOfMonth();
        $period = (clone $query)->whereBetween('incurred_on', [$month->toDateString(), $to->toDateString()]);
        $verifiedRows = (clone $period)->where('status', 'verified')->with(['category:id,label', 'adjustments'])->get();
        $totals = $verifiedRows->groupBy('currency')->map(fn ($expenses, $currency) => ['currency' => $currency, 'actual_total' => (string) $expenses->reduce(fn (BigDecimal $sum, ActualExpense $expense) => $sum->plus((string) $expense->amount)->plus((string) $expense->adjustments->where('status', 'verified')->where('adjustment_type', 'adjustment')->sum('amount'))->minus((string) $expense->adjustments->where('status', 'verified')->where('adjustment_type', 'reversal')->sum('amount')), BigDecimal::zero())->toScale(2)])->values();
        $actualCategories = $verifiedRows->groupBy(fn (ActualExpense $expense) => $expense->currency.'|'.$expense->department.'|'.$expense->category_id)->map(function ($expenses): array {
            $first = $expenses->first();
            $amount = $expenses->reduce(fn (BigDecimal $sum, ActualExpense $expense) => $sum->plus((string) $expense->amount)->plus((string) $expense->adjustments->where('status', 'verified')->where('adjustment_type', 'adjustment')->sum('amount'))->minus((string) $expense->adjustments->where('status', 'verified')->where('adjustment_type', 'reversal')->sum('amount')), BigDecimal::zero());

            return ['category' => $first->category?->label ?? 'Uncategorized', 'department' => $first->department, 'currency' => $first->currency, 'amount' => (string) $amount->toScale(2)];
        })->values();
        $pending = (clone $query)->whereIn('status', ['submitted', 'under_review', 'correction_requested'])->count();
        $missing = (clone $query)->where('receipt_status', 'missing')->count();
        $advanceQuery = $this->visibleAdvances($request);
        $advances = (clone $advanceQuery)->whereIn('status', ['disbursed', 'partially_liquidated'])->get();
        foreach ((clone $advanceQuery)->whereNotNull('required_by')->whereDate('required_by', '<', today())->whereNotIn('status', ['fully_liquidated', 'rejected'])->get() as $advance) {
            $dedupe = 'advance:'.$advance->id.':overdue_liquidation';
            ExpenseException::firstOrCreate(['dedupe_key' => $dedupe], ['cash_advance_id' => $advance->id, 'type' => 'overdue_liquidation', 'severity' => 'normal', 'status' => 'open', 'description' => 'The required-by date has passed and this advance is not fully reconciled.']);
        }
        $outstanding = [];
        foreach ($advances as $advance) {
            $value = $this->advanceBalance($advance);
            $outstanding[$advance->currency] = ($outstanding[$advance->currency] ?? BigDecimal::zero())->plus($value['outstanding']);
        }

        return response()->json(['period' => [$month->toDateString(), $to->toDateString()], 'currency_policy' => 'Amounts remain separated by currency; no exchange-rate basis is configured.', 'actual_by_currency' => $totals, 'actual_by_category' => $actualCategories, 'pending_expense_reviews' => $pending, 'missing_receipts' => $missing, 'outstanding_advances' => array_map(fn ($currency, $amount) => ['currency' => $currency, 'amount' => (string) $amount->toScale(2, RoundingMode::HalfUp)], array_keys($outstanding), array_values($outstanding)), 'pending_liquidations' => CashAdvanceLiquidation::whereIn('cash_advance_id', (clone $advanceQuery)->select('id'))->whereIn('status', ['submitted', 'under_review'])->count(), 'open_commitments' => null, 'commitments_note' => 'No source-of-truth open commitments ledger exists in Phase 1–5; excluded from utilization.', 'budget_utilization' => $this->budgetUtilizationData($request)]);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('expenses.view'), 403);
        $query = $this->visibleExpenses($request)->with(['shipment:id,job_number,customer_name', 'category:id,code,label', 'employee:id,name', 'documents:id,expense_id,verification_status']);
        $query->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s->where('reference', 'like', '%'.$request->string('search').'%')->orWhere('vendor', 'like', '%'.$request->string('search').'%')->orWhere('description', 'like', '%'.$request->string('search').'%')->orWhereHas('shipment', fn ($j) => $j->where('job_number', 'like', '%'.$request->string('search').'%'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')))
            ->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('code', $request->string('category'))))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('incurred_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('incurred_on', '<=', $request->date('to')));

        return $query->orderByDesc('incurred_on')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));
    }

    public function show(Request $request, ActualExpense $expense)
    {
        abort_unless($this->visibleExpenses($request)->whereKey($expense->id)->exists(), 404);

        return response()->json(['data' => $expense->load(['shipment:id,job_number,customer_name', 'category', 'employee:id,name', 'documents', 'events.actor:id,name', 'adjustments']), 'variance' => $this->shipmentVariance($expense->shipment_id)]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('expenses.create'), 403);
        $data = $request->validate([
            'shipment_id' => 'required|integer|exists:shipments,id', 'transport_trip_id' => 'nullable|integer|exists:shipment_transport_trips,id', 'forwarding_booking_id' => 'nullable|integer|exists:shipment_forwarding_bookings,id', 'container_id' => 'nullable|integer|exists:shipment_containers,id',
            'department' => ['required', Rule::in(self::DEPARTMENTS)], 'category_id' => 'required|integer|exists:expense_categories,id', 'description' => 'required|string|max:220', 'other_explanation' => 'nullable|string|max:1000', 'vendor' => 'nullable|string|max:180', 'incurred_on' => 'required|date', 'amount' => 'required|numeric|min:0.01|max:99999999999999.99', 'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'tax_treatment' => ['nullable', Rule::in(['none', 'inclusive', 'exclusive', 'withholding'])], 'tax_amount' => 'nullable|numeric|min:0', 'withholding_amount' => 'nullable|numeric|min:0', 'cost_estimate_line_id' => 'nullable|integer|exists:cost_estimate_lines,id', 'budget_allocation_id' => 'nullable|integer|exists:budget_allocations,id', 'payment_reference' => 'nullable|string|max:100', 'recoverability' => ['required', Rule::in(['included_in_quotation', 'pending_client_approval', 'company_nonrecoverable', 'undetermined'])], 'claim_status' => ['nullable', Rule::in(['not_submitted', 'submitted'])], 'idempotency_key' => 'nullable|string|max:120',
        ]);
        $key = $request->header('Idempotency-Key') ?? $data['idempotency_key'] ?? null;
        if ($key && ($existing = ActualExpense::where('idempotency_key', $key)->first())) {
            abort_unless($this->visibleExpenses($request)->whereKey($existing->id)->exists(), 403);

            return response()->json(['data' => $existing, 'idempotent_replay' => true]);
        }
        $shipment = $this->authorizedShipment($request, $data['shipment_id'], $data['department']);
        $category = ExpenseCategory::findOrFail($data['category_id']);
        abort_unless($category->active, 422);
        abort_if($category->code === 'other_operating' && blank($data['other_explanation']), 422, 'Provide an explanation for other operating costs.');
        $this->validateRelatedRecords($shipment, $data);
        $this->validateFinancialLinks($shipment, $data, $category);
        $tax = BigDecimal::of((string) ($data['tax_amount'] ?? '0'));
        $withholding = BigDecimal::of((string) ($data['withholding_amount'] ?? '0'));
        abort_if($tax->plus($withholding)->isGreaterThan($data['amount']), 422, 'Tax and withholding amounts cannot exceed the recorded expense amount.');

        $expense = DB::transaction(function () use ($request, $data, $category, $key): ActualExpense {
            $reference = $this->nextReference('expense_sequences', 'EXP');
            $expense = ActualExpense::create([
                ...$data, 'reference' => $reference, 'idempotency_key' => $key, 'responsible_employee_id' => $request->user()->id, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'department' => $data['department'], 'description' => $category->code === 'other_operating' ? $data['description'].' — '.$data['other_explanation'] : $data['description'], 'status' => 'draft', 'receipt_status' => 'missing',
                'tax_treatment' => $data['tax_treatment'] ?? 'none', 'tax_amount' => $data['tax_amount'] ?? 0, 'withholding_amount' => $data['withholding_amount'] ?? 0, 'client_charge_status' => ($data['recoverability'] ?? '') === 'pending_client_approval' ? 'pending_approval' : 'not_applicable', 'claim_status' => $data['claim_status'] ?? 'not_submitted',
            ]);
            $this->event($expense, 'created', $request->user()->id, null, $expense->only(['reference', 'shipment_id', 'department', 'category_id', 'amount', 'currency', 'status']));

            return $expense;
        });
        $audit->record('expenses.created', $expense, ['reference' => $expense->reference, 'shipment_id' => $expense->shipment_id, 'amount' => $expense->amount, 'currency' => $expense->currency]);

        return response()->json(['data' => $expense->load(['category', 'shipment'])], 201);
    }

    public function update(Request $request, ActualExpense $expense, AuditLogger $audit)
    {
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.update') && $expense->created_by === $request->user()->id && in_array($expense->status, ['draft', 'correction_requested'], true), 403);
        $data = $request->validate(['description' => 'sometimes|required|string|max:220', 'vendor' => 'nullable|string|max:180', 'incurred_on' => 'sometimes|required|date', 'amount' => 'sometimes|required|numeric|min:0.01|max:99999999999999.99', 'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'category_id' => 'sometimes|required|integer|exists:expense_categories,id', 'payment_reference' => 'nullable|string|max:100', 'recoverability' => ['sometimes', 'required', Rule::in(['included_in_quotation', 'pending_client_approval', 'company_nonrecoverable', 'undetermined'])], 'expected_version' => 'required|integer']);
        abort_unless((int) $expense->lock_version === (int) $data['expected_version'], 409, 'This draft has changed. Reload before editing.');
        unset($data['expected_version']);
        $this->validateFinancialLinks($expense->shipment, ['cost_estimate_line_id' => $expense->cost_estimate_line_id, 'budget_allocation_id' => $expense->budget_allocation_id, 'currency' => $data['currency'] ?? $expense->currency], ExpenseCategory::findOrFail($data['category_id'] ?? $expense->category_id));
        $before = $expense->only(array_keys($data));
        $expense->update([...$data, 'status' => 'draft', 'updated_by' => $request->user()->id, 'lock_version' => $expense->lock_version + 1]);
        $this->event($expense, 'draft_updated', $request->user()->id, $before, $expense->only(array_keys($data)));
        $audit->record('expenses.draft_updated', $expense, ['before' => $before, 'after' => $expense->only(array_keys($data))]);

        return response()->json(['data' => $expense->refresh()]);
    }

    public function submit(Request $request, ActualExpense $expense, AuditLogger $audit)
    {
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.submit') && $expense->created_by === $request->user()->id && $expense->status === 'draft', 403);
        $before = $expense->status;
        $hasVerifiedReceipt = $expense->documents()->whereIn('category', ['receipt', 'invoice'])->where('verification_status', 'verified')->exists();
        $hasPendingDocuments = $expense->documents()->where('verification_status', 'pending')->exists();
        $receiptStatus = $hasVerifiedReceipt ? 'complete' : ($hasPendingDocuments ? 'pending_verification' : 'missing');
        $expense->update(['status' => 'submitted', 'submitted_at' => now(), 'lock_version' => $expense->lock_version + 1, 'receipt_status' => $receiptStatus]);
        $this->event($expense, 'submitted', $request->user()->id, ['status' => $before], ['status' => 'submitted', 'receipt_status' => $expense->receipt_status]);
        if ($expense->receipt_status === 'missing') {
            $this->raiseException($expense, 'missing_or_unverified_receipt', 'normal', 'No receipt or invoice is attached to submitted expense.');
        }
        if (! $expense->budget_allocation_id) {
            $this->raiseException($expense, 'unfunded_expense', 'normal', 'No approved budget allocation is linked.');
        } else {
            $category = $expense->category;
            $allocation = DB::table('budget_allocations')->join('budget_lines', 'budget_lines.id', '=', 'budget_allocations.budget_line_id')->where('budget_allocations.id', $expense->budget_allocation_id)->select('budget_allocations.amount', 'budget_allocations.currency', 'budget_lines.category')->first();
            if (! $allocation || $allocation->currency !== $expense->currency || $allocation->category !== $category->budget_category) {
                $this->raiseException($expense, 'incorrect_category_association', 'high', 'Linked allocation does not match the expense currency or category.');
            } elseif (BigDecimal::of((string) ActualExpense::where('budget_allocation_id', $expense->budget_allocation_id)->where('status', 'verified')->sum('amount'))->isGreaterThan((string) $allocation->amount)) {
                $this->raiseException($expense, 'category_allowance_exceeded', 'high', 'Verified actual expenses exceed the linked allocation amount.');
            }
        }
        if ($expense->client_charge_status === 'pending_approval') {
            $this->raiseException($expense, 'unapproved_additional_charge', 'normal', 'Client approval is pending; internal expense verification does not authorize client billing.');
        }
        $audit->record('expenses.submitted', $expense, ['reference' => $expense->reference, 'receipt_status' => $expense->receipt_status]);

        return response()->json(['data' => $expense->refresh()]);
    }

    public function verify(Request $request, ActualExpense $expense, AuditLogger $audit)
    {
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.verify'), 403);
        abort_if((int) $expense->created_by === (int) $request->user()->id, 403, 'A preparer cannot verify their own expense.');
        $data = $request->validate(['decision' => ['required', Rule::in(['under_review', 'verified', 'correction_requested', 'rejected_claim'])], 'reason' => 'required|string|min:3|max:2000']);
        abort_unless(in_array($expense->status, ['submitted', 'under_review', 'correction_requested'], true), 409);
        if ($data['decision'] === 'under_review') {
            $expense->update(['status' => 'under_review']);
        } elseif ($data['decision'] === 'rejected_claim') {
            abort_unless($expense->claim_status === 'submitted', 409, 'Only a submitted reimbursement claim can be rejected as a claim.');
        }
        if ($data['decision'] === 'verified' && $expense->receipt_status !== 'complete') {
            $receiptException = ExpenseException::where('expense_id', $expense->id)->where('type', 'missing_or_unverified_receipt')->where('status', 'approved_exception')->exists();
            abort_unless($receiptException, 422, 'Receipt support must be verified or a Finance-approved documentation exception must exist.');
        }
        $before = ['status' => $expense->status, 'claim_status' => $expense->claim_status, 'receipt_status' => $expense->receipt_status];
        if ($data['decision'] === 'rejected_claim') {
            // The actual cost remains recognized; only the employee reimbursement claim is rejected.
            $expense->update(['status' => 'verified', 'claim_status' => 'rejected', 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        } elseif ($data['decision'] === 'correction_requested') {
            $expense->update(['status' => 'correction_requested']);
        } else {
            $expense->update(['status' => 'verified', 'claim_status' => $expense->claim_status === 'not_submitted' ? 'not_applicable' : $expense->claim_status, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        }
        $this->event($expense, $data['decision'], $request->user()->id, $before, ['status' => $expense->status, 'claim_status' => $expense->claim_status], $data['reason']);
        if ($data['decision'] === 'verified') {
            $this->detectExpenseExceptions($expense);
        }
        $audit->record('expenses.'.$data['decision'], $expense, ['before' => $before, 'after' => ['status' => $expense->status, 'claim_status' => $expense->claim_status], 'reason' => $data['reason']]);

        return response()->json(['data' => $expense->refresh()]);
    }

    public function upload(Request $request, ActualExpense $expense, AuditLogger $audit, DocumentScanService $scan)
    {
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.receipts.upload') && $expense->created_by === $request->user()->id && in_array($expense->status, ['draft', 'correction_requested'], true), 403);
        $data = $request->validate(['file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'category' => ['required', Rule::in(['receipt', 'invoice', 'supporting_document'])], 'document_group' => 'nullable|uuid', 'upload_key' => 'nullable|string|max:120']);
        $key = $request->header('Idempotency-Key') ?? $data['upload_key'] ?? null;
        if ($key && ($existing = ExpenseDocument::where('upload_key', $key)->first())) {
            abort_unless((int) $existing->expense_id === (int) $expense->id, 409);

            return response()->json(['data' => $existing, 'idempotent_replay' => true]);
        }
        $group = $data['document_group'] ?? (string) Str::uuid();
        $previous = $expense->documents()->where('document_group', $group)->orderByDesc('version')->first();
        $stored = $scan->store($request->file('file'), 'actual-expenses/'.$expense->id);
        $document = $expense->documents()->create(['document_group' => $group, 'category' => $data['category'], 'original_name' => Str::limit(basename($request->file('file')->getClientOriginalName()), 255, ''), 'disk_path' => $stored['path'], 'mime_type' => $request->file('file')->getMimeType(), 'size_bytes' => $request->file('file')->getSize(), 'scan_status' => $stored['scan_status'], 'scan_result' => $stored['scan_result'], 'scanned_at' => $stored['scanned_at'], 'version' => ($previous?->version ?? 0) + 1, 'uploaded_by' => $request->user()->id, 'upload_key' => $key]);
        $expense->update(['receipt_status' => 'pending_verification']);
        $this->event($expense, 'receipt_uploaded', $request->user()->id, null, ['document_id' => $document->id, 'category' => $document->category, 'version' => $document->version]);
        $audit->record('expenses.receipt_uploaded', $expense, ['document_id' => $document->id, 'category' => $document->category, 'version' => $document->version]);

        return response()->json(['data' => $document, 'message' => $stored['scan_status'] === 'clean' ? 'Document scanned and stored privately.' : 'Document is quarantined until a clean scan is available.'], 201);
    }

    public function download(Request $request, ExpenseDocument $document)
    {
        abort_unless($request->user()->can('expenses.view') && $this->visibleExpenses($request)->whereKey($document->expense_id)->exists(), 403);
        app(DocumentScanService::class)->assertTrusted($document->scan_status);
        abort_unless(Storage::disk('local')->exists($document->disk_path), 404);

        return Storage::disk('local')->download($document->disk_path, basename($document->original_name), ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function verifyDocument(Request $request, ExpenseDocument $document, AuditLogger $audit)
    {
        app(DocumentScanService::class)->assertTrusted($document->scan_status);
        $expense = $document->expense;
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.receipts.verify') && $expense->created_by !== $request->user()->id, 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected'])], 'reason' => 'required|string|min:3|max:2000']);
        $document->update(['verification_status' => $data['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        $hasVerifiedReceipt = $expense->documents()->whereIn('category', ['receipt', 'invoice'])->where('verification_status', 'verified')->exists();
        $status = $expense->documents()->where('verification_status', 'rejected')->exists() ? 'invalid' : ($expense->documents()->where('verification_status', 'pending')->exists() ? 'pending_verification' : ($hasVerifiedReceipt ? 'complete' : 'missing'));
        $expense->update(['receipt_status' => $status]);
        $this->event($expense, 'receipt_'.$data['decision'], $request->user()->id, null, ['document_id' => $document->id, 'verification_status' => $data['decision']], $data['reason']);
        $audit->record('expenses.receipt_'.$data['decision'], $expense, ['document_id' => $document->id, 'reason' => $data['reason']]);
        if ($status === 'invalid') {
            $this->raiseException($expense, 'invalid_receipt', 'normal', $data['reason']);
        }

        return response()->json(['data' => $document->refresh(), 'receipt_status' => $status]);
    }

    public function recordClientApproval(Request $request, ActualExpense $expense, AuditLogger $audit)
    {
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.client-approval.record') && $request->user()->hasRole('Finance'), 403);
        abort_unless($expense->client_charge_status === 'pending_approval', 409);
        abort_unless($expense->documents()->where('category', 'supporting_document')->where('verification_status', 'verified')->exists(), 422, 'A verified document containing the client decision is required.');
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'client_reference' => 'required|string|min:3|max:100', 'reason' => 'required|string|min:3|max:2000']);
        $before = ['client_charge_status' => $expense->client_charge_status, 'recoverability' => $expense->recoverability];
        $expense->update(['client_charge_status' => $data['decision'], 'recoverability' => $data['decision'] === 'approved' ? 'separately_client_approved' : 'client_rejected', 'client_approval_reference' => $data['client_reference']]);
        $this->event($expense, 'client_charge_'.$data['decision'], $request->user()->id, $before, ['client_charge_status' => $expense->client_charge_status, 'recoverability' => $expense->recoverability, 'client_reference' => $expense->client_approval_reference], $data['reason']);
        if ($data['decision'] === 'rejected') {
            $this->raiseException($expense, 'client_rejected_nonrecoverable', 'high', $data['reason']);
        }
        $audit->record('expenses.client_charge_'.$data['decision'], $expense, ['client_reference' => $data['client_reference'], 'reason' => $data['reason']]);

        return response()->json(['data' => $expense->refresh()]);
    }

    public function adjustments(Request $request, ActualExpense $expense, AuditLogger $audit)
    {
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.adjust') && $expense->status === 'verified', 403);
        $data = $request->validate(['adjustment_type' => ['required', Rule::in(['reversal', 'adjustment'])], 'amount' => 'required|numeric|min:0.01|max:99999999999999.99', 'reason' => 'required|string|min:5|max:2000']);
        $row = DB::transaction(function () use ($request, $expense, $data): ExpenseAdjustment {
            $reference = $this->nextReference('expense_sequences', 'ADJ');
            $row = ExpenseAdjustment::create([...$data, 'reference' => $reference, 'expense_id' => $expense->id, 'created_by' => $request->user()->id]);
            $this->event($expense, 'adjustment_submitted', $request->user()->id, null, $row->only(['reference', 'adjustment_type', 'amount', 'status']), $data['reason']);

            return $row;
        });
        $audit->record('expenses.adjustment_submitted', $expense, ['adjustment_id' => $row->id, 'amount' => $row->amount, 'reason' => $row->reason]);

        return response()->json(['data' => $row], 201);
    }

    public function verifyAdjustment(Request $request, ExpenseAdjustment $adjustment, AuditLogger $audit)
    {
        $expense = $adjustment->expense;
        $this->assertExpenseVisible($request, $expense);
        abort_unless($request->user()->can('expenses.verify') && $adjustment->created_by !== $request->user()->id, 403);
        abort_unless($adjustment->status === 'submitted', 409);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected'])], 'reason' => 'required|string|min:3|max:2000']);
        if ($data['decision'] === 'verified' && $adjustment->adjustment_type === 'reversal') {
            $previous = BigDecimal::of((string) $expense->adjustments()->where('status', 'verified')->where('adjustment_type', 'reversal')->sum('amount'));
            abort_if($previous->plus((string) $adjustment->amount)->isGreaterThan((string) $expense->amount), 422, 'Verified reversals cannot exceed the original expense amount.');
        }
        $adjustment->update(['status' => $data['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        $this->event($expense, 'adjustment_'.$data['decision'], $request->user()->id, null, ['adjustment_id' => $adjustment->id, 'amount' => $adjustment->amount], $data['reason']);
        $audit->record('expenses.adjustment_'.$data['decision'], $expense, ['adjustment_id' => $adjustment->id, 'reason' => $data['reason']]);

        return response()->json(['data' => $adjustment->refresh()]);
    }

    public function advances(Request $request)
    {
        abort_unless($request->user()->can('cash-advances.view'), 403);
        $rows = $this->visibleAdvances($request)->with(['shipment:id,job_number,customer_name', 'disbursements', 'returns'])->orderByDesc('id')->paginate(20);
        $rows->getCollection()->transform(fn (CashAdvance $advance) => $advance->setAttribute('balance', $this->formatAdvanceBalance($this->advanceBalance($advance))));

        return $rows;
    }

    public function liquidations(Request $request)
    {
        abort_unless($request->user()->can('cash-advances.view') || $request->user()->can('cash-advances.liquidate'), 403);

        return CashAdvanceLiquidation::whereIn('cash_advance_id', $this->visibleAdvances($request)->select('id'))
            ->with(['cashAdvance:id,reference,shipment_id,department,requester_id', 'lines.expense:id,reference,amount,currency,description'])
            ->orderByDesc('id')->paginate(20);
    }

    public function createAdvance(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('cash-advances.request'), 403);
        $data = $request->validate(['shipment_id' => 'required|integer|exists:shipments,id', 'department' => ['required', Rule::in(self::DEPARTMENTS)], 'budget_allocation_id' => 'required|integer|exists:budget_allocations,id', 'requested_amount' => 'required|numeric|min:0.01|max:99999999999999.99', 'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'purpose' => 'required|string|min:5|max:2000', 'category_codes' => 'required|array|min:1', 'category_codes.*' => 'required|string|exists:expense_categories,code', 'required_by' => 'nullable|date|after_or_equal:today']);
        $this->authorizedShipment($request, $data['shipment_id'], $data['department']);
        $allocation = DB::table('budget_allocations')->join('monthly_budget_plans', 'monthly_budget_plans.id', '=', 'budget_allocations.budget_plan_id')->where('budget_allocations.id', $data['budget_allocation_id'])->where('monthly_budget_plans.status', 'approved')->select('budget_allocations.*')->first();
        $allocationDepartment = $allocation ? DB::table('monthly_budget_plans')->where('id', $allocation->budget_plan_id)->value('department') : null;
        abort_unless($allocation && $allocation->currency === $data['currency'] && $allocationDepartment === $data['department'], 422, 'Select an approved allocation for the same department and currency.');
        $allocationCategory = DB::table('budget_allocations')->join('budget_lines', 'budget_lines.id', '=', 'budget_allocations.budget_line_id')->where('budget_allocations.id', $allocation->id)->value('budget_lines.category');
        abort_unless(ExpenseCategory::where('active', true)->whereIn('code', $data['category_codes'])->where('budget_category', $allocationCategory)->exists(), 422, 'Advance categories must match an active category on the approved allocation.');
        $advance = DB::transaction(function () use ($request, $data, $audit): CashAdvance {
            $ref = $this->nextReference('cash_advance_sequences', 'CA');
            $advance = CashAdvance::create([...$data, 'reference' => $ref, 'requester_id' => $request->user()->id, 'status' => 'draft']);
            $audit->record('cash_advances.created', $advance, ['reference' => $ref, 'amount' => $advance->requested_amount, 'currency' => $advance->currency]);

            return $advance;
        });

        return response()->json(['data' => $advance], 201);
    }

    public function advanceTransition(Request $request, CashAdvance $advance, string $action, AuditLogger $audit)
    {
        abort_unless($this->visibleAdvances($request)->whereKey($advance->id)->exists(), 403);
        $data = $request->validate(['reason' => 'nullable|string|max:2000', 'approved_amount' => 'nullable|numeric|min:0.01', 'expected_version' => 'nullable|integer']);
        abort_if(isset($data['expected_version']) && (int) $data['expected_version'] !== $advance->lock_version, 409, 'The advance changed. Reload before continuing.');
        $before = ['status' => $advance->status, 'approved_amount' => $advance->approved_amount];
        if ($action === 'submit') {
            abort_unless($request->user()->can('cash-advances.request') && $advance->requester_id === $request->user()->id && $advance->status === 'draft', 403);
            $this->assertAllocationCapacity($advance->budget_allocation_id, BigDecimal::of((string) $advance->requested_amount), $advance->currency);
            $advance->update(['status' => 'submitted', 'lock_version' => $advance->lock_version + 1]);
        } elseif ($action === 'review') {
            abort_unless($request->user()->can('cash-advances.review') && $advance->status === 'submitted' && $advance->requester_id !== $request->user()->id, 403);
            $advance->update(['status' => 'under_review', 'reviewed_by' => $request->user()->id, 'review_remarks' => $data['reason'] ?? null, 'lock_version' => $advance->lock_version + 1]);
        } elseif (in_array($action, ['approve', 'reject'], true)) {
            abort_unless($request->user()->can('cash-advances.approve') && $advance->status === 'under_review' && $advance->requester_id !== $request->user()->id && $advance->reviewed_by !== $request->user()->id, 403);
            if ($action === 'approve') {
                $amount = BigDecimal::of((string) ($data['approved_amount'] ?? $advance->requested_amount));
                abort_if($amount->isGreaterThan((string) $advance->requested_amount), 422, 'Approved amount cannot exceed the request.');
                $this->assertAllocationCapacity($advance->budget_allocation_id, $amount, $advance->currency, $advance->id);
                $advance->update(['status' => 'disbursement_pending', 'approved_amount' => (string) $amount->toScale(2), 'approved_by' => $request->user()->id, 'lock_version' => $advance->lock_version + 1]);
            } else {
                $advance->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'review_remarks' => $data['reason'] ?? 'Request rejected.', 'lock_version' => $advance->lock_version + 1]);
            }
        } else {
            abort(404);
        }
        $audit->record('cash_advances.'.$action, $advance, ['before' => $before, 'after' => ['status' => $advance->status, 'approved_amount' => $advance->approved_amount], 'reason' => $data['reason'] ?? null]);

        return response()->json(['data' => $advance->refresh()]);
    }

    public function recordDisbursement(Request $request, CashAdvance $advance, AuditLogger $audit)
    {
        abort_unless($this->visibleAdvances($request)->whereKey($advance->id)->exists() && $request->user()->can('cash-advances.disbursement.record'), 403);
        abort_unless($advance->status === 'disbursement_pending' && $advance->requester_id !== $request->user()->id, 409);
        $data = $request->validate(['source_type' => ['required', Rule::in(['bank_transfer', 'cash_voucher', 'existing_payment'])], 'source_reference' => 'required|string|max:100', 'amount' => 'required|numeric|min:0.01', 'disbursed_on' => 'required|date']);
        $amount = BigDecimal::of((string) $data['amount']);
        abort_if($advance->disbursements()->whereIn('status', ['pending_verification', 'verified'])->exists(), 409, 'This workflow accepts one disbursement record for the approved amount.');
        abort_unless($amount->isEqualTo((string) $advance->approved_amount), 422, 'Record the approved amount as one verified disbursement.');
        $row = $advance->disbursements()->create([...$data, 'currency' => $advance->currency, 'recorded_by' => $request->user()->id, 'status' => 'pending_verification']);
        $audit->record('cash_advances.disbursement_recorded', $advance, ['disbursement_id' => $row->id, 'source_type' => $row->source_type, 'source_reference' => $row->source_reference, 'amount' => $row->amount]);

        return response()->json(['data' => $row], 201);
    }

    public function verifyDisbursement(Request $request, CashAdvanceDisbursement $disbursement, AuditLogger $audit)
    {
        $advance = $disbursement->cashAdvance;
        abort_unless($request->user()->can('cash-advances.disbursement.verify') && $disbursement->recorded_by !== $request->user()->id && $advance->requester_id !== $request->user()->id, 403);
        abort_unless($disbursement->status === 'pending_verification' && $advance->status === 'disbursement_pending', 409);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected'])], 'reason' => 'required|string|min:3|max:2000']);
        $disbursement->update(['status' => $data['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        if ($data['decision'] === 'verified') {
            $advance->update(['status' => 'disbursed', 'lock_version' => $advance->lock_version + 1]);
        }
        $audit->record('cash_advances.disbursement_'.$data['decision'], $advance, ['disbursement_id' => $disbursement->id, 'source_reference' => $disbursement->source_reference, 'reason' => $data['reason']]);

        return response()->json(['data' => $disbursement->refresh(), 'advance' => $advance->refresh()]);
    }

    public function createLiquidation(Request $request, CashAdvance $advance, AuditLogger $audit)
    {
        abort_unless($this->visibleAdvances($request)->whereKey($advance->id)->exists() && $request->user()->can('cash-advances.liquidate') && $advance->requester_id === $request->user()->id, 403);
        abort_unless(in_array($advance->status, ['disbursed', 'partially_liquidated'], true), 409, 'Liquidation requires a verified disbursement.');
        $data = $request->validate(['expenses' => 'required|array|min:1', 'expenses.*.expense_id' => 'required|integer|distinct|exists:actual_expenses,id', 'expenses.*.eligible_amount' => 'required|numeric|min:0.01']);
        $liquidation = DB::transaction(function () use ($request, $advance, $data, $audit): CashAdvanceLiquidation {
            $advance = CashAdvance::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            $liquidation = $advance->liquidations()->create(['reference' => $this->nextReference('cash_advance_sequences', 'LQ'), 'submitted_by' => $request->user()->id, 'status' => 'draft']);
            $sum = BigDecimal::zero();
            foreach ($data['expenses'] as $line) {
                $expense = ActualExpense::whereKey($line['expense_id'])->lockForUpdate()->firstOrFail();
                abort_unless($expense->status === 'verified' && $expense->shipment_id === $advance->shipment_id && $expense->department === $advance->department && $expense->currency === $advance->currency, 422, 'Expense must be verified and match the advance shipment, department and currency.');
                $amount = BigDecimal::of((string) $line['eligible_amount']);
                abort_if($amount->isGreaterThan((string) $expense->amount), 422, 'Liquidation amount cannot exceed the verified expense.');
                $alreadyLiquidated = DB::table('cash_advance_liquidation_lines')->join('cash_advance_liquidations', 'cash_advance_liquidations.id', '=', 'cash_advance_liquidation_lines.liquidation_id')->where('cash_advance_liquidation_lines.expense_id', $expense->id)->whereIn('cash_advance_liquidations.status', ['submitted', 'under_review', 'verified'])->sum('cash_advance_liquidation_lines.eligible_amount');
                abort_if(BigDecimal::of((string) $alreadyLiquidated)->plus($amount)->isGreaterThan((string) $expense->amount), 422, 'This expense has already been liquidated against another advance.');
                $liquidation->lines()->create(['expense_id' => $expense->id, 'eligible_amount' => (string) $amount]);
                $sum = $sum->plus($amount);
            }
            $liquidation->update(['submitted_expense_total' => (string) $sum->toScale(2)]);
            $audit->record('cash_advances.liquidation_draft_created', $advance, ['liquidation_reference' => $liquidation->reference, 'submitted_total' => $liquidation->submitted_expense_total]);

            return $liquidation->load('lines');
        });

        return response()->json(['data' => $liquidation], 201);
    }

    public function submitLiquidation(Request $request, CashAdvanceLiquidation $liquidation, AuditLogger $audit)
    {
        $advance = $liquidation->cashAdvance;
        abort_unless($request->user()->can('cash-advances.liquidate') && $advance->requester_id === $request->user()->id && $liquidation->status === 'draft', 403);
        DB::transaction(function () use ($liquidation): void {
            CashAdvance::whereKey($liquidation->cash_advance_id)->lockForUpdate()->firstOrFail();
            foreach ($liquidation->lines as $line) {
                $expense = ActualExpense::whereKey($line->expense_id)->lockForUpdate()->firstOrFail();
                $reservedElsewhere = DB::table('cash_advance_liquidation_lines')->join('cash_advance_liquidations', 'cash_advance_liquidations.id', '=', 'cash_advance_liquidation_lines.liquidation_id')->where('cash_advance_liquidation_lines.expense_id', $expense->id)->where('cash_advance_liquidations.id', '!=', $liquidation->id)->whereIn('cash_advance_liquidations.status', ['submitted', 'under_review', 'verified'])->sum('cash_advance_liquidation_lines.eligible_amount');
                abort_if(BigDecimal::of((string) $reservedElsewhere)->plus((string) $line->eligible_amount)->isGreaterThan((string) $expense->amount), 422, 'This expense is already reserved against another advance.');
            }
            $liquidation->update(['status' => 'submitted', 'submitted_at' => now()]);
        });
        $audit->record('cash_advances.liquidation_submitted', $advance, ['liquidation_reference' => $liquidation->reference, 'total' => $liquidation->submitted_expense_total]);

        return response()->json(['data' => $liquidation->refresh()]);
    }

    public function verifyLiquidation(Request $request, CashAdvanceLiquidation $liquidation, AuditLogger $audit)
    {
        $advance = $liquidation->cashAdvance;
        abort_unless($request->user()->can('cash-advances.liquidation.verify') && $advance->requester_id !== $request->user()->id && $liquidation->status === 'submitted', 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'correction_requested', 'rejected'])], 'reason' => 'required|string|min:3|max:2000']);
        if ($data['decision'] !== 'verified') {
            $liquidation->update(['status' => $data['decision'], 'reviewed_by' => $request->user()->id, 'review_remarks' => $data['reason']]);
            $audit->record('cash_advances.liquidation_'.$data['decision'], $advance, ['liquidation_reference' => $liquidation->reference, 'reason' => $data['reason']]);

            return response()->json(['data' => $liquidation->refresh()]);
        }
        $result = DB::transaction(function () use ($request, $liquidation, $advance, $data): array {
            $lockedAdvance = CashAdvance::whereKey($advance->id)->lockForUpdate()->firstOrFail();
            $before = $this->advanceBalance($lockedAdvance);
            $lineSum = BigDecimal::zero();
            foreach ($liquidation->lines as $line) {
                $expense = ActualExpense::whereKey($line->expense_id)->lockForUpdate()->firstOrFail();
                abort_unless($expense->status === 'verified', 409, 'Every liquidation line must still be a verified actual expense.');
                $line->update(['verified_amount' => $line->eligible_amount, 'status' => 'verified']);
                $lineSum = $lineSum->plus((string) $line->eligible_amount);
            }
            $available = $before['outstanding'];
            $reimbursement = $lineSum->minus($available);
            if ($reimbursement->isNegative()) {
                $reimbursement = BigDecimal::zero();
            }
            $liquidation->update(['status' => 'verified', 'verified_expense_total' => (string) $lineSum->toScale(2), 'reimbursement_due' => (string) $reimbursement->toScale(2), 'reviewed_by' => $request->user()->id, 'review_remarks' => $data['reason'], 'verified_at' => now()]);
            $after = $this->advanceBalance($lockedAdvance);
            $lockedAdvance->update(['status' => $after['outstanding']->isZero() ? 'fully_liquidated' : 'partially_liquidated', 'lock_version' => $lockedAdvance->lock_version + 1]);

            return ['advance' => $lockedAdvance->refresh(), 'outstanding' => (string) $after['outstanding']->toScale(2), 'reimbursement_due' => (string) $reimbursement->toScale(2)];
        });
        $audit->record('cash_advances.liquidation_verified', $advance, ['liquidation_reference' => $liquidation->reference, 'verified_total' => $liquidation->fresh()->verified_expense_total, 'outstanding' => $result['outstanding'], 'reimbursement_due' => $result['reimbursement_due'], 'reason' => $data['reason']]);

        return response()->json(['data' => $liquidation->refresh(), ...$result]);
    }

    public function recordReturn(Request $request, CashAdvance $advance, AuditLogger $audit)
    {
        abort_unless($this->visibleAdvances($request)->whereKey($advance->id)->exists() && $request->user()->can('cash-advances.liquidate') && $advance->requester_id === $request->user()->id, 403);
        $data = $request->validate(['reference' => 'required|string|max:100', 'amount' => 'required|numeric|min:0.01', 'returned_on' => 'required|date']);
        $this->assertAmountWithinAdvanceBalance($advance, BigDecimal::of((string) $data['amount']));
        $row = $advance->returns()->create([...$data, 'currency' => $advance->currency, 'recorded_by' => $request->user()->id, 'status' => 'pending_verification']);
        $audit->record('cash_advances.return_recorded', $advance, ['return_reference' => $row->reference, 'amount' => $row->amount]);

        return response()->json(['data' => $row], 201);
    }

    public function verifyReturn(Request $request, CashAdvanceReturn $return, AuditLogger $audit)
    {
        $advance = $return->cashAdvance;
        abort_unless($request->user()->can('cash-advances.returns.verify') && $return->recorded_by !== $request->user()->id && $advance->requester_id !== $request->user()->id, 403);
        abort_unless($return->status === 'pending_verification', 409);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected'])], 'reason' => 'required|string|min:3|max:2000']);
        if ($data['decision'] === 'verified') {
            $this->assertAmountWithinAdvanceBalance($advance, BigDecimal::of((string) $return->amount));
        }
        $return->update(['status' => $data['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        $balance = $this->advanceBalance($advance->refresh());
        if ($data['decision'] === 'verified' && $balance['outstanding']->isZero()) {
            $advance->update(['status' => 'fully_liquidated']);
        }
        $audit->record('cash_advances.return_'.$data['decision'], $advance, ['return_reference' => $return->reference, 'amount' => $return->amount, 'reason' => $data['reason']]);

        return response()->json(['data' => $return->refresh(), 'balance' => $this->formatAdvanceBalance($balance)]);
    }

    public function budgetUtilization(Request $request)
    {
        abort_unless($request->user()->can('budgets.utilization.view'), 403);

        return response()->json(['data' => $this->budgetUtilizationData($request), 'policy' => 'Budget consumption equals verified actual expenses plus outstanding disbursed advances plus approved undisbursed advance reservations. Liquidated expense and advance balance changes offset; cash disbursements are not posted as expenses. Values are grouped by currency. Open commitments are not present in the source schema and are excluded.']);
    }

    public function variances(Request $request)
    {
        abort_unless($request->user()->can('expenses.variance.view'), 403);
        $shipments = $this->visibleShipments($request)->with(['quotation.estimate.lines', 'baseline'])->whereHas('quotation')->orderByDesc('shipments.id')->paginate(20);
        $rows = $shipments->getCollection()->flatMap(fn (Shipment $shipment) => collect($this->shipmentVariance($shipment->id)['currency_rows'])->map(fn (array $row) => ['shipment_id' => $shipment->id, 'job_number' => $shipment->job_number, ...$row]));

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $shipments->currentPage(), 'last_page' => $shipments->lastPage(), 'total' => $shipments->total()], 'currency_policy' => 'Never aggregate across currencies.']);
    }

    public function exceptions(Request $request)
    {
        abort_unless($request->user()->can('expenses.exceptions.view'), 403);
        $query = ExpenseException::query()->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->where(function ($q) use ($request): void {
            $q->whereHas('expense', fn ($e) => $e->whereIn('shipment_id', $this->visibleShipments($request)->select('id')))
                ->orWhereHas('cashAdvance', fn ($a) => $a->whereIn('id', $this->visibleAdvances($request)->select('id')));
        })->with(['expense:id,reference,shipment_id,department,amount,currency', 'cashAdvance:id,reference,shipment_id']);

        return $query->latest()->paginate(20);
    }

    public function resolveException(Request $request, ExpenseException $exception, AuditLogger $audit)
    {
        abort_unless($request->user()->can('expenses.exceptions.approve'), 403);
        abort_unless($exception->status === 'open', 409);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved_exception', 'rejected_request', 'resolved'])], 'reason' => 'required|string|min:5|max:2000']);
        $exception->update(['status' => $data['decision'], 'reviewed_by' => $request->user()->id, 'resolution' => $data['reason'], 'resolved_at' => now()]);
        $audit->record('expenses.exception_'.$data['decision'], $exception, ['type' => $exception->type, 'reason' => $data['reason']]);

        return response()->json(['data' => $exception->refresh()]);
    }

    private function visibleExpenses(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->can('expenses.view'), 403);
        if ($user->hasAnyRole(['Accounting', 'Finance'])) {
            return ActualExpense::query();
        }
        $departments = array_values(array_intersect(self::DEPARTMENTS, $user->getRoleNames()->all()));
        if (! $departments) {
            return ActualExpense::query()->where('created_by', $user->id);
        }

        return ActualExpense::query()->where(function ($q) use ($departments, $user): void {
            $q->where(fn ($x) => $x->whereIn('department', $departments)->whereIn('shipment_id', DB::table('shipment_department_assignments')->select('shipment_id')->whereIn('department', $departments)->where(fn ($a) => $a->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id))))
                ->orWhere('created_by', $user->id);
        });
    }

    private function visibleAdvances(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->can('cash-advances.view') || $user->can('cash-advances.liquidate')), 403);
        if ($user->hasAnyRole(['Accounting', 'Finance'])) {
            return CashAdvance::query();
        }

        return CashAdvance::query()->where('requester_id', $user->id);
    }

    private function visibleShipments(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->can('expenses.view') || $user->can('cash-advances.view') || $user->can('budgets.utilization.view')), 403);
        $departments = array_values(array_intersect(self::DEPARTMENTS, $user->getRoleNames()->all()));
        if ($user->hasAnyRole(['Accounting', 'Finance'])) {
            return Shipment::query();
        }

        return Shipment::query()->whereHas('assignments', fn ($q) => $q->whereIn('department', $departments)->where(fn ($a) => $a->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id)));
    }

    private function authorizedShipment(Request $request, int $shipmentId, string $department): Shipment
    {
        $user = $request->user();
        abort_unless(in_array($department, self::DEPARTMENTS, true) && $user->hasRole($department), 403);
        $shipment = Shipment::whereKey($shipmentId)->whereNull('archived_at')->firstOrFail();
        abort_unless($shipment->assignments()->where('department', $department)->where(fn ($q) => $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', $user->id))->exists(), 403, 'This shipment is not assigned to your department scope.');

        return $shipment;
    }

    private function assertExpenseVisible(Request $request, ActualExpense $expense): void
    {
        abort_unless($this->visibleExpenses($request)->whereKey($expense->id)->exists(), 404);
    }

    private function department(Request $request): ?string
    {
        foreach (self::DEPARTMENTS as $department) {
            if ($request->user()->hasRole($department)) {
                return $department;
            }
        }

        return null;
    }

    private function validateRelatedRecords(Shipment $shipment, array $data): void
    {
        foreach (['transport_trip_id' => 'trips', 'forwarding_booking_id' => 'bookings', 'container_id' => 'containers'] as $field => $relation) {
            if (! empty($data[$field])) {
                abort_unless($shipment->{$relation}()->whereKey($data[$field])->exists(), 422, 'Related operational record must belong to this shipment.');
            }
        }
    }

    private function validateFinancialLinks(Shipment $shipment, array $data, ExpenseCategory $category): void
    {
        if (! empty($data['cost_estimate_line_id'])) {
            $line = DB::table('cost_estimate_lines')->join('cost_estimates', 'cost_estimates.id', '=', 'cost_estimate_lines.cost_estimate_id')->where('cost_estimate_lines.id', $data['cost_estimate_line_id'])->select('cost_estimate_lines.category', 'cost_estimate_lines.currency', 'cost_estimate_lines.cost_estimate_id', 'cost_estimates.status', 'cost_estimates.currency as estimate_currency')->first();
            abort_unless($line && $line->status === 'approved' && $line->category === $category->estimate_category && $line->cost_estimate_id === $shipment->quotation?->cost_estimate_id && $line->currency === $line->estimate_currency && $line->estimate_currency === $data['currency'], 422, 'Estimate line must belong to this shipment baseline and match the actual currency; no FX basis is configured.');
        }
        if (! empty($data['budget_allocation_id'])) {
            $allocation = DB::table('budget_allocations')->join('budget_lines', 'budget_lines.id', '=', 'budget_allocations.budget_line_id')->join('monthly_budget_plans', 'monthly_budget_plans.id', '=', 'budget_allocations.budget_plan_id')->where('budget_allocations.id', $data['budget_allocation_id'])->select('budget_allocations.currency', 'budget_lines.category', 'monthly_budget_plans.status')->first();
            abort_unless($allocation && $allocation->status === 'approved' && $allocation->category === $category->budget_category && $allocation->currency === $data['currency'], 422, 'Budget allocation must be approved and match the selected category and currency.');
        }
    }

    private function nextReference(string $table, string $prefix): string
    {
        $year = now()->year;
        DB::table($table)->insertOrIgnore(['year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table($table)->where('year', $year)->lockForUpdate()->first();
        $next = $row->last_value + 1;
        DB::table($table)->where('year', $year)->update(['last_value' => $next, 'updated_at' => now()]);

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }

    private function event(ActualExpense $expense, string $action, ?int $actorId, ?array $before, ?array $after, ?string $reason = null): void
    {
        ExpenseEvent::create(['expense_id' => $expense->id, 'actor_id' => $actorId, 'action' => $action, 'before_values' => $before, 'after_values' => $after, 'reason' => $reason, 'occurred_at' => now()]);
    }

    private function raiseException(ActualExpense $expense, string $type, string $severity, string $description): void
    {
        $key = 'expense:'.$expense->id.':'.$type;
        ExpenseException::firstOrCreate(['dedupe_key' => $key], ['expense_id' => $expense->id, 'type' => $type, 'severity' => $severity, 'status' => 'open', 'description' => $description]);
    }

    private function detectExpenseExceptions(ActualExpense $expense): void
    {
        $duplicates = ActualExpense::where('id', '!=', $expense->id)->whereNotNull('vendor')->where('vendor', $expense->vendor)->where('amount', $expense->amount)->where('currency', $expense->currency)->whereDate('incurred_on', $expense->incurred_on)->where('status', '!=', 'draft')->exists();
        if ($duplicates) {
            $this->raiseException($expense, 'duplicate_invoice_candidate', 'normal', 'Another expense has the same vendor, amount, currency and incurrence date; review before treating it as a duplicate.');
        }
        if ($expense->receipt_status !== 'complete') {
            $this->raiseException($expense, 'missing_or_unverified_receipt', 'normal', 'Expense support is missing or pending verification.');
        }
        if (! $expense->budget_allocation_id) {
            $this->raiseException($expense, 'unfunded_expense', 'normal', 'No approved budget allocation is linked.');
        }
        if ($expense->recoverability === 'client_rejected' || $expense->client_charge_status === 'rejected') {
            $this->raiseException($expense, 'client_rejected_nonrecoverable', 'high', 'Client rejected the additional charge; preserve the actual cost and exclude it from client-billable totals.');
        }
        $variance = $this->shipmentVariance($expense->shipment_id);
        if ($variance['over_estimate']) {
            $this->raiseException($expense, 'actual_exceeds_estimate', 'normal', 'Verified actual expenses exceed the approved estimated baseline in this currency.');
        }
    }

    private function advanceBalance(CashAdvance $advance): array
    {
        $disbursed = BigDecimal::of((string) $advance->disbursements()->where('status', 'verified')->sum('amount'));
        $liquidated = BigDecimal::of((string) DB::table('cash_advance_liquidation_lines')->join('cash_advance_liquidations', 'cash_advance_liquidations.id', '=', 'cash_advance_liquidation_lines.liquidation_id')->where('cash_advance_liquidations.cash_advance_id', $advance->id)->where('cash_advance_liquidations.status', 'verified')->sum('cash_advance_liquidation_lines.verified_amount'));
        $returned = BigDecimal::of((string) $advance->returns()->where('status', 'verified')->sum('amount'));
        $outstanding = $disbursed->minus($liquidated)->minus($returned);
        if ($outstanding->isNegative()) {
            $outstanding = BigDecimal::zero();
        }

        return ['disbursed' => $disbursed, 'liquidated' => $liquidated, 'returned' => $returned, 'outstanding' => $outstanding];
    }

    private function formatAdvanceBalance(array $balance): array
    {
        return array_map(fn ($key) => (string) $balance[$key]->toScale(2, RoundingMode::HalfUp), ['disbursed', 'liquidated', 'returned', 'outstanding']);
    }

    private function assertAmountWithinAdvanceBalance(CashAdvance $advance, BigDecimal $amount): void
    {
        $balance = $this->advanceBalance($advance);
        abort_if($amount->isGreaterThan($balance['outstanding']), 422, 'The amount exceeds the remaining disbursed advance.');
    }

    private function assertAllocationCapacity(int $allocationId, BigDecimal $requested, string $currency, ?int $excludeAdvance = null): void
    {
        $allocation = DB::table('budget_allocations')->where('id', $allocationId)->first();
        abort_unless($allocation && $allocation->currency === $currency, 422);
        $actual = ActualExpense::where('budget_allocation_id', $allocationId)->where('status', 'verified')->sum('amount');
        $reserved = CashAdvance::where('budget_allocation_id', $allocationId)->when($excludeAdvance, fn ($q) => $q->where('id', '!=', $excludeAdvance))->whereIn('status', ['submitted', 'under_review', 'disbursement_pending', 'disbursed', 'partially_liquidated'])->get()->reduce(function (BigDecimal $sum, CashAdvance $advance): BigDecimal {
            if (in_array($advance->status, ['disbursed', 'partially_liquidated'], true)) {
                return $sum->plus((string) $this->advanceBalance($advance)['outstanding']);
            }

            return $sum->plus((string) ($advance->approved_amount ?? $advance->requested_amount));
        }, BigDecimal::zero());
        $available = BigDecimal::of((string) $allocation->amount)->minus((string) $actual)->minus($reserved);
        abort_if($available->isNegative() || $requested->isGreaterThan($available), 422, 'This request exceeds the remaining approved allocation. No funding exception policy is configured.');
    }

    private function budgetUtilizationData(Request $request): array
    {
        $plans = DB::table('monthly_budget_plans')->where('status', 'approved')->when($request->filled('month'), fn ($q) => $q->where('budget_month', $request->date('month')))->get();
        $out = [];
        foreach ($plans as $plan) {
            $lines = DB::table('budget_lines')->where('budget_plan_id', $plan->id)->get();
            foreach ($lines as $line) {
                $allocations = DB::table('budget_allocations')->where('budget_line_id', $line->id)->get();
                foreach ($allocations as $allocation) {
                    $actual = ActualExpense::where('budget_allocation_id', $allocation->id)->where('status', 'verified')->get()->reduce(fn (BigDecimal $sum, ActualExpense $expense) => $sum->plus((string) $expense->amount)->plus((string) $expense->adjustments()->where('status', 'verified')->where('adjustment_type', 'adjustment')->sum('amount'))->minus((string) $expense->adjustments()->where('status', 'verified')->where('adjustment_type', 'reversal')->sum('amount')), BigDecimal::zero());
                    $advanceReservations = CashAdvance::where('budget_allocation_id', $allocation->id)->whereIn('status', ['submitted', 'under_review', 'disbursement_pending'])->get()->reduce(fn (BigDecimal $sum, CashAdvance $a) => $sum->plus((string) ($a->approved_amount ?? $a->requested_amount)), BigDecimal::zero());
                    $advanceOutstanding = CashAdvance::where('budget_allocation_id', $allocation->id)->whereIn('status', ['disbursed', 'partially_liquidated'])->get()->reduce(fn (BigDecimal $sum, CashAdvance $a) => $sum->plus((string) $this->advanceBalance($a)['outstanding']), BigDecimal::zero());
                    $actualAmount = $actual;
                    $consumed = $actualAmount->plus($advanceReservations)->plus($advanceOutstanding);
                    $budget = BigDecimal::of((string) $allocation->amount);
                    $remaining = $budget->minus($consumed);
                    $key = $plan->currency;
                    $out[] = ['allocation_id' => $allocation->id, 'reference' => $allocation->reference, 'plan_reference' => $plan->reference, 'department' => $plan->department, 'category' => $line->category, 'approved_budget' => (string) BigDecimal::of((string) $line->approved_amount)->toScale(2), 'allocated_budget' => (string) $budget->toScale(2), 'actual_total' => (string) $actualAmount->toScale(2), 'advance_exposure' => (string) $advanceReservations->plus($advanceOutstanding)->toScale(2), 'open_commitments' => null, 'remaining_budget' => (string) ($remaining->isNegative() ? BigDecimal::zero() : $remaining)->toScale(2), 'over_budget_exposure' => (string) ($remaining->isNegative() ? $remaining->abs() : BigDecimal::zero())->toScale(2), 'currency' => $plan->currency];
                }
            }
        }

        return $out;
    }

    private function shipmentVariance(int $shipmentId): array
    {
        $shipment = Shipment::with('quotation.estimate.lines')->findOrFail($shipmentId);
        $estimate = $shipment->quotation?->estimate;
        $estimated = [];
        if ($estimate && $estimate->status === 'approved') {
            foreach ($estimate->lines as $line) {
                $estimated[$estimate->currency] = ($estimated[$estimate->currency] ?? BigDecimal::zero())->plus((string) $line->estimate_currency_total);
            }
        }
        $actualRows = ActualExpense::where('shipment_id', $shipmentId)->where('status', 'verified')->with('adjustments')->get()->groupBy('currency');
        $actual = $actualRows->mapWithKeys(fn ($expenses, $currency) => [$currency => $expenses->reduce(fn (BigDecimal $sum, ActualExpense $expense) => $sum->plus((string) $expense->amount)->plus((string) $expense->adjustments->where('status', 'verified')->where('adjustment_type', 'adjustment')->sum('amount'))->minus((string) $expense->adjustments->where('status', 'verified')->where('adjustment_type', 'reversal')->sum('amount')), BigDecimal::zero())])->all();
        $currencies = array_values(array_unique([...array_keys($estimated), ...array_keys($actual)]));
        $rows = [];
        $over = false;
        foreach ($currencies as $currency) {
            $estimateAmount = $estimated[$currency] ?? null;
            $actualAmount = $actual[$currency] ?? BigDecimal::zero();
            $variance = $estimateAmount ? $actualAmount->minus($estimateAmount) : null;
            $over = $over || ($variance?->isPositive() ?? false);
            $rows[] = ['currency' => $currency, 'approved_estimate' => $estimateAmount ? (string) $estimateAmount->toScale(2) : null, 'actual_incurred' => (string) $actualAmount->toScale(2), 'variance' => $variance ? (string) $variance->toScale(2) : null, 'variance_percent' => ! $estimateAmount || $estimateAmount->isZero() ? null : (string) $variance->multipliedBy('100')->dividedBy($estimateAmount, 2, RoundingMode::HalfUp), 'open_commitments' => null];
        }

        return ['shipment_id' => $shipmentId, 'job_number' => $shipment->job_number, 'client' => $shipment->customer_name, 'currency_rows' => $rows, 'over_estimate' => $over, 'commitments' => 'Not integrated'];
    }
}
