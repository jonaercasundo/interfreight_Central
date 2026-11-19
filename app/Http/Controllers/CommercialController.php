<?php

namespace App\Http\Controllers;

use App\Models\ClientInquiry;
use App\Models\CommercialBaseline;
use App\Models\CostEstimate;
use App\Models\Quotation;
use App\Models\QuotationApprovalStep;
use App\Models\QuotationClientResponse;
use App\Services\AuditLogger;
use App\Services\CommercialCalculator;
use App\Services\DocumentReferenceGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommercialController extends Controller
{
    public function page(string $page)
    {
        if ($page === 'finance-approval-queue') {
            abort_unless(request()->user()->can('quotations.review') || request()->user()->can('quotations.approve'), 403);
        }

        return view('app', ['page' => $page]);
    }

    public function estimates(Request $request)
    {
        abort_unless($request->user()->can('cost-estimates.view'), 403);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', 'string', 'max:24'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->estimateScope(CostEstimate::query())->with(['inquiry.client:id,company_name', 'forecast:id,status', 'owner:id,name'])->withCount('lines');
        $query->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $inner) => $inner->where('reference', 'like', '%'.$search.'%')->orWhereHas('inquiry', fn (Builder $inquiry) => $inquiry->where('reference', 'like', '%'.$search.'%')->orWhereHas('client', fn (Builder $client) => $client->where('company_name', 'like', '%'.$search.'%')))));
        $query->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status));

        return $query->latest()->paginate(15);
    }

    public function showEstimate(Request $request, CostEstimate $estimate)
    {
        abort_unless($request->user()->can('cost-estimates.view'), 403);
        $this->assertEstimateOwner($request, $estimate);

        return response()->json(['data' => $estimate->load(['inquiry.client:id,company_name,reference', 'forecast', 'owner:id,name', 'creator:id,name', 'lines'])->append('available_for_quotation')]);
    }

    public function storeEstimate(Request $request, CommercialCalculator $calculator, DocumentReferenceGenerator $references, AuditLogger $audit)
    {
        abort_unless($request->user()->can('cost-estimates.create'), 403);
        $data = $this->estimateData($request);
        $inquiry = ClientInquiry::with('client')->findOrFail($data['inquiry_id']);
        $this->assertInquiryOwner($request, $inquiry);
        $forecast = isset($data['forecast_id']) ? $inquiry->forecasts()->whereKey($data['forecast_id'])->firstOrFail() : null;

        $estimate = DB::transaction(function () use ($request, $data, $inquiry, $forecast, $calculator, $references): CostEstimate {
            ClientInquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $version = (int) CostEstimate::where('inquiry_id', $inquiry->id)->max('version') + 1;
            $estimate = CostEstimate::create([
                'reference' => $references->next('EST'), 'inquiry_id' => $inquiry->id, 'forecast_id' => $forecast?->id,
                'sales_owner_id' => $inquiry->sales_owner_id, 'version' => $version, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'shipment_classification' => $inquiry->service_type, 'transport_mode' => $inquiry->transport_mode, 'origin' => $inquiry->origin,
                'destination' => $inquiry->destination, 'planned_window' => $forecast?->forecast_month ?? $inquiry->requested_ship_date,
                'currency' => $data['currency'], 'assumptions' => $data['assumptions'] ?? null,
            ]);
            $this->replaceEstimateLines($estimate, $data['lines'], $calculator);

            return $estimate->load('lines');
        });
        $audit->record('cost_estimates.created', $estimate, ['reference' => $estimate->reference, 'version' => $estimate->version, 'line_count' => $estimate->lines->count()]);

        return response()->json(['data' => $estimate], 201);
    }

    public function updateEstimate(Request $request, CostEstimate $estimate, CommercialCalculator $calculator, AuditLogger $audit)
    {
        abort_unless($request->user()->can('cost-estimates.update'), 403);
        $this->assertEstimateOwner($request, $estimate);
        $data = $this->estimateData($request, true);
        $beforeStatus = $estimate->status;
        $beforeFinancials = ['total' => $estimate->estimated_cost_total, 'funding_required' => $estimate->funding_required_total, 'lines' => $estimate->load('lines')->lines->map(fn ($line) => ['category' => $line->category, 'description' => $line->description, 'amount' => $line->estimate_currency_total])->all()];
        DB::transaction(function () use ($request, $estimate, $data, $calculator): void {
            $locked = CostEstimate::whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== 'draft' && $locked->status !== 'revision_requested', 409, 'Only draft estimates can be changed. Create a new revision instead.');
            abort_if(isset($data['lock_version']) && (int) $data['lock_version'] !== $locked->lock_version, 409, 'This estimate changed in another session. Reload before editing.');
            $locked->fill(['currency' => $data['currency'], 'assumptions' => $data['assumptions'] ?? null, 'updated_by' => $request->user()->id, 'lock_version' => $locked->lock_version + 1])->save();
            $this->replaceEstimateLines($locked, $data['lines'], $calculator);
        });
        $updated = $estimate->fresh()->load('lines');
        $audit->record('cost_estimates.updated', $updated, ['reference' => $updated->reference, 'version' => $updated->version, 'previous_status' => $beforeStatus, 'line_count' => count($data['lines']), 'financial_details' => ['before' => $beforeFinancials, 'after' => ['total' => $updated->estimated_cost_total, 'funding_required' => $updated->funding_required_total, 'lines' => $updated->lines->map(fn ($line) => ['category' => $line->category, 'description' => $line->description, 'amount' => $line->estimate_currency_total])->all()]]]);

        return $this->showEstimate($request, $estimate->fresh());
    }

    public function submitEstimate(Request $request, CostEstimate $estimate, AuditLogger $audit)
    {
        abort_unless($request->user()->can('cost-estimates.submit'), 403);
        $this->assertEstimateOwner($request, $estimate);
        DB::transaction(function () use ($request, $estimate): void {
            $locked = CostEstimate::with('lines')->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['draft', 'revision_requested'], true), 409, 'Only a draft or revision-requested estimate can be submitted.');
            abort_if($locked->lines->isEmpty(), 422, 'Add at least one cost line before submitting this estimate.');
            $locked->update(['status' => 'submitted', 'updated_by' => $request->user()->id, 'lock_version' => $locked->lock_version + 1]);
        });
        $audit->record('cost_estimates.submitted', $estimate, ['reference' => $estimate->reference, 'version' => $estimate->version]);

        return response()->json(['data' => $estimate->fresh(), 'message' => 'Cost estimate submitted for Accounting review.']);
    }

    public function reviewEstimate(Request $request, CostEstimate $estimate, AuditLogger $audit)
    {
        abort_unless($request->user()->can('cost-estimates.review'), 403);
        abort_unless($request->user()->hasRole('Accounting') || $request->user()->hasRole('Administrator'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'revision_requested', 'rejected'])], 'remarks' => ['required', 'string', 'min:3', 'max:2000']]);
        DB::transaction(function () use ($request, $estimate, $data): void {
            $locked = CostEstimate::whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'submitted', 409, 'Only submitted estimates can be reviewed.');
            $locked->update(['status' => $data['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_remarks' => $data['remarks'], 'lock_version' => $locked->lock_version + 1]);
        });
        $audit->record('cost_estimates.reviewed', $estimate, ['reference' => $estimate->reference, 'decision' => $data['decision'], 'reason' => $data['remarks']]);

        return response()->json(['data' => $estimate->fresh(), 'message' => 'Cost estimate review recorded.']);
    }

    public function reviseEstimate(Request $request, CostEstimate $estimate, DocumentReferenceGenerator $references, AuditLogger $audit)
    {
        abort_unless($request->user()->can('cost-estimates.create'), 403);
        $this->assertEstimateOwner($request, $estimate);
        abort_unless(in_array($estimate->status, ['approved', 'rejected'], true), 409, 'Only a formally reviewed estimate can be duplicated as a new revision.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $revision = DB::transaction(function () use ($request, $estimate, $references): CostEstimate {
            ClientInquiry::whereKey($estimate->inquiry_id)->lockForUpdate()->firstOrFail();
            $version = (int) CostEstimate::where('inquiry_id', $estimate->inquiry_id)->max('version') + 1;
            $revision = $estimate->load('lines')->replicate(['id', 'reference', 'version', 'parent_estimate_id', 'status', 'reviewed_by', 'reviewed_at', 'review_remarks', 'lock_version', 'created_at', 'updated_at']);
            $revision->reference = $references->next('EST');
            $revision->version = $version;
            $revision->parent_estimate_id = $estimate->id;
            $revision->revision_reason = $data['reason'];
            $revision->status = 'draft';
            $revision->created_by = $request->user()->id;
            $revision->updated_by = $request->user()->id;
            $revision->lock_version = 1;
            $revision->save();
            foreach ($estimate->lines as $line) {
                $revision->lines()->create($line->only(['category', 'description', 'supplier', 'quantity', 'unit', 'unit_cost', 'currency', 'exchange_rate_to_estimate', 'exchange_rate_source', 'exchange_rate_date', 'tax_treatment', 'tax_rate', 'subtotal', 'tax_amount', 'line_total', 'estimate_currency_total', 'cost_type', 'cost_payer', 'recoverability', 'funding_required', 'notes']));
            }

            return $revision->load('lines');
        });
        $audit->record('cost_estimates.revised', $revision, ['reference' => $revision->reference, 'parent_reference' => $estimate->reference, 'version' => $revision->version, 'reason' => $data['reason']]);

        return response()->json(['data' => $revision], 201);
    }

    public function quotations(Request $request)
    {
        abort_unless($request->user()->can('quotations.view'), 403);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', 'string', 'max:24'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->quotationScope(Quotation::query())->with(['client:id,company_name', 'inquiry:id,reference', 'owner:id,name', 'approvals:id,quotation_id,actor_id,stage,action'])->withCount('lines');
        $query->when($filters['search'] ?? null, fn (Builder $q, $search) => $q->where(fn (Builder $inner) => $inner->where('reference', 'like', '%'.$search.'%')->orWhereHas('client', fn (Builder $client) => $client->where('company_name', 'like', '%'.$search.'%'))));
        $query->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status));

        $quotes = $query->latest()->paginate(15);
        $quotes->getCollection()->transform(function (Quotation $quote) use ($request): Quotation {
            $completedStages = $quote->approvals->where('action', 'approved')->pluck('stage')->all();
            $hasApprovedAllRequiredStages = collect(config('ifc_finance.required_quotation_reviews', []))->every(fn ($stage) => in_array($stage, $completedStages, true));
            $reviewerConflict = config('ifc_finance.prohibit_self_approval') && $quote->approvals->contains(fn ($step) => $step->actor_id === $request->user()->id && $step->action === 'approved');
            $quote->setAttribute('can_finalize', $quote->status === 'submitted' && $request->user()->can('quotations.approve') && $hasApprovedAllRequiredStages && ! $reviewerConflict);
            $quote->unsetRelation('approvals');
            if (! $request->user()->can('cost-estimates.view')) {
                $quote->makeHidden(['cost_snapshot']);
            }
            if (! $request->user()->can('quotations.update')) {
                $quote->makeHidden(['internal_notes']);
            }

            return $quote;
        });

        return $quotes;
    }

    public function showQuotation(Request $request, Quotation $quotation, CommercialCalculator $calculator)
    {
        abort_unless($request->user()->can('quotations.view'), 403);
        $this->assertQuotationOwner($request, $quotation);
        $quotation->load(['client:id,company_name,contact_name,email,billing_address', 'inquiry:id,reference,service_type,transport_mode', 'estimate', 'owner:id,name', 'lines', 'approvals.actor:id,name', 'responses']);
        $data = $quotation->toArray();
        unset($data['cost_snapshot']);
        if (! $request->user()->can('quotations.update')) {
            unset($data['internal_notes']);
        }
        if ($request->user()->can('margins.view')) {
            $data['margin_preview'] = $calculator->margin($quotation->estimate->estimated_cost_total, $quotation->total);
        } else {
            unset($data['cost_snapshot']);
            $data['estimate'] = null;
        }

        return response()->json(['data' => $data]);
    }

    public function storeQuotation(Request $request, CommercialCalculator $calculator, DocumentReferenceGenerator $references, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.create'), 403);
        $data = $this->quotationData($request);
        $estimate = CostEstimate::with(['inquiry.client', 'lines', 'forecast'])->findOrFail($data['cost_estimate_id']);
        abort_unless($estimate->status === 'approved', 422, 'An Accounting-approved cost estimate is required before creating a quotation.');
        abort_unless($estimate->forecast_id !== null && $estimate->forecast?->status === 'active', 422, 'A current shipment forecast must exist before the first quotation.');
        $this->assertEstimateOwner($request, $estimate);
        $quote = DB::transaction(function () use ($request, $data, $estimate, $calculator, $references): Quotation {
            ClientInquiry::whereKey($estimate->inquiry_id)->lockForUpdate()->firstOrFail();
            $version = (int) Quotation::where('inquiry_id', $estimate->inquiry_id)->max('version') + 1;
            $quote = Quotation::create($this->quoteAttributes($request, $data, $estimate, $references->next('QTN'), $version));
            $this->replaceQuotationLines($quote, $data['lines'], $calculator);
            $quote->update(['cost_snapshot' => $this->estimateSnapshot($estimate)]);

            return $quote->load('lines');
        });
        $audit->record('quotations.created', $quote, ['reference' => $quote->reference, 'version' => $quote->version, 'line_count' => $quote->lines->count()]);

        $response = $quote->toArray();
        unset($response['cost_snapshot']);

        return response()->json(['data' => $response], 201);
    }

    public function updateQuotation(Request $request, Quotation $quotation, CommercialCalculator $calculator, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.update'), 403);
        $this->assertQuotationOwner($request, $quotation);
        $data = $this->quotationData($request, true);
        $beforeStatus = $quotation->status;
        $beforeFinancials = ['total' => $quotation->total, 'lines' => $quotation->load('lines')->lines->map(fn ($line) => ['description' => $line->description, 'line_total' => $line->line_total])->all()];
        DB::transaction(function () use ($quotation, $data, $calculator): void {
            $locked = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['draft', 'revision_requested'], true), 409, 'Issued and approved quotations cannot be edited. Create a new version instead.');
            abort_if(isset($data['lock_version']) && (int) $data['lock_version'] !== $locked->lock_version, 409, 'This quotation changed in another session. Reload before editing.');
            abort_if(isset($data['cost_estimate_id']) && (int) $data['cost_estimate_id'] !== $locked->cost_estimate_id, 422, 'Change the cost estimate through a new commercial revision.');
            $locked->fill(collect($data)->except(['lines', 'lock_version', 'cost_estimate_id'])->all());
            $locked->lock_version++;
            $locked->save();
            $this->replaceQuotationLines($locked, $data['lines'], $calculator);
        });
        $updated = $quotation->fresh()->load('lines');
        $audit->record('quotations.updated', $updated, ['reference' => $updated->reference, 'version' => $updated->version, 'previous_status' => $beforeStatus, 'reason' => $data['change_reason'] ?? 'Draft edits', 'financial_details' => ['before' => $beforeFinancials, 'after' => ['total' => $updated->total, 'lines' => $updated->lines->map(fn ($line) => ['description' => $line->description, 'line_total' => $line->line_total])->all()]]]);

        return $this->showQuotation($request, $quotation->fresh(), $calculator);
    }

    public function submitQuotation(Request $request, Quotation $quotation, CommercialCalculator $calculator, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.submit'), 403);
        $this->assertQuotationOwner($request, $quotation);
        DB::transaction(function () use ($quotation, $calculator): void {
            $locked = Quotation::with('lines', 'estimate')->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['draft', 'revision_requested'], true), 409, 'Only a draft quotation can be submitted.');
            abort_if($locked->lines->isEmpty() || $calculator->moneyCents((string) $locked->total, 'total') <= 0, 422, 'Add selling lines with a positive total before submitting.');
            abort_unless($locked->estimate->status === 'approved', 409, 'The linked cost estimate is no longer approved.');
            $locked->update(['status' => 'submitted', 'submitted_at' => now(), 'lock_version' => $locked->lock_version + 1]);
        });
        $audit->record('quotations.submitted', $quotation, ['reference' => $quotation->reference, 'version' => $quotation->version]);

        return response()->json(['data' => $quotation->fresh(), 'message' => 'Quotation submitted for internal review.']);
    }

    public function reviewQuotation(Request $request, Quotation $quotation, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.review'), 403);
        $data = $request->validate(['stage' => ['required', Rule::in(['accounting', 'finance', 'executive'])], 'decision' => ['required', Rule::in(['approved', 'revision_requested', 'rejected'])], 'remarks' => ['required', 'string', 'min:3', 'max:2000']]);
        abort_unless($request->user()->hasRole(ucfirst($data['stage'])) || $request->user()->hasRole('Administrator'), 403);
        DB::transaction(function () use ($request, $quotation, $data): void {
            $locked = Quotation::with('approvals')->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'submitted', 409, 'Only submitted quotations can be reviewed.');
            abort_if($locked->approvals->contains(fn ($step) => $step->stage === $data['stage'] && $step->action === 'approved'), 409, 'This approval stage has already been completed.');
            QuotationApprovalStep::create(['quotation_id' => $locked->id, 'actor_id' => $request->user()->id, 'stage' => $data['stage'], 'action' => $data['decision'], 'remarks' => $data['remarks'], 'acted_at' => now()]);
            if ($data['decision'] !== 'approved') {
                $locked->update(['status' => $data['decision'], 'lock_version' => $locked->lock_version + 1]);
            }
        });
        $audit->record('quotations.reviewed', $quotation, ['reference' => $quotation->reference, 'stage' => $data['stage'], 'decision' => $data['decision'], 'reason' => $data['remarks']]);

        return response()->json(['data' => $quotation->fresh()->load('approvals.actor:id,name'), 'message' => 'Quotation review recorded.']);
    }

    public function approveQuotation(Request $request, Quotation $quotation, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.approve'), 403);
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $quotation, $data): void {
            $locked = Quotation::with('approvals')->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'submitted', 409, 'Only submitted quotations can be approved.');
            foreach (config('ifc_finance.required_quotation_reviews', []) as $stage) {
                abort_unless($locked->approvals->contains(fn ($step) => $step->stage === $stage && $step->action === 'approved'), 409, 'Required '.$stage.' review is not complete.');
            }
            if (config('ifc_finance.prohibit_self_approval')) {
                abort_if($locked->approvals->contains(fn ($step) => $step->actor_id === $request->user()->id && $step->action === 'approved'), 403, 'The final approver must be different from required reviewers.');
            }
            $locked->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'lock_version' => $locked->lock_version + 1]);
            QuotationApprovalStep::create(['quotation_id' => $locked->id, 'actor_id' => $request->user()->id, 'stage' => 'final', 'action' => 'approved', 'remarks' => $data['remarks'] ?? null, 'acted_at' => now()]);
        });
        $audit->record('quotations.approved', $quotation, ['reference' => $quotation->reference, 'version' => $quotation->version, 'reason' => $data['remarks'] ?? null]);

        return response()->json(['data' => $quotation->fresh(), 'message' => 'Quotation internally approved.']);
    }

    public function issueQuotation(Request $request, Quotation $quotation, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.issue'), 403);
        $this->assertQuotationOwner($request, $quotation);
        abort_if($quotation->valid_until->lt(today()), 409, 'This quotation has expired and cannot be issued.');
        DB::transaction(function () use ($quotation): void {
            $locked = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved', 409, 'Only internally approved quotations can be issued to a client.');
            abort_if($locked->valid_until->lt(today()), 409, 'This quotation has expired and cannot be issued.');
            $locked->update(['status' => 'issued', 'issued_at' => now(), 'lock_version' => $locked->lock_version + 1]);
        });
        $audit->record('quotations.issued', $quotation, ['reference' => $quotation->reference, 'version' => $quotation->version]);

        return response()->json(['data' => $quotation->fresh(), 'message' => 'Approved quotation issued to the client.']);
    }

    public function expireQuotation(Request $request, Quotation $quotation, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.issue'), 403);
        $this->assertQuotationOwner($request, $quotation);
        DB::transaction(function () use ($quotation): void {
            $locked = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'issued' && $locked->valid_until->lt(today()), 409, 'Only an issued quotation past its validity date can be marked expired.');
            $locked->update(['status' => 'expired', 'lock_version' => $locked->lock_version + 1]);
        });
        $audit->record('quotations.expired', $quotation, ['reference' => $quotation->reference, 'version' => $quotation->version]);

        return response()->json(['data' => $quotation->fresh(), 'message' => 'Quotation marked expired.']);
    }

    public function respondQuotation(Request $request, Quotation $quotation, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.client-response.record'), 403);
        $this->assertQuotationOwner($request, $quotation);
        $data = $request->validate([
            'response' => ['required', Rule::in(['accepted', 'rejected'])], 'client_representative' => ['required', 'string', 'max:120'],
            'quotation_version' => ['required', 'integer', 'min:1'], 'responded_at' => ['required', 'date'],
            'evidence_reference' => ['required_if:response,accepted', 'nullable', 'string', 'max:500'], 'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($request, $quotation, $data): void {
            $locked = Quotation::with('lines', 'estimate', 'approvals')->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'issued', 409, 'A client response can only be recorded for an issued quotation.');
            abort_unless($data['quotation_version'] === $locked->version, 409, 'The response must identify the currently issued quotation version.');
            abort_if(substr((string) $data['responded_at'], 0, 10) > $locked->valid_until->toDateString(), 422, 'The client response date is after the quotation validity period.');
            QuotationClientResponse::create([...$data, 'quotation_id' => $locked->id, 'recorded_by' => $request->user()->id]);
            $locked->update(['status' => $data['response'] === 'accepted' ? 'client_accepted' : 'client_rejected', 'lock_version' => $locked->lock_version + 1]);
            if ($data['response'] === 'accepted') {
                CommercialBaseline::where('inquiry_id', $locked->inquiry_id)->where('is_active', true)->update(['is_active' => false]);
                $locked->parent()->whereIn('status', ['client_accepted'])->update(['status' => 'superseded']);
                $snapshot = [
                    'quotation' => $locked->only(['reference', 'version', 'client_id', 'inquiry_id', 'service_scope', 'origin', 'destination', 'cargo_description', 'planned_window', 'currency', 'valid_until', 'terms', 'payment_terms', 'exclusions', 'assumptions', 'customer_remarks', 'subtotal', 'discount_total', 'tax_total', 'total']),
                    'selling_lines' => $locked->lines->map(fn ($line) => $line->only(['description', 'quantity', 'unit', 'unit_price', 'discount_type', 'discount_value', 'tax_treatment', 'tax_rate', 'subtotal', 'discount_amount', 'tax_amount', 'line_total']))->all(),
                    'cost_estimate' => $locked->cost_snapshot,
                    'approvals' => $locked->approvals->map(fn ($step) => $step->only(['stage', 'action', 'actor_id', 'acted_at', 'remarks']))->all(),
                    'acceptance' => ['representative' => $data['client_representative'], 'responded_at' => $data['responded_at'], 'evidence_reference' => $data['evidence_reference']],
                ];
                CommercialBaseline::create(['inquiry_id' => $locked->inquiry_id, 'quotation_id' => $locked->id, 'quotation_version' => $locked->version, 'snapshot' => $snapshot, 'accepted_at' => $data['responded_at'], 'accepted_by' => $request->user()->id, 'is_active' => true]);
            }
        });
        $audit->record('quotations.client_response', $quotation, ['reference' => $quotation->reference, 'version' => $quotation->version, 'response' => $data['response'], 'client_representative' => $data['client_representative'], 'evidence_reference' => $data['evidence_reference'] ?? null, 'reason' => $data['remarks'] ?? null]);
        if ($data['response'] === 'accepted') {
            $audit->record('commercial_baselines.created', $quotation, ['reference' => $quotation->reference, 'version' => $quotation->version]);
        }

        return response()->json(['data' => $quotation->fresh()->load('responses'), 'message' => 'Client response recorded.']);
    }

    public function reviseQuotation(Request $request, Quotation $quotation, DocumentReferenceGenerator $references, AuditLogger $audit)
    {
        abort_unless($request->user()->can('quotations.revise'), 403);
        $this->assertQuotationOwner($request, $quotation);
        $data = $request->validate(['cost_estimate_id' => ['required', 'integer', 'exists:cost_estimates,id'], 'reason' => ['required', 'string', 'min:3', 'max:2000']]);
        abort_unless(in_array($quotation->status, ['approved', 'issued', 'client_accepted', 'client_rejected', 'rejected', 'expired', 'superseded'], true), 409, 'Finish or revise the open quotation before creating another version.');
        $estimate = CostEstimate::with('lines')->findOrFail($data['cost_estimate_id']);
        abort_unless($estimate->inquiry_id === $quotation->inquiry_id && $estimate->status === 'approved', 422, 'A new revision requires an approved cost estimate for the same inquiry.');
        abort_unless($estimate->forecast_id !== null && $estimate->forecast()->where('status', 'active')->exists(), 422, 'A new quotation revision requires a current active forecast.');
        $revision = DB::transaction(function () use ($quotation, $estimate, $references, $data): Quotation {
            ClientInquiry::whereKey($quotation->inquiry_id)->lockForUpdate()->firstOrFail();
            $version = (int) Quotation::where('inquiry_id', $quotation->inquiry_id)->max('version') + 1;
            $old = $quotation->load('lines');
            $revision = $old->replicate(['id', 'reference', 'version', 'status', 'submitted_at', 'approved_at', 'approved_by', 'issued_at', 'lock_version', 'created_at', 'updated_at']);
            $revision->reference = $references->next('QTN');
            $revision->version = $version;
            $revision->parent_quotation_id = $quotation->id;
            $revision->revision_reason = $data['reason'];
            $revision->cost_estimate_id = $estimate->id;
            $revision->cost_snapshot = $this->estimateSnapshot($estimate);
            $revision->status = 'draft';
            $revision->submitted_at = null;
            $revision->approved_at = null;
            $revision->approved_by = null;
            $revision->issued_at = null;
            $revision->lock_version = 1;
            $revision->save();
            foreach ($old->lines as $line) {
                $revision->lines()->create($line->only(['description', 'quantity', 'unit', 'unit_price', 'discount_type', 'discount_value', 'tax_treatment', 'tax_rate', 'subtotal', 'discount_amount', 'tax_amount', 'line_total']));
            }

            return $revision->load('lines');
        });
        $audit->record('quotations.revised', $revision, ['reference' => $revision->reference, 'parent_reference' => $quotation->reference, 'version' => $revision->version, 'reason' => $data['reason']]);
        $response = $revision->toArray();
        unset($response['cost_snapshot']);

        return response()->json(['data' => $response], 201);
    }

    public function compareQuotation(Request $request, Quotation $quotation, CommercialCalculator $calculator)
    {
        abort_unless($request->user()->can('quotations.history.view'), 403);
        $this->assertQuotationOwner($request, $quotation);
        $quotation->load('lines', 'estimate', 'parent.lines', 'parent.estimate');
        $parent = $quotation->parent;
        $fields = ['service_scope', 'origin', 'destination', 'cargo_description', 'planned_window', 'terms', 'payment_terms', 'exclusions', 'assumptions', 'customer_remarks'];
        $changedTerms = $parent ? collect($fields)->filter(fn ($field) => $parent->{$field} != $quotation->{$field})->values()->all() : [];
        $previousMargin = $parent && $request->user()->can('margins.view') ? $calculator->margin($parent->estimate->estimated_cost_total, $parent->total) : null;
        $currentMargin = $request->user()->can('margins.view') ? $calculator->margin($quotation->estimate->estimated_cost_total, $quotation->total) : null;

        return response()->json(['data' => [
            'previous' => $parent ? ['reference' => $parent->reference, 'version' => $parent->version, 'total' => $parent->total, 'currency' => $parent->currency, 'status' => $parent->status, 'lines' => $parent->lines, 'margin' => $previousMargin] : null,
            'current' => ['reference' => $quotation->reference, 'version' => $quotation->version, 'total' => $quotation->total, 'currency' => $quotation->currency, 'status' => $quotation->status, 'lines' => $quotation->lines, 'margin' => $currentMargin],
            'difference' => $parent ? $calculator->cents($calculator->moneyCents((string) $quotation->total, 'new') - $calculator->moneyCents((string) $parent->total, 'old')) : null,
            'changed_terms' => $changedTerms,
        ]]);
    }

    public function margins(Request $request, CommercialCalculator $calculator)
    {
        abort_unless($request->user()->can('margins.view'), 403);
        $quotes = $this->quotationScope(Quotation::query())->with(['estimate', 'inquiry.client'])->whereIn('status', ['submitted', 'approved', 'issued', 'client_accepted', 'client_rejected'])->latest()->paginate(20);
        $quotes->getCollection()->transform(function (Quotation $quote) use ($calculator): array {
            return ['id' => $quote->id, 'reference' => $quote->reference, 'version' => $quote->version, 'status' => $quote->status, 'currency' => $quote->currency, 'total' => $quote->total, 'client' => $quote->inquiry?->client?->company_name, 'margin' => $calculator->margin($quote->estimate->estimated_cost_total, $quote->total)];
        });

        return $quotes;
    }

    public function marginPreview(Request $request, CommercialCalculator $calculator)
    {
        abort_unless($request->user()->can('margins.view'), 403);
        $data = $request->validate([
            'cost_estimate_id' => ['required', 'integer', 'exists:cost_estimates,id'],
            'target_margin_percent' => ['nullable', 'numeric', 'gte:0', 'lt:100', 'decimal:0,4'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:180'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,4'],
            'lines.*.unit' => ['required', 'string', 'max:32'], 'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'max:900000', 'decimal:0,4'],
            'lines.*.discount_type' => ['required', Rule::in(['none', 'percent', 'amount'])], 'lines.*.discount_value' => ['nullable', 'numeric', 'gte:0', 'max:100000000', 'decimal:0,4'],
            'lines.*.tax_treatment' => ['required', Rule::in(['none', 'exclusive', 'inclusive'])], 'lines.*.tax_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,4'],
        ]);
        $estimate = CostEstimate::findOrFail($data['cost_estimate_id']);
        $this->assertEstimateOwner($request, $estimate);
        abort_unless($estimate->status === 'approved', 409, 'The margin preview requires an approved cost estimate.');
        $totals = $calculator->quotationTotals($data['lines']);

        return response()->json(['data' => $calculator->margin((string) $estimate->estimated_cost_total, $totals['total'], $data['target_margin_percent'] ?? null)]);
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $summary = [];
        if ($user->can('quotations.view')) {
            $quotes = $this->quotationScope(Quotation::query());
            $summary['sales'] = [
                'draft' => (clone $quotes)->where('status', 'draft')->count(),
                'pending_approval' => (clone $quotes)->where('status', 'submitted')->count(),
                'approved' => (clone $quotes)->where('status', 'approved')->count(),
                'awaiting_client' => (clone $quotes)->where('status', 'issued')->count(),
                'accepted' => (clone $quotes)->where('status', 'client_accepted')->count(),
                'expiring' => (clone $quotes)->where('status', 'issued')->whereBetween('valid_until', [today(), today()->addDays(30)])->count(),
                'projected_offer_value' => (clone $quotes)->whereIn('status', ['submitted', 'approved', 'issued'])->selectRaw('currency, SUM(total) as total')->groupBy('currency')->get(),
                'accepted_commercial_value' => (clone $quotes)->where('status', 'client_accepted')->selectRaw('currency, SUM(total) as total')->groupBy('currency')->get(),
                'recent' => (clone $quotes)->with('client:id,company_name')->latest()->limit(8)->get(['id', 'reference', 'version', 'status', 'currency', 'total', 'client_id', 'created_at']),
            ];
        }
        if ($user->hasAnyRole(['Accounting', 'Finance', 'Administrator']) && $user->can('cost-estimates.view')) {
            $period = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', planned_window)" : "DATE_FORMAT(planned_window, '%Y-%m')";
            $summary['accounting'] = [
                'cost_demand' => CostEstimate::whereIn('status', ['submitted', 'approved'])->selectRaw("{$period} as period, currency, SUM(estimated_cost_total) as estimated_cost, COUNT(*) as estimates")->groupBy('period', 'currency')->orderBy('period')->get(),
                'waiting_for_review' => CostEstimate::where('status', 'submitted')->count(),
                'unestimated_forecasts' => DB::table('shipment_forecasts')->join('client_inquiries', 'client_inquiries.id', '=', 'shipment_forecasts.inquiry_id')->where('shipment_forecasts.status', 'active')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cost_estimates')->whereColumn('cost_estimates.inquiry_id', 'client_inquiries.id')->where('cost_estimates.status', 'approved'))->count(),
            ];
        }
        if ($user->hasAnyRole(['Finance', 'Executive', 'Administrator']) && $user->can('margins.view')) {
            $summary['finance'] = ['approval_queue' => $this->quotationScope(Quotation::query())->where('status', 'submitted')->count(), 'estimated_cost_exposure' => CostEstimate::whereIn('status', ['submitted', 'approved'])->selectRaw('currency, SUM(estimated_cost_total) as total')->groupBy('currency')->get()];
        }

        return response()->json(['data' => $summary]);
    }

    public function cashRequirements(Request $request)
    {
        abort_unless($request->user()->can('cost-estimates.cash.view'), 403);
        $periodExpression = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', planned_window)" : "DATE_FORMAT(planned_window, '%Y-%m')";

        return CostEstimate::query()->whereIn('status', ['submitted', 'approved'])
            ->selectRaw("{$periodExpression} as period, currency, SUM(funding_required_total) as funding_required, COUNT(*) as estimate_count")
            ->groupBy('period', 'currency')->orderBy('period')->get();
    }

    public function printQuotation(Request $request, Quotation $quotation)
    {
        abort_unless($request->user()->can('quotations.export'), 403);
        $this->assertQuotationOwner($request, $quotation);
        abort_unless(in_array($quotation->status, ['approved', 'issued', 'client_accepted', 'client_rejected', 'superseded'], true), 409, 'Only approved quotations can be printed for client review.');

        return view('commercial.quotation-print', ['quotation' => $quotation->load(['client', 'lines'])]);
    }

    private function estimateData(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'inquiry_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:client_inquiries,id'],
            'forecast_id' => ['nullable', 'integer', 'exists:shipment_forecasts,id'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'], 'assumptions' => ['nullable', 'string', 'max:4000'],
            'lock_version' => ['sometimes', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.category' => ['required', Rule::in(CommercialCalculator::COST_CATEGORIES)],
            'lines.*.description' => ['required', 'string', 'max:180'], 'lines.*.supplier' => ['nullable', 'string', 'max:180'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,4'], 'lines.*.unit' => ['required', 'string', 'max:32'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gte:0', 'max:900000', 'decimal:0,4'], 'lines.*.currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate_to_estimate' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'decimal:0,8'],
            'lines.*.exchange_rate_source' => ['nullable', 'string', 'max:120'], 'lines.*.exchange_rate_date' => ['nullable', 'date'],
            'lines.*.tax_treatment' => ['required', Rule::in(['none', 'exclusive', 'inclusive'])], 'lines.*.tax_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,4'],
            'lines.*.cost_type' => ['required', Rule::in(['direct', 'indirect'])], 'lines.*.cost_payer' => ['required', Rule::in(['company', 'client'])],
            'lines.*.recoverability' => ['required', Rule::in(['recoverable', 'nonrecoverable'])], 'lines.*.funding_required' => ['required', 'boolean'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function quotationData(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'cost_estimate_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:cost_estimates,id'],
            'client_contact' => ['nullable', 'string', 'max:120'], 'service_scope' => ['required', 'string', 'max:240'],
            'origin' => ['required', 'string', 'max:180'], 'destination' => ['required', 'string', 'max:180'],
            'cargo_description' => ['required', 'string', 'max:220'], 'planned_window' => ['nullable', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:today'], 'terms' => ['nullable', 'string', 'max:4000'],
            'payment_terms' => ['nullable', 'string', 'max:180'], 'exclusions' => ['nullable', 'string', 'max:4000'],
            'assumptions' => ['nullable', 'string', 'max:4000'], 'internal_notes' => ['nullable', 'string', 'max:4000'],
            'customer_remarks' => ['nullable', 'string', 'max:4000'], 'change_reason' => ['nullable', 'string', 'max:2000'],
            'lock_version' => ['sometimes', 'integer', 'min:1'], 'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:180'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,4'],
            'lines.*.unit' => ['required', 'string', 'max:32'], 'lines.*.unit_price' => ['required', 'numeric', 'gte:0', 'max:900000', 'decimal:0,4'],
            'lines.*.discount_type' => ['required', Rule::in(['none', 'percent', 'amount'])], 'lines.*.discount_value' => ['nullable', 'numeric', 'gte:0', 'max:100000000', 'decimal:0,4'],
            'lines.*.tax_treatment' => ['required', Rule::in(['none', 'exclusive', 'inclusive'])], 'lines.*.tax_rate' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,4'],
        ]);
    }

    private function replaceEstimateLines(CostEstimate $estimate, array $lines, CommercialCalculator $calculator): void
    {
        $estimate->lines()->delete();
        $calculated = array_map(fn (array $line) => $calculator->costLine($line, strtoupper($estimate->currency)), $lines);
        $totals = $calculator->summarizeCosts($calculated);
        foreach ($calculated as $line) {
            unset($line['_estimate_cents']);
            $estimate->lines()->create($line);
        }
        $estimate->fill($totals)->save();
    }

    private function replaceQuotationLines(Quotation $quotation, array $lines, CommercialCalculator $calculator): void
    {
        $quotation->lines()->delete();
        $totals = $calculator->quotationTotals($lines);
        foreach ($totals['lines'] as $line) {
            foreach (['_subtotal_cents', '_discount_cents', '_tax_cents', '_total_cents'] as $private) {
                unset($line[$private]);
            }
            $quotation->lines()->create($line);
        }
        $quotation->update(['subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount_total'], 'tax_total' => $totals['tax_total'], 'total' => $totals['total']]);
    }

    private function quoteAttributes(Request $request, array $data, CostEstimate $estimate, string $reference, int $version): array
    {
        return [
            'reference' => $reference, 'inquiry_id' => $estimate->inquiry_id, 'client_id' => $estimate->inquiry->client_id,
            'forecast_id' => $estimate->forecast_id, 'cost_estimate_id' => $estimate->id, 'cost_snapshot' => $this->estimateSnapshot($estimate),
            'sales_owner_id' => $estimate->sales_owner_id, 'version' => $version, 'client_contact' => $data['client_contact'] ?? $estimate->inquiry->client->contact_name,
            'service_scope' => $data['service_scope'], 'origin' => $data['origin'], 'destination' => $data['destination'],
            'cargo_description' => $data['cargo_description'], 'planned_window' => $data['planned_window'] ?? $estimate->planned_window,
            'currency' => $estimate->currency, 'valid_until' => $data['valid_until'], 'terms' => $data['terms'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null, 'exclusions' => $data['exclusions'] ?? null,
            'assumptions' => $data['assumptions'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null,
            'customer_remarks' => $data['customer_remarks'] ?? null,
        ];
    }

    private function estimateSnapshot(CostEstimate $estimate): array
    {
        return ['reference' => $estimate->reference, 'version' => $estimate->version, 'currency' => $estimate->currency, 'direct_cost_total' => $estimate->direct_cost_total, 'indirect_cost_total' => $estimate->indirect_cost_total, 'contingency_total' => $estimate->contingency_total, 'estimated_cost_total' => $estimate->estimated_cost_total, 'funding_required_total' => $estimate->funding_required_total, 'recoverable_total' => $estimate->recoverable_total, 'nonrecoverable_total' => $estimate->nonrecoverable_total, 'assumptions' => $estimate->assumptions, 'lines' => $estimate->lines->map(fn ($line) => $line->only(['category', 'description', 'supplier', 'quantity', 'unit', 'unit_cost', 'currency', 'exchange_rate_to_estimate', 'exchange_rate_source', 'exchange_rate_date', 'tax_treatment', 'tax_rate', 'subtotal', 'tax_amount', 'line_total', 'estimate_currency_total', 'cost_type', 'cost_payer', 'recoverability', 'funding_required', 'notes']))->all()];
    }

    private function estimateScope(Builder $query): Builder
    {
        $user = request()->user();

        return $user->hasRole(['Sales']) && ! $user->hasAnyRole(['Administrator', 'Accounting', 'Finance', 'Executive']) ? $query->where('sales_owner_id', $user->id) : $query;
    }

    private function quotationScope(Builder $query): Builder
    {
        $user = request()->user();

        return $user->hasRole(['Sales']) && ! $user->hasAnyRole(['Administrator', 'Accounting', 'Finance', 'Executive']) ? $query->where('sales_owner_id', $user->id) : $query;
    }

    private function assertEstimateOwner(Request $request, CostEstimate $estimate): void
    {
        abort_unless($this->hasGlobalCommercialAccess($request) || $estimate->sales_owner_id === $request->user()->id, 404);
    }

    private function assertQuotationOwner(Request $request, Quotation $quotation): void
    {
        abort_unless($this->hasGlobalCommercialAccess($request) || $quotation->sales_owner_id === $request->user()->id, 404);
    }

    private function assertInquiryOwner(Request $request, ClientInquiry $inquiry): void
    {
        abort_unless($this->hasGlobalCommercialAccess($request) || $inquiry->sales_owner_id === $request->user()->id, 404);
    }

    private function hasGlobalCommercialAccess(Request $request): bool
    {
        return $request->user()->hasAnyRole(['Administrator', 'Accounting', 'Finance', 'Executive']);
    }
}
