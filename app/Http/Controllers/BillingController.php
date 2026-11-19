<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentScanService;
use App\Services\StatutoryBillingRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

class BillingController
{
    public function page(Request $request, string $section = 'overview')
    {
        $permission = match ($section) {
            'ready' => 'billing.ready.view',
            'create' => 'billing.invoices.create',
            'register', 'revisions', 'documents' => 'billing.invoices.view',
            'approvals' => 'billing.invoices.review',
            'exceptions' => 'billing.exceptions.view',
            default => 'billing.dashboard.view',
        };
        abort_unless($request->user()->can($permission) || ($section === 'overview' && $request->user()->can('department.billing.view')), 403);

        return view('app', ['page' => 'billing', 'billingSection' => $section]);
    }

    public function dashboard(Request $request)
    {
        abort_unless($request->user()->can('billing.dashboard.view') || $request->user()->can('department.billing.view'), 403);
        $counts = DB::table('billing_invoices')->selectRaw("SUM(workflow_status = 'draft') as drafts, SUM(workflow_status = 'submitted') as pending, SUM(workflow_status = 'approved') as approved, SUM(workflow_status = 'issued') as issued")->first();
        $ready = $this->readyQuery()->limit(12)->get();
        $issuedValue = DB::table('billing_invoices')->where('workflow_status', 'issued')->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()])->selectRaw('currency, SUM(total) as amount')->groupBy('currency')->get();
        $canViewCollections = $request->user()->can('collections.view');
        $paidByInvoice = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('r.status', 'reconciled')->select('a.invoice_id')->selectRaw('SUM(a.amount) as collected')->groupBy('a.invoice_id');
        $openReceivables = $canViewCollections ? DB::table('billing_invoices as i')->leftJoinSub($paidByInvoice, 'paid', fn ($join) => $join->on('paid.invoice_id', '=', 'i.id'))->where('i.workflow_status', 'issued')->select('i.currency')->selectRaw('SUM(i.total - COALESCE(paid.collected, 0)) as amount')->groupBy('i.currency')->get() : null;
        $overdueCount = $canViewCollections ? DB::table('billing_invoices as i')->leftJoinSub($paidByInvoice, 'paid', fn ($join) => $join->on('paid.invoice_id', '=', 'i.id'))->where('i.workflow_status', 'issued')->whereNotNull('i.due_date')->whereDate('i.due_date', '<', now()->toDateString())->whereRaw('i.total > COALESCE(paid.collected, 0)')->count() : null;

        return response()->json(['data' => [
            'metrics' => ['ready' => $this->readyQuery()->count(), 'drafts' => (int) ($counts->drafts ?? 0), 'pending_approval' => (int) ($counts->pending ?? 0), 'awaiting_issuance' => (int) ($counts->approved ?? 0), 'issued' => (int) ($counts->issued ?? 0), 'issued_this_month_by_currency' => $issuedValue, 'outstanding_balance' => $openReceivables, 'overdue' => $overdueCount, 'collection_status' => $canViewCollections ? 'reconciled_receipts' : 'permission_required', 'exceptions' => DB::table('billing_exceptions')->where('status', 'open')->count()],
            'ready' => $ready, 'recent' => $this->invoiceQuery()->latest('billing_invoices.created_at')->limit(8)->get(),
            'approval_queue' => $this->invoiceQuery()->where('billing_invoices.workflow_status', 'submitted')->limit(8)->get(),
            'exceptions' => DB::table('billing_exceptions')->where('status', 'open')->orderByDesc('id')->limit(8)->get(),
            'trend' => $this->monthlyTrend(),
            'status_distribution' => DB::table('billing_invoices')->select('workflow_status')->selectRaw('COUNT(*) as count')->groupBy('workflow_status')->orderBy('workflow_status')->get(),
            'by_client' => DB::table('billing_invoices')->join('clients', 'clients.id', '=', 'billing_invoices.client_id')->where('billing_invoices.workflow_status', 'issued')->select('clients.company_name', 'billing_invoices.currency')->selectRaw('SUM(billing_invoices.total) as amount')->groupBy('clients.company_name', 'billing_invoices.currency')->orderByDesc('amount')->limit(8)->get(),
            'by_service' => DB::table('billing_invoices')->join('shipments', 'shipments.id', '=', 'billing_invoices.shipment_id')->where('billing_invoices.workflow_status', 'issued')->select('shipments.service_type', 'billing_invoices.currency')->selectRaw('SUM(billing_invoices.total) as amount')->groupBy('shipments.service_type', 'billing_invoices.currency')->orderByDesc('amount')->get(),
        ]]);
    }

    public function ready(Request $request)
    {
        abort_unless($request->user()->can('billing.ready.view'), 403);

        return response()->json(['data' => $this->readyQuery()->paginate(20)]);
    }

    public function charges(Request $request, int $shipment)
    {
        abort_unless($request->user()->can('billing.ready.view') || $request->user()->can('billing.invoices.create'), 403);
        $result = $this->eligibleCharges($shipment);

        return response()->json(['data' => $result['charges'], 'eligibility' => $result['eligibility']]);
    }

    public function invoices(Request $request)
    {
        abort_unless($request->user()->can('billing.invoices.view'), 403);
        $query = $this->invoiceQuery();
        if ($this->salesStatusOnly($request)) {
            $query->where('clients.sales_owner_id', $request->user()->id)->select('billing_invoices.id', 'billing_invoices.reference', 'billing_invoices.client_id', 'billing_invoices.shipment_id', 'billing_invoices.invoice_date', 'billing_invoices.due_date', 'billing_invoices.currency', 'billing_invoices.workflow_status', 'clients.company_name', 'shipments.job_number');
        }
        $query->when($request->string('search')->isNotEmpty(), function ($q) use ($request): void {
            $term = '%'.$request->string('search')->toString().'%';
            $q->where(fn ($inner) => $inner->where('billing_invoices.reference', 'like', $term)->orWhere('clients.company_name', 'like', $term)->orWhere('shipments.job_number', 'like', $term));
        });
        $query->when($request->filled('status'), fn ($q) => $q->where('billing_invoices.workflow_status', $request->string('status')));
        $query->when($request->filled('client_id'), fn ($q) => $q->where('billing_invoices.client_id', $request->integer('client_id')));

        return response()->json(['data' => $query->orderByDesc('billing_invoices.created_at')->paginate(min(100, max(1, $request->integer('per_page', 20))))]);
    }

    public function create(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('billing.invoices.create'), 403);
        $data = $request->validate([
            'shipment_id' => ['required', 'integer', 'exists:shipments,id'],
            'source_keys' => ['required', 'array', 'min:1', 'max:100'],
            'source_keys.*' => ['required', 'string', 'max:80', 'distinct', 'regex:/^(quotation_line|commercial_change):[1-9][0-9]*$/'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $invoice = DB::transaction(function () use ($request, $data): object {
            if (! empty($data['idempotency_key'])) {
                $existing = DB::table('billing_invoices')->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_unless((int) $existing->created_by === (int) $request->user()->id, 409, 'The idempotency key was already used.');

                    return $existing;
                }
            }
            foreach (collect($data['source_keys'])->sort()->values() as $sourceKey) {
                $this->lockAuthorizedSource((int) $data['shipment_id'], $sourceKey);
            }
            if (! empty($data['idempotency_key'])) {
                $existing = DB::table('billing_invoices')->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    abort_unless((int) $existing->created_by === (int) $request->user()->id, 409, 'The idempotency key was already used.');

                    return $existing;
                }
            }
            $eligibility = $this->eligibleCharges((int) $data['shipment_id']);
            abort_unless($eligibility['eligibility']['state'] === 'ready', 422, 'This shipment is not ready for billing.');
            $selected = collect($data['source_keys'])->mapWithKeys(fn ($key) => [$key => true]);
            $charges = collect($eligibility['charges'])->keyBy('source_key');
            abort_unless($selected->keys()->every(fn ($key) => $charges->has($key)), 422, 'One or more selected charges are no longer authorized and billable.');
            $shipment = DB::table('shipments')->where('id', $data['shipment_id'])->first();
            $year = now()->year;
            DB::table('billing_invoice_sequences')->insertOrIgnore(['year' => $year, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = DB::table('billing_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            $next = (int) $sequence->last_value + 1;
            DB::table('billing_invoice_sequences')->where('year', $year)->update(['last_value' => $next, 'updated_at' => now()]);
            $reference = sprintf('BIL-%d-%06d', $year, $next);
            $quotation = DB::table('quotations')->where('id', $shipment->quotation_id)->first();
            $baseline = DB::table('commercial_baselines')->where('id', $shipment->commercial_baseline_id)->first();
            $invoiceId = DB::table('billing_invoices')->insertGetId([
                'reference' => $reference, 'idempotency_key' => $data['idempotency_key'] ?? null, 'client_id' => $shipment->client_id,
                'shipment_id' => $shipment->id, 'quotation_id' => $quotation->id, 'commercial_baseline_id' => $baseline->id,
                'baseline_version' => $baseline->quotation_version, 'currency' => $quotation->currency, 'payment_terms' => $quotation->payment_terms,
                'billing_contact' => DB::table('clients')->where('id', $shipment->client_id)->value('contact_name'),
                'billing_address' => DB::table('clients')->where('id', $shipment->client_id)->value('billing_address'),
                'due_date' => $data['due_date'] ?? null, 'remarks' => $data['remarks'] ?? null,
                'workflow_status' => 'draft', 'tax_configuration_status' => 'unverified', 'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $subtotalCents = 0;
            $discountCents = 0;
            $taxCents = 0;
            $totalCents = 0;
            $taxKnown = true;
            foreach ($selected->keys() as $key) {
                $charge = $charges[$key];
                $lineAmount = $this->cents($charge['remaining_amount']);
                $totalCents += $lineAmount;
                $taxValue = $charge['tax_amount'] ?? null;
                $taxKnown = $taxKnown && $taxValue !== null;
                $subtotalCents += $this->cents($charge['subtotal'] ?? $charge['remaining_amount']);
                $discountCents += $this->cents($charge['discount_amount'] ?? '0.00');
                $taxCents += $taxValue === null ? 0 : $this->cents($taxValue);
                DB::table('billing_invoice_lines')->insert([
                    'invoice_id' => $invoiceId, 'source_type' => $charge['source_type'], 'source_id' => $charge['source_id'],
                    'authorization_reference' => $charge['authorization_reference'], 'description' => $charge['description'],
                    'quantity' => $charge['quantity'], 'unit' => $charge['unit'], 'unit_price' => $charge['unit_price'],
                    'authorized_amount' => $charge['authorized_amount'], 'previously_invoiced' => $charge['previously_invoiced'],
                    'line_amount' => $charge['remaining_amount'], 'currency' => $charge['currency'], 'tax_treatment' => $charge['tax_treatment'],
                    'tax_rate' => $charge['tax_rate'], 'tax_amount' => $taxValue,
                    'source_snapshot' => json_encode($charge['snapshot'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('billing_invoices')->where('id', $invoiceId)->update(['subtotal' => $this->money($subtotalCents), 'discount_total' => $this->money($discountCents), 'tax_total' => $taxKnown ? $this->money($taxCents) : null, 'total' => $taxKnown ? $this->money($totalCents) : null, 'updated_at' => now()]);
            DB::table('billing_invoice_events')->insert(['invoice_id' => $invoiceId, 'actor_id' => $request->user()->id, 'event' => 'draft_created', 'after_state' => json_encode(['reference' => $reference, 'source_keys' => $selected->keys()->values()], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->recordInvoiceRevision($invoiceId, $request->user()->id, 'Draft created from authorized sources.');

            return DB::table('billing_invoices')->where('id', $invoiceId)->first();
        });
        $audit->record('billing.invoice.created', null, ['invoice_id' => $invoice->id, 'reference' => $invoice->reference, 'shipment_id' => $invoice->shipment_id]);

        return response()->json(['data' => $this->invoiceDetail((int) $invoice->id, $request->user()), 'message' => 'Draft invoice created.'], 201);
    }

    public function submit(Request $request, int $invoice, AuditLogger $audit)
    {
        abort_unless($request->user()->can('billing.invoices.submit'), 403);
        $record = DB::transaction(function () use ($request, $invoice): object {
            $row = DB::table('billing_invoices')->where('id', $invoice)->lockForUpdate()->firstOrFail();
            abort_unless($row->created_by === $request->user()->id || $request->user()->can('billing.invoices.update'), 403);
            abort_unless($row->workflow_status === 'draft', 409, 'Only draft invoices can be submitted.');
            $eligibility = $this->eligibleCharges((int) $row->shipment_id, $invoice);
            $map = collect($eligibility['charges'])->keyBy(fn ($charge) => $charge['source_type'].':'.$charge['source_id']);
            foreach (DB::table('billing_invoice_lines')->where('invoice_id', $invoice)->get() as $line) {
                abort_unless($map->has($line->source_type.':'.$line->source_id), 409, 'A source authorization is no longer eligible.');
                abort_unless($map[$line->source_type.':'.$line->source_id]['remaining_amount'] >= $line->line_amount, 409, 'An authorized amount has changed.');
            }
            DB::table('billing_invoices')->where('id', $invoice)->update(['workflow_status' => 'submitted', 'invoice_date' => $row->invoice_date ?: now()->toDateString(), 'submitted_at' => now(), 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            DB::table('billing_invoice_events')->insert(['invoice_id' => $invoice, 'actor_id' => $request->user()->id, 'event' => 'submitted', 'before_state' => json_encode(['workflow_status' => 'draft']), 'after_state' => json_encode(['workflow_status' => 'submitted']), 'created_at' => now()]);
            $this->recordInvoiceRevision($invoice, $request->user()->id, 'Submitted for review.');

            return $row;
        });
        $audit->record('billing.invoice.submitted', null, ['invoice_id' => $record->id, 'reference' => $record->reference]);

        return response()->json(['data' => $this->invoiceDetail($invoice, $request->user()), 'message' => 'Invoice submitted for Accounting review.']);
    }

    public function cancelDraft(Request $request, int $invoice, AuditLogger $audit)
    {
        abort_unless($request->user()->can('billing.invoices.update'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::transaction(function () use ($request, $invoice, $data): void {
            $row = DB::table('billing_invoices')->where('id', $invoice)->lockForUpdate()->firstOrFail();
            abort_unless($row->workflow_status === 'draft', 409, 'Only an unsubmitted draft can be canceled.');
            abort_unless((int) $row->created_by === (int) $request->user()->id || $request->user()->can('billing.invoices.update'), 403);
            DB::table('billing_invoices')->where('id', $invoice)->update(['workflow_status' => 'cancelled', 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            DB::table('billing_invoice_events')->insert(['invoice_id' => $invoice, 'actor_id' => $request->user()->id, 'event' => 'draft_cancelled', 'before_state' => json_encode(['workflow_status' => 'draft']), 'after_state' => json_encode(['workflow_status' => 'cancelled']), 'reason' => $data['reason'], 'created_at' => now()]);
            $this->recordInvoiceRevision($invoice, $request->user()->id, $data['reason']);
        });
        $audit->record('billing.invoice.draft_cancelled', null, ['invoice_id' => $invoice, 'reason' => $data['reason']]);

        return response()->json(['message' => 'Draft canceled; its authorized amounts are available for another draft.']);
    }

    public function review(Request $request, int $invoice, AuditLogger $audit)
    {
        abort_unless($this->canReview($request), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'request_revision'])], 'comments' => ['required', 'string', 'min:3', 'max:2000']]);
        DB::transaction(function () use ($request, $invoice, $data): void {
            $row = DB::table('billing_invoices')->where('id', $invoice)->lockForUpdate()->firstOrFail();
            abort_unless($row->workflow_status === 'submitted', 409, 'Only submitted invoices can be reviewed.');
            abort_unless((int) $row->created_by !== (int) $request->user()->id, 403, 'The preparer cannot approve their own invoice.');
            $status = match ($data['action']) {
                'approve' => 'approved', 'reject' => 'rejected', default => 'revision_requested'
            };
            DB::table('billing_invoices')->where('id', $invoice)->update(['workflow_status' => $status, 'approved_by' => $status === 'approved' ? $request->user()->id : null, 'approved_at' => $status === 'approved' ? now() : null, 'updated_at' => now(), 'updated_by' => $request->user()->id]);
            DB::table('billing_invoice_approvals')->insert(['invoice_id' => $invoice, 'actor_id' => $request->user()->id, 'action' => $data['action'], 'comments' => $data['comments'], 'acted_at' => now()]);
            DB::table('billing_invoice_events')->insert(['invoice_id' => $invoice, 'actor_id' => $request->user()->id, 'event' => 'review_'.$data['action'], 'before_state' => json_encode(['workflow_status' => 'submitted']), 'after_state' => json_encode(['workflow_status' => $status]), 'reason' => $data['comments'], 'created_at' => now()]);
            $this->recordInvoiceRevision($invoice, $request->user()->id, $data['comments']);
        });
        $audit->record('billing.invoice.reviewed', null, ['invoice_id' => $invoice, 'action' => $data['action'], 'reason' => $data['comments']]);

        return response()->json(['data' => $this->invoiceDetail($invoice, $request->user()), 'message' => 'Review decision recorded.']);
    }

    public function issue(Request $request, int $invoice, StatutoryBillingRegistry $profiles)
    {
        abort_unless($request->user()->can('billing.invoices.issue') && $request->user()->hasRole('Billing'), 403);
        abort_unless((bool) config('ifc_billing.legal_issuance_verified', false), 409, 'Official invoice issuance is disabled until company tax registration, invoice format, and statutory numbering are verified.');
        abort_unless($profiles->active(), 409, 'A Finance and Legal approved statutory profile is required before official invoice issuance.');
        abort(409, 'A configured statutory issuance provider is required before official invoice issuance can be enabled.');
    }

    public function documents(Request $request, int $invoice)
    {
        abort_unless(! $this->salesStatusOnly($request), 403);
        abort_unless(DB::table('billing_invoices')->where('id', $invoice)->exists(), 404);

        return response()->json(['data' => DB::table('billing_invoice_documents')->where('invoice_id', $invoice)->orderBy('created_at')->get()]);
    }

    public function uploadDocument(Request $request, int $invoice, AuditLogger $audit, DocumentScanService $scan)
    {
        $data = $request->validate(['document' => ['required', 'file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx']]);
        $record = DB::transaction(function () use ($request, $invoice, $data, $scan): object {
            $row = DB::table('billing_invoices')->where('id', $invoice)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($row->workflow_status, ['draft', 'submitted', 'revision_requested'], true), 409, 'Supporting documents cannot be changed after approval or issuance.');
            $file = $data['document'];
            $hash = hash_file('sha256', $file->getRealPath());
            $stored = $scan->store($file, 'private/billing/'.$row->reference);
            $id = DB::table('billing_invoice_documents')->insertGetId(['invoice_id' => $invoice, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'storage_path' => $stored['path'], 'mime_type' => (string) $file->getMimeType(), 'file_size' => $file->getSize(), 'sha256' => $hash, 'scan_status' => $stored['scan_status'], 'scan_result' => $stored['scan_result'], 'scanned_at' => $stored['scanned_at'], 'version' => 1, 'uploaded_by' => $request->user()->id, 'created_at' => now()]);
            DB::table('billing_invoice_events')->insert(['invoice_id' => $invoice, 'actor_id' => $request->user()->id, 'event' => 'supporting_document_uploaded', 'after_state' => json_encode(['document_id' => $id, 'sha256' => $hash]), 'created_at' => now()]);

            return DB::table('billing_invoice_documents')->where('id', $id)->first();
        });
        $audit->record('billing.document.uploaded', null, ['invoice_id' => $invoice, 'document_id' => $record->id, 'sha256' => $record->sha256]);

        return response()->json(['data' => $record, 'message' => $record->scan_status === 'clean' ? 'Supporting document scanned and stored privately.' : 'Supporting document is quarantined until a clean scan is available.'], 201);
    }

    public function downloadDocument(Request $request, int $document)
    {
        $record = DB::table('billing_invoice_documents')->join('billing_invoices', 'billing_invoices.id', '=', 'billing_invoice_documents.invoice_id')->where('billing_invoice_documents.id', $document)->select('billing_invoice_documents.*', 'billing_invoices.workflow_status')->firstOrFail();
        abort_unless(in_array($record->workflow_status, ['issued', 'approved'], true) || $request->user()->can('billing.documents.manage'), 403);
        app(DocumentScanService::class)->assertTrusted($record->scan_status);
        abort_unless(Storage::disk('local')->exists($record->storage_path), 404);

        return Storage::disk('local')->download($record->storage_path, basename($record->original_name), ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function indexDetail(Request $request, int $invoice)
    {
        abort_unless($request->user()->can('billing.invoices.view'), 403);

        return response()->json(['data' => $this->invoiceDetail($invoice, $request->user())]);
    }

    public function preview(Request $request, int $invoice)
    {
        abort_unless($request->user()->can('billing.invoices.download') && ! $this->salesStatusOnly($request), 403);
        $data = $this->invoiceDetail($invoice, $request->user());

        return response()->view('billing.preview', ['invoice' => $data, 'isIssued' => $data->workflow_status === 'issued']);
    }

    public function exceptions(Request $request)
    {
        abort_unless($request->user()->can('billing.exceptions.view'), 403);

        return response()->json(['data' => DB::table('billing_exceptions')->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")->orderByDesc('created_at')->paginate(30)]);
    }

    public function resolveException(Request $request, int $exception, AuditLogger $audit)
    {
        abort_unless($request->user()->can('billing.exceptions.resolve'), 403);
        $data = $request->validate(['resolution' => ['required', 'string', 'min:3', 'max:2000']]);
        $row = DB::table('billing_exceptions')->where('id', $exception)->firstOrFail();
        abort_unless($row->status === 'open', 409, 'This exception is already resolved.');
        DB::table('billing_exceptions')->where('id', $exception)->update(['status' => 'resolved', 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution' => $data['resolution'], 'updated_at' => now()]);
        $audit->record('billing.exception.resolved', null, ['exception_id' => $exception, 'reason' => $data['resolution']]);

        return response()->json(['message' => 'Exception resolution recorded.']);
    }

    public function history(Request $request, int $invoice)
    {
        abort_unless($request->user()->can('billing.invoices.history.view'), 403);
        abort_unless(DB::table('billing_invoices')->where('id', $invoice)->exists(), 404);

        return response()->json(['data' => ['events' => DB::table('billing_invoice_events')->where('invoice_id', $invoice)->orderBy('created_at')->get(), 'approvals' => DB::table('billing_invoice_approvals')->join('users', 'users.id', '=', 'billing_invoice_approvals.actor_id')->where('invoice_id', $invoice)->select('billing_invoice_approvals.*', 'users.name as actor')->orderBy('acted_at')->get(), 'revisions' => DB::table('billing_invoice_revisions')->where('invoice_id', $invoice)->orderBy('revision')->get()]]);
    }

    private function readyQuery()
    {
        return DB::table('shipments as s')->join('clients as c', 'c.id', '=', 's.client_id')->join('quotations as q', 'q.id', '=', 's.quotation_id')->join('commercial_baselines as b', 'b.id', '=', 's.commercial_baseline_id')->whereNotNull('s.client_id')->whereNotNull('s.quotation_id')->whereNotNull('s.commercial_baseline_id')->whereNotIn('s.master_status', ['cancelled', 'financially_closed'])->whereNull('s.archived_at')->whereColumn('s.client_id', 'q.client_id')->whereColumn('b.quotation_id', 'q.id')->whereColumn('b.quotation_version', 'q.version')->select('s.id as shipment_id', 's.job_number', 's.reference as shipment_reference', 's.master_status', 's.service_type', 's.mode', 's.currency as shipment_currency', 'c.id as client_id', 'c.company_name', 'q.reference as quotation_reference', 'q.currency', 'q.payment_terms', 'b.id as baseline_id', 'b.quotation_version', 'b.accepted_at')->orderByDesc('s.updated_at');
    }

    private function eligibleCharges(int $shipmentId, ?int $excludeInvoiceId = null): array
    {
        $shipment = DB::table('shipments as s')->join('quotations as q', 'q.id', '=', 's.quotation_id')->join('commercial_baselines as b', 'b.id', '=', 's.commercial_baseline_id')->where('s.id', $shipmentId)->whereNotIn('s.master_status', ['cancelled', 'financially_closed'])->whereNull('s.archived_at')->whereColumn('s.client_id', 'q.client_id')->whereColumn('b.quotation_id', 'q.id')->whereColumn('b.quotation_version', 'q.version')->select('s.*', 'q.reference as quotation_reference', 'q.currency as quotation_currency', 'q.payment_terms', 'b.quotation_version', 'b.snapshot', 'b.accepted_at')->first();
        abort_unless($shipment && $shipment->client_id, 422, 'Shipment has no valid accepted quotation baseline.');
        $snapshot = json_decode($shipment->snapshot, true) ?: [];
        $acceptedLines = $snapshot['selling_lines'] ?? [];
        $sourceLines = DB::table('quotation_lines')->where('quotation_id', $shipment->quotation_id)->orderBy('id')->get();
        $charges = [];
        $reservedTotal = 0;
        $billedTotal = 0;
        $baselineMatches = count($acceptedLines) === $sourceLines->count();
        foreach ($sourceLines as $i => $line) {
            $accepted = $acceptedLines[$i] ?? null;
            $baselineFields = ['description', 'quantity', 'unit', 'unit_price', 'discount_type', 'discount_value', 'subtotal', 'discount_amount', 'tax_treatment', 'tax_rate', 'tax_amount', 'line_total'];
            $lineMatches = $accepted !== null && collect($baselineFields)->every(fn ($field) => (string) ($accepted[$field] ?? '') === (string) ($line->{$field} ?? ''));
            if (! $lineMatches) {
                $baselineMatches = false;

                continue;
            }
            $previous = $this->previouslyInvoiced('quotation_line', $line->id);
            $reserved = $this->reservedAmount('quotation_line', $line->id, $excludeInvoiceId);
            $reservedTotal += $this->cents($reserved);
            $billedTotal += $this->cents($previous);
            $authorized = (string) $line->line_total;
            $remaining = $this->money($this->cents($authorized) - $this->cents($previous) - $this->cents($reserved));
            if ($this->cents($remaining) <= 0) {
                continue;
            }
            $charges[] = ['source_key' => 'quotation_line:'.$line->id, 'source_type' => 'quotation_line', 'source_id' => $line->id,
                'authorization_reference' => $shipment->quotation_reference.' / line '.$line->id, 'description' => $line->description,
                'quantity' => $line->quantity, 'unit' => $line->unit, 'unit_price' => $line->unit_price, 'authorized_amount' => $authorized,
                'previously_invoiced' => $previous, 'reserved_amount' => $reserved, 'remaining_amount' => $remaining, 'subtotal' => $line->subtotal,
                'discount_amount' => $line->discount_amount, 'currency' => $shipment->quotation_currency, 'tax_treatment' => $line->tax_treatment,
                'tax_rate' => $line->tax_rate, 'tax_amount' => $line->tax_amount, 'snapshot' => ['quotation_reference' => $shipment->quotation_reference, 'baseline_version' => $shipment->quotation_version, 'line' => $accepted]];
        }
        $authorizations = DB::table('commercial_change_authorizations as a')->join('additional_charge_requests as r', 'r.id', '=', 'a.request_id')->join('additional_charge_evidence as e', 'e.id', '=', 'a.evidence_id')->where('a.shipment_id', $shipmentId)->where('a.commercial_baseline_id', $shipment->commercial_baseline_id)->select('a.*', 'r.reference as request_reference', 'r.description')->orderBy('a.id')->get();
        foreach ($authorizations as $auth) {
            $previous = $this->previouslyInvoiced('commercial_change', $auth->id);
            $reserved = $this->reservedAmount('commercial_change', $auth->id, $excludeInvoiceId);
            $reservedTotal += $this->cents($reserved);
            $billedTotal += $this->cents($previous);
            $remaining = $this->money($this->cents($auth->approved_amount) - $this->cents($previous) - $this->cents($reserved));
            if ($this->cents($remaining) <= 0) {
                continue;
            }
            $charges[] = ['source_key' => 'commercial_change:'.$auth->id, 'source_type' => 'commercial_change', 'source_id' => $auth->id,
                'authorization_reference' => $auth->request_reference.' / authorization '.$auth->authorization_version, 'description' => $auth->description,
                'quantity' => '1.0000', 'unit' => 'authorized amount', 'unit_price' => $auth->approved_amount, 'authorized_amount' => $auth->approved_amount,
                'previously_invoiced' => $previous, 'reserved_amount' => $reserved, 'remaining_amount' => $remaining, 'subtotal' => $remaining, 'discount_amount' => '0.00',
                'currency' => $auth->currency, 'tax_treatment' => null, 'tax_rate' => null, 'tax_amount' => null,
                'snapshot' => ['request_reference' => $auth->request_reference, 'authorization_id' => $auth->id, 'evidence_id' => $auth->evidence_id, 'effective_at' => $auth->effective_at, 'tax_classification' => 'unconfigured']];
        }
        $currencyMismatch = collect($charges)->contains(fn ($charge) => $charge['currency'] !== $shipment->quotation_currency);
        $blocked = ! $baselineMatches || $currencyMismatch;

        $state = $blocked ? 'blocked' : ($charges ? (($reservedTotal > 0 || $billedTotal > 0) ? 'partially_eligible' : 'ready') : ($reservedTotal > 0 ? 'blocked' : ($billedTotal > 0 ? 'already_fully_billed' : 'blocked')));
        $reasons = ! $baselineMatches ? ['Accepted quotation lines do not match the current immutable baseline.'] : ($currencyMismatch ? ['Commercial authorizations use inconsistent currencies.'] : ($reservedTotal > 0 ? ['Some authorized amounts are reserved by another active invoice draft or approval.'] : []));

        return ['charges' => $blocked ? [] : $charges, 'eligibility' => [
            'state' => $state,
            'reasons' => $reasons,
            'message' => ! $baselineMatches ? 'Baseline verification failed.' : ($currencyMismatch ? 'Currency reconciliation is required.' : ($charges ? ($reservedTotal > 0 || $billedTotal > 0 ? 'Some authorized balances remain available; other amounts are already invoiced or reserved.' : 'Accepted quotation and verified client authorizations are available for draft billing.') : ($reservedTotal > 0 ? 'Authorized amounts are reserved by another active invoice.' : ($billedTotal > 0 ? 'All authorized commercial amounts are already allocated to issued invoices.' : 'No eligible commercial charges are available.')))),
            'milestone_policy' => 'No service-specific billing milestone or POD requirement is configured. Drafting is allowed from accepted commercial scope; issuance remains subject to configured policy.',
            'shipment_id' => $shipmentId,
        ]];
    }

    private function previouslyInvoiced(string $sourceType, int $sourceId): string
    {
        return (string) DB::table('billing_invoice_lines as l')->join('billing_invoices as i', 'i.id', '=', 'l.invoice_id')->where('l.source_type', $sourceType)->where('l.source_id', $sourceId)->where('i.workflow_status', 'issued')->sum('l.line_amount');
    }

    private function reservedAmount(string $sourceType, int $sourceId, ?int $excludeInvoiceId = null): string
    {
        return (string) DB::table('billing_invoice_lines as l')->join('billing_invoices as i', 'i.id', '=', 'l.invoice_id')->where('l.source_type', $sourceType)->where('l.source_id', $sourceId)->whereIn('i.workflow_status', ['draft', 'submitted', 'approved', 'revision_requested'])->when($excludeInvoiceId, fn ($query) => $query->where('i.id', '!=', $excludeInvoiceId))->sum('l.line_amount');
    }

    private function lockAuthorizedSource(int $shipmentId, string $sourceKey): void
    {
        [$type, $rawId] = explode(':', $sourceKey, 2);
        $id = (int) $rawId;
        $shipment = DB::table('shipments')->where('id', $shipmentId)->first();
        abort_unless($shipment, 422, 'Shipment was not found.');
        if ($type === 'quotation_line') {
            $line = DB::table('quotation_lines')->where('id', $id)->lockForUpdate()->first();
            abort_unless($line && (int) $line->quotation_id === (int) $shipment->quotation_id, 422, 'Quotation line does not belong to this shipment.');

            return;
        }
        $authorization = DB::table('commercial_change_authorizations')->where('id', $id)->lockForUpdate()->first();
        abort_unless($authorization && (int) $authorization->shipment_id === $shipmentId && (int) $authorization->commercial_baseline_id === (int) $shipment->commercial_baseline_id, 422, 'Commercial authorization does not belong to this shipment.');
    }

    private function invoiceQuery()
    {
        return DB::table('billing_invoices')->join('clients', 'clients.id', '=', 'billing_invoices.client_id')->join('shipments', 'shipments.id', '=', 'billing_invoices.shipment_id')->select('billing_invoices.*', 'clients.company_name', 'shipments.job_number');
    }

    private function recordInvoiceRevision(int $invoiceId, int $actorId, ?string $reason): void
    {
        $snapshot = [
            'invoice' => (array) DB::table('billing_invoices')->where('id', $invoiceId)->firstOrFail(),
            'lines' => DB::table('billing_invoice_lines')->where('invoice_id', $invoiceId)->orderBy('id')->get()->map(fn ($line) => (array) $line)->all(),
        ];
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $revision = (int) DB::table('billing_invoice_revisions')->where('invoice_id', $invoiceId)->max('revision') + 1;
        $revisionId = DB::table('billing_invoice_revisions')->insertGetId([
            'invoice_id' => $invoiceId, 'revision' => $revision, 'snapshot' => $encoded,
            // MySQL canonicalizes JSON when storing it; hash the persisted bytes so verification is portable.
            'sha256' => str_repeat('0', 64), 'reason' => $reason,
            'created_by' => $actorId, 'created_at' => now(),
        ]);
        $persistedSnapshot = (string) DB::table('billing_invoice_revisions')->where('id', $revisionId)->value('snapshot');
        DB::table('billing_invoice_revisions')->where('id', $revisionId)->update(['sha256' => hash('sha256', $persistedSnapshot)]);
    }

    private function invoiceDetail(int $id, ?User $user = null): object
    {
        $query = $this->invoiceQuery()->where('billing_invoices.id', $id);
        if ($user && $this->salesStatusOnly(request())) {
            $query->where('clients.sales_owner_id', $user->id)->select('billing_invoices.id', 'billing_invoices.reference', 'billing_invoices.client_id', 'billing_invoices.shipment_id', 'billing_invoices.invoice_date', 'billing_invoices.due_date', 'billing_invoices.currency', 'billing_invoices.workflow_status', 'clients.company_name', 'shipments.job_number');
        }
        $invoice = $query->firstOrFail();
        $restricted = $user && $this->salesStatusOnly(request());
        $invoice->lines = $restricted ? [] : DB::table('billing_invoice_lines')->where('invoice_id', $id)->orderBy('id')->get();
        $invoice->approvals = $restricted ? [] : DB::table('billing_invoice_approvals')->join('users', 'users.id', '=', 'billing_invoice_approvals.actor_id')->where('invoice_id', $id)->select('billing_invoice_approvals.*', 'users.name as actor')->orderBy('acted_at')->get();
        if ($restricted) {
            $invoice->subtotal = $invoice->discount_total = $invoice->tax_total = $invoice->total = null;
            $invoice->payment_terms = $invoice->billing_address = $invoice->billing_contact = null;
        }
        if (! $user?->can('collections.view')) {
            $invoice->collection_status = 'restricted';
        } elseif ($invoice->workflow_status !== 'issued') {
            $invoice->collection_status = 'not_issued';
        } else {
            $invoice->collection_paid = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('a.invoice_id', $id)->where('r.status', 'reconciled')->sum('a.amount');
            $invoice->collection_pending = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('a.invoice_id', $id)->where('r.status', 'pending_reconciliation')->sum('a.amount');
            $invoice->collection_outstanding = max(0, $this->cents($invoice->total) - $this->cents($invoice->collection_paid));
            $invoice->collection_status = $invoice->collection_outstanding === 0 ? 'settled' : ($this->cents($invoice->collection_paid) > 0 ? 'partially_collected' : 'unpaid');
        }
        $invoice->accounting_handover = DB::table('billing_accounting_outbox')->where('invoice_id', $id)->value('status') ?? 'pending_integration';

        return $invoice;
    }

    private function salesStatusOnly(Request $request): bool
    {
        return $request->user()->hasRole('Sales') && ! $request->user()->hasAnyRole(['Billing', 'Accounting', 'Finance', 'Administrator']);
    }

    private function canReview(Request $request): bool
    {
        return $request->user()->can('billing.invoices.review') && ($request->user()->hasRole('Accounting') || $request->user()->hasRole('Finance'));
    }

    private function monthlyTrend()
    {
        $format = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', issued_at)" : "DATE_FORMAT(issued_at, '%Y-%m')";

        return DB::table('billing_invoices')->where('workflow_status', 'issued')->where('issued_at', '>=', now()->subMonths(11)->startOfMonth())->selectRaw("{$format} as month, currency, SUM(total) as amount")->groupBy('month', 'currency')->orderBy('month')->get();
    }

    private function cents(string|int|float|null $amount): int
    {
        if ($amount === null) {
            return 0;
        }
        $value = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Invalid stored monetary value.');
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $result = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $negative ? -$result : $result;
    }

    private function money(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
