<?php

namespace App\Http\Controllers;

use App\Models\ActualExpense;
use App\Models\Shipment;
use App\Services\AuditLogger;
use App\Services\DocumentScanService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdditionalChargeController extends Controller
{
    private const DEPARTMENTS = ['Operations', 'Transport', 'Forwarding'];

    private const CATEGORIES = ['shipping_line', 'port_storage', 'demurrage', 'detention', 'extra_trucking', 'additional_delivery', 'fuel_surcharge', 'customs', 'inspection', 'rebooking', 'warehousing', 'handling', 'documentation', 'waiting_time', 'other'];

    public function page(): View
    {
        return view('app', ['page' => 'additional-charges']);
    }

    public function index(Request $request)
    {
        $this->requireView($request);
        $query = DB::table('additional_charge_requests as r')->join('shipments as s', 's.id', '=', 'r.shipment_id')->join('clients as c', 'c.id', '=', 'r.client_id')
            ->select('r.*', 's.job_number', 'c.company_name')->orderByDesc('r.id');
        $this->scope($request, $query, 'r');
        if ($request->user()->hasRole('Billing')) {
            $query->where('r.client_status', 'approved')->whereNotNull('r.approved_amount');
        }
        $rows = $query->paginate(20)->withQueryString();
        $rows->getCollection()->transform(fn ($row) => $this->serialize($row));

        return response()->json(['data' => $rows]);
    }

    public function dashboard(Request $request)
    {
        $this->requireView($request);
        $query = DB::table('additional_charge_requests as r');
        $this->scope($request, $query, 'r');
        $all = (clone $query)->get();

        return response()->json(['data' => [
            'total' => $all->count(), 'internal_pending' => $all->whereIn('internal_status', ['submitted', 'under_review'])->count(),
            'client_pending' => $all->where('client_status', 'pending')->count(), 'approved' => $all->where('client_status', 'approved')->count(),
            'partial' => $all->where('client_status', 'partially_approved')->count(), 'rejected' => $all->where('client_status', 'rejected')->count(),
            'approved_value_by_currency' => $all->whereIn('client_status', ['approved', 'partially_approved'])->groupBy('currency')->map(fn ($rows) => number_format((float) $rows->sum('approved_amount'), 2, '.', '')),
            'missing_evidence' => $all->filter(fn ($row) => ! DB::table('additional_charge_evidence')->where('request_id', $row->id)->exists())->count(),
        ]]);
    }

    public function options(Request $request)
    {
        abort_unless($request->user()->can('additional-charges.create'), 403);
        $departments = array_values(array_intersect(self::DEPARTMENTS, $request->user()->getRoleNames()->all()));
        $shipments = Shipment::query()->whereNull('archived_at')->whereHas('assignments', fn ($q) => $q->whereIn('department', $departments)->where(fn ($a) => $a->whereNull('assigned_user_id')->orWhere('assigned_user_id', $request->user()->id)))->with('client:id,company_name')->orderByDesc('id')->limit(500)->get(['id', 'job_number', 'reference', 'client_id', 'commercial_baseline_id', 'quotation_id']);

        return response()->json(['data' => ['shipments' => $shipments, 'departments' => $departments, 'categories' => self::CATEGORIES, 'expenses' => ActualExpense::whereIn('shipment_id', $shipments->pluck('id'))->whereIn('department', $departments)->where('status', 'verified')->whereNotIn('id', DB::table('additional_charge_expense_links')->select('expense_id'))->get(['id', 'shipment_id', 'reference', 'department', 'description', 'amount', 'currency'])]]);
    }

    public function show(Request $request, int $id)
    {
        $this->requireView($request);
        $query = DB::table('additional_charge_requests as r')->join('shipments as s', 's.id', '=', 'r.shipment_id')->join('clients as c', 'c.id', '=', 'r.client_id')->where('r.id', $id)->select('r.*', 's.job_number', 'c.company_name');
        $this->scope($request, $query, 'r');
        $row = $query->firstOrFail();
        $data = $this->serialize($row);
        $data['expenses'] = DB::table('additional_charge_expense_links as l')->join('actual_expenses as e', 'e.id', '=', 'l.expense_id')->where('l.request_id', $id)->select('e.id', 'e.reference', 'e.description', 'e.amount', 'e.currency', 'l.allocated_cost')->get();
        $data['evidence'] = DB::table('additional_charge_evidence')->where('request_id', $id)->orderBy('id')->get(['id', 'document_type', 'original_name', 'mime_type', 'size_bytes', 'verification_status', 'created_at']);
        $data['history'] = DB::table('additional_charge_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')->where('e.request_id', $id)->orderBy('e.id')->get(['e.event', 'e.before_state', 'e.after_state', 'e.reason', 'e.created_at', 'u.name as actor']);
        $data['authorizations'] = DB::table('commercial_change_authorizations')->where('request_id', $id)->orderBy('authorization_version')->get();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('additional-charges.create'), 403);
        $data = $request->validate([
            'shipment_id' => ['required', 'integer', 'exists:shipments,id'], 'department' => ['required', Rule::in(self::DEPARTMENTS)],
            'category' => ['required', Rule::in(self::CATEGORIES)], 'description' => 'required|string|max:220', 'justification' => 'required|string|max:4000',
            'actual_cost' => 'required|numeric|min:0.01|max:99999999999999.99', 'proposed_amount' => 'required|numeric|min:0.01|max:99999999999999.99',
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'tax_treatment' => ['nullable', Rule::in(['none', 'inclusive', 'exclusive', 'withholding'])],
            'notes' => 'nullable|string|max:2000', 'expense_ids' => 'nullable|array|max:30', 'expense_ids.*' => 'integer|distinct|exists:actual_expenses,id',
        ]);
        abort_unless($request->user()->hasRole($data['department']), 403, 'The request department must be one of your assigned operating roles.');
        $shipment = $this->assignedShipment($request, (int) $data['shipment_id'], $data['department']);
        abort_unless((int) $shipment->client_id > 0, 422, 'Shipment must have a client.');
        $expenses = collect();
        if (! empty($data['expense_ids'])) {
            $expenses = ActualExpense::whereIn('id', $data['expense_ids'])->where('shipment_id', $shipment->id)->where('department', $data['department'])->where('status', 'verified')->get();
            abort_unless($expenses->count() === count($data['expense_ids']), 422, 'Linked expenses must be verified costs from this shipment and department.');
            abort_unless($expenses->every(fn ($expense) => $expense->currency === $data['currency']), 422, 'Linked expenses must use the request currency; foreign exchange allocation is not configured.');
            $sumCents = $expenses->reduce(fn (int $sum, $expense) => $sum + $this->cents((string) $expense->amount), 0);
            abort_if($sumCents !== $this->cents($data['actual_cost']), 422, 'Actual cost must equal the linked verified expense total.');
        }
        $baseline = $shipment->commercial_baseline_id ? DB::table('commercial_baselines')->where('id', $shipment->commercial_baseline_id)->first() : null;
        $id = DB::transaction(function () use ($request, $shipment, $baseline, $data, $expenses): int {
            foreach ($expenses as $expense) {
                ActualExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
                $alreadyLinked = DB::table('additional_charge_expense_links as l')->join('additional_charge_requests as r', 'r.id', '=', 'l.request_id')->where('l.expense_id', $expense->id)->where('r.internal_status', '!=', 'internally_rejected')->whereNotIn('r.client_status', ['rejected', 'withdrawn'])->exists();
                abort_if($alreadyLinked, 409, 'An expense cannot be recovered through more than one open or authorized charge request.');
            }
            $year = now()->year;
            DB::table('additional_charge_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequenceRow = DB::table('additional_charge_sequences')->where('year', $year)->lockForUpdate()->first();
            $sequence = (int) $sequenceRow->last_value + 1;
            DB::table('additional_charge_sequences')->where('year', $year)->update(['last_value' => $sequence, 'updated_at' => now()]);
            $ref = sprintf('ACR-%04d-%06d', $year, $sequence);
            $id = DB::table('additional_charge_requests')->insertGetId([
                'reference' => $ref, 'shipment_id' => $shipment->id, 'client_id' => $shipment->client_id, 'department' => $data['department'], 'requester_id' => $request->user()->id,
                'quotation_id' => $shipment->quotation_id, 'commercial_baseline_id' => $baseline?->id, 'baseline_version' => $baseline?->quotation_version,
                'category' => $data['category'], 'description' => $data['description'], 'justification' => $data['justification'], 'actual_cost' => $data['actual_cost'], 'proposed_amount' => $data['proposed_amount'],
                'currency' => $data['currency'], 'tax_treatment' => $data['tax_treatment'] ?? 'none', 'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($expenses as $expense) {
                DB::table('additional_charge_expense_links')->insert(['request_id' => $id, 'expense_id' => $expense->id, 'allocated_cost' => $expense->amount, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->event($id, $request->user()->id, 'created', null, ['reference' => $ref, 'shipment_id' => $shipment->id, 'actual_cost' => $data['actual_cost'], 'proposed_amount' => $data['proposed_amount']]);

            return $id;
        });
        $audit->record('additional_charges.created', null, ['request_id' => $id]);

        return $this->show($request, $id)->setStatusCode(201);
    }

    public function submit(Request $request, int $id)
    {
        abort_unless($request->user()->can('additional-charges.submit'), 403);

        return $this->mutate($request, $id, function ($row) use ($request): array {
            abort_unless((int) $row->requester_id === (int) $request->user()->id || $request->user()->hasRole('Administrator'), 403);
            abort_unless($row->internal_status === 'draft' || $row->internal_status === 'revision_requested', 409);
            $evidence = DB::table('additional_charge_evidence')->where('request_id', $row->id)->where('scan_status', 'clean')->exists();
            abort_unless($evidence, 422, 'Attach evidence before submitting the charge for review.');

            return ['internal_status' => 'submitted', 'submitted_at' => now()];
        }, 'submitted', 'Submitted for internal commercial review');
    }

    public function update(Request $request, int $id)
    {
        abort_unless($request->user()->can('additional-charges.update'), 403);
        $data = $request->validate(['category' => ['sometimes', Rule::in(self::CATEGORIES)], 'description' => 'sometimes|required|string|max:220', 'justification' => 'sometimes|required|string|max:4000', 'actual_cost' => 'sometimes|required|numeric|min:0.01|max:99999999999999.99', 'proposed_amount' => 'sometimes|required|numeric|min:0.01|max:99999999999999.99', 'notes' => 'nullable|string|max:2000']);

        return $this->mutate($request, $id, function ($row) use ($request, $data): array {
            abort_unless((int) $row->requester_id === (int) $request->user()->id, 403);
            abort_unless(in_array($row->internal_status, ['draft', 'revision_requested'], true), 409, 'Only drafts and requests returned for revision can be edited.');
            $linked = DB::table('additional_charge_expense_links')->where('request_id', $row->id)->sum('allocated_cost');
            abort_if(DB::table('additional_charge_expense_links')->where('request_id', $row->id)->exists() && isset($data['actual_cost']) && $this->cents($data['actual_cost']) !== $this->cents((string) $linked), 422, 'Actual cost must continue to match the linked verified expenses.');

            return $data;
        }, 'revised', 'Draft or returned request details revised');
    }

    public function review(Request $request, int $id)
    {
        abort_unless($request->user()->can('additional-charges.internal-review'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'request_revision'])], 'reason' => 'required|string|max:2000']);
        abort_if(in_array('Administrator', $request->user()->getRoleNames()->all(), true) && $request->user()->hasAnyRole(self::DEPARTMENTS), 403, 'Administrative access alone is not a commercial review role.');

        return $this->mutate($request, $id, function ($row) use ($request, $data): array {
            abort_unless(in_array($row->internal_status, ['submitted', 'under_review'], true), 409);
            abort_if((int) $row->requester_id === (int) $request->user()->id, 403, 'Requesters cannot approve their own requests.');
            $status = ['approve' => 'internally_approved', 'reject' => 'internally_rejected', 'request_revision' => 'revision_requested'][$data['decision']];

            return ['internal_status' => $status, 'client_status' => $data['decision'] === 'approve' ? 'awaiting_request' : 'not_requested'];
        }, 'internal_'.$data['decision'], $data['reason']);
    }

    public function prepareClientRequest(Request $request, int $id)
    {
        abort_unless($request->user()->can('additional-charges.client-request.prepare'), 403);
        $data = $request->validate(['follow_up_on' => 'nullable|date|after_or_equal:today', 'notes' => 'nullable|string|max:2000']);

        return $this->mutate($request, $id, function ($row) use ($data): array {
            abort_unless($row->internal_status === 'internally_approved' && $row->client_status === 'awaiting_request', 409);

            return ['client_status' => 'pending', 'client_requested_at' => now(), 'follow_up_on' => $data['follow_up_on'] ?? null, 'notes' => $data['notes'] ?? $row->notes];
        }, 'client_request_prepared', 'Client approval request prepared for manual sending', fn ($row) => $this->assertSalesClientAccess($request, (int) $row->client_id));
    }

    public function recordDecision(Request $request, int $id)
    {
        abort_unless($request->user()->can('additional-charges.client-response.record'), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'partially_approved', 'rejected', 'no_response'])], 'approved_amount' => 'nullable|numeric|min:0|max:99999999999999.99',
            'representative' => 'nullable|string|max:180', 'remarks' => 'required|string|max:2000', 'responded_at' => 'nullable|date',
        ]);
        abort_unless($request->user()->can('commercial-changes.authorize'), 403, 'A separate commercial authorization permission is required.');

        return $this->mutate($request, $id, function ($row) use ($data): array {
            abort_unless($row->internal_status === 'internally_approved' && $row->client_status === 'pending', 409);
            $amount = $this->cents((string) ($data['approved_amount'] ?? '0'));
            $proposed = $this->cents((string) $row->proposed_amount);
            abort_if($data['decision'] === 'approved' && $amount !== $proposed, 422, 'Approved amount must match the requested amount; use partial approval for a different amount.');
            abort_if($data['decision'] === 'partially_approved' && ($amount <= 0 || $amount >= $proposed), 422, 'Partial approval must be positive and lower than the proposed amount.');
            abort_if(in_array($data['decision'], ['rejected', 'no_response'], true) && $amount !== 0, 422);
            $approved = $data['decision'] === 'no_response' ? 'pending' : $data['decision'];
            $class = match ($approved) {
                'approved' => 'approved_additional_charge', 'partially_approved' => 'partially_recoverable', 'rejected' => 'company_absorbed', default => 'pending_client_decision'
            };

            return ['client_status' => $approved, 'approved_amount' => $amount ? number_format($amount / 100, 2, '.', '') : null, 'client_representative' => $data['representative'] ?? null, 'client_responded_at' => $data['decision'] === 'no_response' ? null : ($data['responded_at'] ?? now()), 'classification' => $class, 'classification_reason' => $data['remarks']];
        }, 'client_'.$data['decision'], $data['remarks'], function ($row) use ($request, $data): void {
            $this->assertSalesClientAccess($request, (int) $row->client_id);
            if (! in_array($data['decision'], ['approved', 'partially_approved'], true)) {
                return;
            }
            $evidence = DB::table('additional_charge_evidence')->where('request_id', $row->id)->whereIn('document_type', ['email_approval', 'client_letter', 'signed_decision'])->where('scan_status', 'clean')->where('verification_status', 'verified')->orderByDesc('id')->first();
            abort_unless($evidence, 422, 'Verified client decision evidence is required before a charge can be approved.');
        });
    }

    public function verifyEvidence(Request $request, int $id, int $evidenceId, AuditLogger $audit)
    {
        abort_unless($request->user()->can('additional-charges.client-response.verify'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['verified', 'rejected'])], 'reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($request, $id, $evidenceId, $data, $audit): void {
            $row = DB::table('additional_charge_requests')->where('id', $id)->lockForUpdate()->firstOrFail();
            $evidence = DB::table('additional_charge_evidence')->where('id', $evidenceId)->where('request_id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($evidence->scan_status === 'clean', 423, 'Evidence cannot be verified before a clean malware scan.');
            abort_if((int) $evidence->uploaded_by === (int) $request->user()->id && $data['decision'] === 'verified', 403, 'Evidence uploader cannot verify their own evidence.');
            DB::table('additional_charge_evidence')->where('id', $evidenceId)->update(['verification_status' => $data['decision'], 'verified_by' => $request->user()->id, 'verified_at' => now(), 'updated_at' => now()]);
            $this->event($id, $request->user()->id, 'evidence_'.$data['decision'], ['evidence_id' => $evidenceId, 'status' => $evidence->verification_status], ['status' => $data['decision']], $data['reason']);
            $audit->record('additional_charges.evidence_'.$data['decision'], null, ['request_id' => $id, 'evidence_id' => $evidenceId]);
        });

        return $this->show($request, $id);
    }

    public function upload(Request $request, int $id, AuditLogger $audit, DocumentScanService $scan)
    {
        abort_unless($request->user()->can('additional-charges.create') || $request->user()->can('additional-charges.client-response.record'), 403);
        $data = $request->validate(['file' => 'required|file|max:15360|mimes:pdf,png,jpg,jpeg,doc,docx,xls,xlsx', 'document_type' => ['required', Rule::in(['receipt', 'carrier_invoice', 'vendor_document', 'email_approval', 'client_letter', 'signed_decision', 'incident_report', 'other'])]]);
        $scope = DB::table('additional_charge_requests')->where('id', $id);
        $this->scope($request, $scope);
        $row = $scope->firstOrFail();
        $stored = $scan->store($data['file'], 'additional-charge-evidence/'.$id);
        $evidenceId = DB::table('additional_charge_evidence')->insertGetId(['request_id' => $id, 'document_type' => $data['document_type'], 'original_name' => mb_substr($data['file']->getClientOriginalName(), 0, 255), 'disk_path' => $stored['path'], 'mime_type' => $data['file']->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $data['file']->getSize(), 'scan_status' => $stored['scan_status'], 'scan_result' => $stored['scan_result'], 'scanned_at' => $stored['scanned_at'], 'uploaded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->event($id, $request->user()->id, 'evidence_uploaded', null, ['evidence_id' => $evidenceId, 'document_type' => $data['document_type']]);
        $audit->record('additional_charges.evidence_uploaded', null, ['request_id' => $id, 'evidence_id' => $evidenceId]);

        return $this->show($request, $id)->setStatusCode(201);
    }

    public function download(Request $request, int $evidenceId): StreamedResponse
    {
        abort_unless($request->user()->can('additional-charges.view'), 403);
        $query = DB::table('additional_charge_evidence as e')->join('additional_charge_requests as r', 'r.id', '=', 'e.request_id')->where('e.id', $evidenceId)->select('e.*', 'r.shipment_id', 'r.requester_id');
        $this->scope($request, $query, 'r');
        $evidence = $query->firstOrFail();
        app(DocumentScanService::class)->assertTrusted($evidence->scan_status);

        return Storage::disk('local')->download($evidence->disk_path, basename($evidence->original_name));
    }

    public function billingEligibility(Request $request)
    {
        abort_unless($request->user()->can('commercial-changes.billing-eligibility.view'), 403);
        $rows = DB::table('commercial_change_authorizations as a')->join('additional_charge_requests as r', 'r.id', '=', 'a.request_id')->join('shipments as s', 's.id', '=', 'a.shipment_id')->join('clients as c', 'c.id', '=', 'r.client_id')->select('a.id', 'a.request_id', 'r.reference', 's.job_number', 'c.company_name', 'a.approved_amount', 'a.currency', 'a.effective_at', 'a.authorization_version')->orderByDesc('a.id')->paginate(20);
        $rows->getCollection()->transform(function ($row): array {
            $data = (array) $row;
            $data['approved_amount'] = number_format((float) $row->approved_amount, 2, '.', '');

            return $data;
        });

        return response()->json(['data' => $rows]);
    }

    public function classify(Request $request, int $id, AuditLogger $audit)
    {
        abort_unless($request->user()->can('expense-recoverability.classify'), 403);
        $data = $request->validate(['classification' => ['required', Rule::in(['included_in_original_quotation', 'contractually_nonbillable', 'under_investigation', 'company_absorbed'])], 'reason' => 'required|string|max:2000']);
        abort_if(in_array($data['classification'], ['company_absorbed', 'included_in_original_quotation'], true) && ! $request->user()->can('additional-charges.client-response.verify'), 403);
        $result = $this->mutate($request, $id, function ($row) use ($data): array {
            if ($data['classification'] === 'company_absorbed') {
                abort_unless(in_array($row->client_status, ['rejected', 'partially_approved'], true), 409, 'Classify only an actually unrecovered amount after a client decision.');
            }

            return ['classification' => $data['classification'], 'classification_reason' => $data['reason'], 'classified_by' => request()->user()->id];
        }, 'loss_classified', $data['reason']);
        $audit->record('additional_charges.loss_classified', null, ['request_id' => $id, 'classification' => $data['classification']]);

        return $result;
    }

    public function profitImpact(Request $request, int $shipmentId)
    {
        abort_unless($request->user()->can('profit-impact.view'), 403);
        $shipment = Shipment::with(['quotation.estimate'])->findOrFail($shipmentId);
        $requestRows = DB::table('additional_charge_requests')->where('shipment_id', $shipmentId)->get();
        $currency = $shipment->quotation?->currency ?? $shipment->currency;
        $baseRevenue = $currency ? $this->cents((string) ($shipment->quotation?->total ?? '0')) : null;
        $baseCost = $currency ? $this->cents((string) ($shipment->quotation?->estimate?->estimated_cost_total ?? '0')) : null;
        $approved = $requestRows->whereIn('client_status', ['approved', 'partially_approved'])->where('currency', $currency)->sum(fn ($row) => $this->cents((string) ($row->approved_amount ?? '0')));
        $pending = $requestRows->whereIn('client_status', ['pending', 'awaiting_request'])->where('currency', $currency)->sum(fn ($row) => $this->cents((string) $row->proposed_amount));
        $actual = ActualExpense::where('shipment_id', $shipmentId)->where('status', 'verified')->where('currency', $currency)->get(['amount'])->sum(fn ($expense) => $this->cents((string) $expense->amount));
        $projectedRevenue = $baseRevenue === null ? null : $baseRevenue + $approved;
        $projectedCost = $baseCost === null ? null : max($baseCost, $actual);
        $projectedProfit = $projectedRevenue !== null && $projectedCost !== null ? $projectedRevenue - $projectedCost : null;

        return response()->json(['data' => ['currency' => $currency, 'original_approved_selling_value' => $baseRevenue === null ? null : $this->money($baseRevenue), 'original_estimated_cost' => $baseCost === null ? null : $this->money($baseCost), 'original_estimated_gross_profit' => $baseRevenue !== null && $baseCost !== null ? $this->money($baseRevenue - $baseCost) : null, 'actual_incurred_cost_to_date' => $this->money($actual), 'approved_additional_charges' => $this->money($approved), 'pending_proposed_charges_excluded' => $this->money($pending), 'revised_projected_gross_profit' => $projectedProfit === null ? null : $this->money($projectedProfit), 'projected_gross_margin_percent' => $projectedRevenue > 0 && $projectedProfit !== null ? number_format(($projectedProfit / $projectedRevenue) * 100, 2, '.', '') : null, 'basis_note' => $currency ? 'One currency only; estimates and verified actuals are compared only when currencies match. Pending charges are excluded from revenue.' : 'No consistent quotation currency is available; no combined projection is shown.']]);
    }

    private function mutate(Request $request, int $id, callable $change, string $event, string $reason, ?callable $beforeCommit = null)
    {
        return DB::transaction(function () use ($request, $id, $change, $event, $reason, $beforeCommit) {
            $row = DB::table('additional_charge_requests')->where('id', $id)->lockForUpdate()->firstOrFail();
            $this->scope($request, DB::table('additional_charge_requests')->where('id', $id));
            if ($beforeCommit) {
                $beforeCommit($row);
            }
            $before = collect((array) $row)->only(['internal_status', 'client_status', 'approved_amount', 'classification', 'classification_reason', 'lock_version'])->all();
            $changes = $change($row);
            $changes['lock_version'] = $row->lock_version + 1;
            $changes['updated_at'] = now();
            DB::table('additional_charge_requests')->where('id', $id)->update($changes);
            $after = array_merge($before, collect($changes)->except('updated_at')->all());
            $this->event($id, $request->user()->id, $event, $before, $after, $reason);

            if (in_array($changes['client_status'] ?? '', ['approved', 'partially_approved'], true)) {
                $fresh = DB::table('additional_charge_requests')->where('id', $id)->first();
                $evidence = DB::table('additional_charge_evidence')->where('request_id', $id)->where('scan_status', 'clean')->where('verification_status', 'verified')->orderByDesc('id')->firstOrFail();
                $version = (int) DB::table('commercial_change_authorizations')->where('request_id', $id)->max('authorization_version') + 1;
                DB::table('commercial_change_authorizations')->insert(['request_id' => $id, 'shipment_id' => $fresh->shipment_id, 'quotation_id' => $fresh->quotation_id, 'commercial_baseline_id' => $fresh->commercial_baseline_id, 'baseline_version' => $fresh->baseline_version, 'authorization_version' => $version, 'approved_amount' => $fresh->approved_amount, 'currency' => $fresh->currency, 'client_representative' => $fresh->client_representative, 'evidence_id' => $evidence->id, 'authorized_by' => $request->user()->id, 'effective_at' => $fresh->client_responded_at ?? now(), 'snapshot' => json_encode(['reference' => $fresh->reference, 'proposed_amount' => $fresh->proposed_amount, 'approved_amount' => $fresh->approved_amount, 'currency' => $fresh->currency, 'baseline_version' => $fresh->baseline_version, 'client_representative' => $fresh->client_representative]), 'created_at' => now(), 'updated_at' => now()]);
                $this->event($id, $request->user()->id, 'commercial_change_authorized', null, ['version' => $version, 'approved_amount' => $fresh->approved_amount, 'currency' => $fresh->currency], 'Billing eligibility created');
            }

            return $this->show($request, $id);
        });
    }

    private function requireView(Request $request): void
    {
        abort_unless($request->user()->can('additional-charges.view') || $request->user()->can('commercial-changes.billing-eligibility.view'), 403);
    }

    private function scope(Request $request, $query, ?string $alias = null): void
    {
        $user = $request->user();
        if ($user->can('commercial-changes.billing-eligibility.view') && ! $user->can('additional-charges.view')) {
            $query->where(($alias ? $alias.'.' : '').'client_status', 'approved')->whereNotNull(($alias ? $alias.'.' : '').'approved_amount');

            return;
        }
        if ($user->hasAnyRole(self::DEPARTMENTS) && ! $user->hasAnyRole(['Sales', 'Accounting', 'Finance', 'Executive', 'Administrator'])) {
            $query->where(($alias ? $alias.'.' : '').'requester_id', $user->id);
        }
        if ($user->hasRole('Sales') && ! $user->hasAnyRole(['Accounting', 'Finance', 'Executive', 'Administrator'])) {
            $query->whereIn(($alias ? $alias.'.' : '').'client_id', DB::table('clients')->select('id')->where('sales_owner_id', $user->id));
        }
    }

    private function assignedShipment(Request $request, int $id, string $department): Shipment
    {
        $shipment = Shipment::whereKey($id)->whereNull('archived_at')->firstOrFail();
        abort_unless($shipment->assignments()->where('department', $department)->where(fn ($q) => $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', $request->user()->id))->exists(), 403, 'Shipment is not assigned to this department scope.');

        return $shipment;
    }

    private function serialize(object $row): array
    {
        $data = (array) $row;
        foreach (['actual_cost', 'proposed_amount', 'approved_amount'] as $field) {
            if ($data[$field] !== null) {
                $data[$field] = number_format((float) $data[$field], 2, '.', '');
            }
        }
        $data['billing_eligible'] = DB::table('commercial_change_authorizations')->where('request_id', $row->id)->exists();

        return $data;
    }

    private function event(int $id, ?int $actor, string $event, ?array $before, ?array $after, ?string $reason = null): void
    {
        DB::table('additional_charge_events')->insert(['request_id' => $id, 'actor_id' => $actor, 'event' => $event, 'before_state' => $before ? json_encode($before) : null, 'after_state' => $after ? json_encode($after) : null, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cents(string $amount): int
    {
        return BigDecimal::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    private function assertSalesClientAccess(Request $request, int $clientId): void
    {
        $user = $request->user();
        if ($user->hasRole('Sales') && ! $user->hasAnyRole(['Accounting', 'Finance', 'Executive', 'Administrator'])) {
            abort_unless(DB::table('clients')->where('id', $clientId)->where('sales_owner_id', $user->id)->exists(), 404);
        }
    }
}
