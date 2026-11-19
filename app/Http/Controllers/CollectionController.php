<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CollectionController
{
    public function page(Request $request)
    {
        abort_unless($request->user()->can('collections.view'), 403);

        return view('app', ['page' => 'collections']);
    }

    public function dashboard(Request $request)
    {
        abort_unless($request->user()->can('collections.view'), 403);
        $invoices = $this->issuedInvoices()->get();
        $receivables = $this->receivableRows($invoices);
        $currencyTotals = $receivables->groupBy('currency')->map(fn ($rows, $currency) => [
            'currency' => $currency,
            'invoiced' => $this->money($rows->sum(fn ($row) => $this->cents($row['total']))),
            'collected' => $this->money($rows->sum(fn ($row) => $this->cents($row['collected']))),
            'outstanding' => $this->money($rows->sum(fn ($row) => $this->cents($row['outstanding']))),
            'pending_reconciliation' => $this->money($rows->sum(fn ($row) => $this->cents($row['pending_amount']))),
            'count' => $rows->count(),
        ])->values();
        $aging = $receivables->groupBy('currency')->map(function ($rows, $currency): array {
            $buckets = ['not_due' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0, 'no_due_date' => 0];
            foreach ($rows as $row) {
                $key = $row['aging_bucket'];
                $buckets[$key] += $this->cents($row['outstanding']);
            }

            return ['currency' => $currency, ...collect($buckets)->map(fn ($amount) => $this->money($amount))->all()];
        })->values();
        $pending = DB::table('collection_payment_receipts as r')->join('clients as c', 'c.id', '=', 'r.client_id')
            ->where('r.status', 'pending_reconciliation')->select('r.id', 'r.reference', 'r.client_id', 'c.company_name', 'r.currency', 'r.amount', 'r.received_at', 'r.payment_method', 'r.evidence_reference', 'r.recorded_by')->orderByDesc('r.received_at')->limit(8)->get();
        $verifiedThisMonth = DB::table('collection_payment_receipts')->where('status', 'reconciled')->whereBetween('reconciled_at', [now()->startOfMonth(), now()->endOfMonth()])->select('currency')->selectRaw('SUM(amount) as amount')->groupBy('currency')->get();
        $unapplied = DB::table('collection_payment_receipts as r')->leftJoinSub(DB::table('collection_payment_allocations')->select('receipt_id')->selectRaw('SUM(amount) as allocated')->groupBy('receipt_id'), 'a', fn ($join) => $join->on('a.receipt_id', '=', 'r.id'))->where('r.status', 'reconciled')->whereRaw('r.amount > COALESCE(a.allocated, 0)')->select('r.currency')->selectRaw('SUM(r.amount - COALESCE(a.allocated, 0)) as amount')->groupBy('r.currency')->get();
        $partial = $receivables->filter(fn ($row) => $this->cents($row['collected']) > 0 && $this->cents($row['outstanding']) > 0)->count();
        $settled = $receivables->filter(fn ($row) => $this->cents($row['total']) > 0 && $this->cents($row['outstanding']) === 0)->count();

        return response()->json(['data' => [
            'totals' => $currencyTotals,
            'aging' => $aging,
            'counts' => ['issued_invoices' => $receivables->count(), 'overdue' => $receivables->filter(fn ($row) => $this->cents($row['outstanding']) > 0 && ! in_array($row['aging_bucket'], ['not_due', 'no_due_date'], true))->count(), 'pending_reconciliation' => DB::table('collection_payment_receipts')->where('status', 'pending_reconciliation')->count()],
            'kpis' => ['verified_this_month' => $verifiedThisMonth, 'unapplied_credits' => $unapplied, 'partially_paid' => $partial, 'fully_settled' => $settled, 'as_of' => now()->toIso8601String()],
            'pending_receipts' => $pending,
            'recent_receipts' => $this->receiptQuery()->orderByDesc('r.received_at')->limit(8)->get(),
        ]]);
    }

    public function invoices(Request $request)
    {
        abort_unless($request->user()->can('collections.view'), 403);
        $data = $request->validate(['as_of' => ['nullable', 'date', 'before_or_equal:today']]);
        $query = $this->issuedInvoices()
            ->when(isset($data['as_of']), fn ($query) => $query->whereDate('billing_invoices.issued_at', '<=', $data['as_of']))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('billing_invoices.reference', 'like', $term)->orWhere('billing_invoices.official_number', 'like', $term)->orWhere('clients.company_name', 'like', $term)->orWhere('shipments.job_number', 'like', $term));
            })
            ->when($request->filled('client_id'), fn ($query) => $query->where('billing_invoices.client_id', $request->integer('client_id')))
            ->orderByRaw('CASE WHEN billing_invoices.due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('billing_invoices.due_date');

        $items = $query->paginate(min(100, max(1, $request->integer('per_page', 20))));
        $items->setCollection($this->receivableRows(collect($items->items()), $data['as_of'] ?? null));

        return response()->json(['data' => $items]);
    }

    public function receipts(Request $request)
    {
        abort_unless($request->user()->can('collections.history.view'), 403);
        $query = $this->receiptQuery()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('r.reference', 'like', $term)->orWhere('r.evidence_reference', 'like', $term)->orWhere('c.company_name', 'like', $term));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('r.status', $request->string('status')->toString()));

        $page = $query->orderByDesc('r.received_at')->paginate(min(100, max(1, $request->integer('per_page', 20))));
        $allocations = DB::table('collection_payment_allocations as a')->join('billing_invoices as i', 'i.id', '=', 'a.invoice_id')->whereIn('a.receipt_id', $page->getCollection()->pluck('id'))->select('a.receipt_id', 'i.reference', 'a.amount', 'i.currency')->get()->groupBy('receipt_id');
        $page->setCollection($page->getCollection()->map(function ($receipt) use ($allocations) {
            $receipt->unapplied_credit = $this->money($this->cents($receipt->unapplied_credit));
            $receipt->allocations = $allocations->get($receipt->id, collect())->values();

            return $receipt;
        }));

        return response()->json(['data' => $page]);
    }

    public function record(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.record') && $request->user()->hasRole('Accounting'), 403);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100', 'unique:collection_payment_receipts,reference'],
            'idempotency_key' => ['nullable', 'string', 'max:100', 'unique:collection_payment_receipts,idempotency_key'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'cash', 'check', 'card', 'online_payment', 'other'])],
            'evidence_reference' => ['required', 'string', 'max:180'],
            'provider_name' => ['nullable', 'string', 'max:120'],
            'bank_transaction_reference' => ['nullable', 'string', 'max:180'],
            'deposit_reference' => ['nullable', 'string', 'max:180'],
            'clearing_date' => ['nullable', 'date', 'after_or_equal:received_at'],
            'payment_notes' => ['nullable', 'string', 'max:5000'],
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.invoice_id' => ['required', 'integer', 'distinct', 'exists:billing_invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999.99'],
        ]);

        $receipt = DB::transaction(function () use ($request, $data, $audit): object {
            if (! empty($data['bank_transaction_reference'])) {
                $duplicate = DB::table('collection_payment_receipts')->where('bank_transaction_reference', $data['bank_transaction_reference'])->when(! empty($data['provider_name']), fn ($q) => $q->where('provider_name', $data['provider_name']))->exists();
                abort_if($duplicate, 422, 'This bank transaction reference has already been recorded for this provider.');
            }
            $allocationTotal = collect($data['allocations'])->sum(fn ($allocation) => $this->cents($allocation['amount']));
            abort_if($allocationTotal > $this->cents($data['amount']), 422, 'Allocations cannot exceed the receipt amount.');
            $invoices = collect($data['allocations'])->keyBy('invoice_id');
            if ($invoices->isNotEmpty()) {
                $invoiceRows = DB::table('billing_invoices')->whereIn('id', $invoices->keys())->lockForUpdate()->get()->keyBy('id');
                foreach ($invoices as $invoiceId => $allocation) {
                    $invoice = $invoiceRows->get($invoiceId);
                    abort_unless($invoice && $invoice->workflow_status === 'issued', 422, 'Collections can only be allocated to issued invoices.');
                    abort_unless((int) $invoice->client_id === (int) $data['client_id'], 422, 'The selected invoice belongs to a different client.');
                    abort_unless($invoice->currency === $data['currency'], 422, 'Receipt and invoice currencies must match.');
                    $held = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('a.invoice_id', $invoiceId)->whereIn('r.status', ['pending_reconciliation', 'reconciled'])->sum('a.amount');
                    abort_if($this->cents($held) + $this->cents($allocation['amount']) > $this->cents($invoice->total), 422, 'This allocation exceeds the invoice balance after existing and pending allocations.');
                }
            }

            $receiptId = DB::table('collection_payment_receipts')->insertGetId([
                'reference' => $data['reference'], 'idempotency_key' => $data['idempotency_key'] ?? null,
                'client_id' => $data['client_id'], 'currency' => $data['currency'], 'amount' => $data['amount'],
                'received_at' => $data['received_at'], 'payment_method' => $data['payment_method'],
                'evidence_reference' => $data['evidence_reference'], 'provider_name' => $data['provider_name'] ?? null,
                'bank_transaction_reference' => $data['bank_transaction_reference'] ?? null, 'deposit_reference' => $data['deposit_reference'] ?? null,
                'clearing_date' => $data['clearing_date'] ?? null, 'payment_notes' => $data['payment_notes'] ?? null, 'status' => 'pending_reconciliation',
                'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['allocations'] as $allocation) {
                DB::table('collection_payment_allocations')->insert(['receipt_id' => $receiptId, 'invoice_id' => $allocation['invoice_id'], 'amount' => $allocation['amount'], 'created_at' => now()]);
            }
            $after = ['reference' => $data['reference'], 'client_id' => $data['client_id'], 'currency' => $data['currency'], 'amount' => $data['amount'], 'payment_method' => $data['payment_method'], 'provider_name' => $data['provider_name'] ?? null, 'bank_transaction_reference' => $data['bank_transaction_reference'] ?? null, 'deposit_reference' => $data['deposit_reference'] ?? null, 'evidence_reference' => $data['evidence_reference'], 'allocations' => $data['allocations'], 'status' => 'pending_reconciliation'];
            DB::table('collection_payment_events')->insert(['receipt_id' => $receiptId, 'actor_id' => $request->user()->id, 'event' => 'receipt_recorded', 'after_state' => json_encode($after), 'created_at' => now()]);
            $audit->record('collections.receipt.recorded', null, ['receipt_id' => $receiptId, ...$after]);

            return DB::table('collection_payment_receipts')->where('id', $receiptId)->first();
        });

        return response()->json(['message' => 'Payment receipt recorded and awaiting reconciliation.', 'data' => $receipt], 201);
    }

    public function reconcile(Request $request, int $receipt, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.reconcile') && $request->user()->hasRole('Finance'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['reconcile', 'reject'])], 'note' => ['required', 'string', 'min:5', 'max:2000']]);
        $updated = DB::transaction(function () use ($request, $receipt, $data, $audit): object {
            $record = DB::table('collection_payment_receipts')->where('id', $receipt)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless($record->status === 'pending_reconciliation', 409, 'Only pending receipts can be reconciled.');
            abort_unless((int) $record->recorded_by !== (int) $request->user()->id, 403, 'The receipt recorder cannot reconcile the same receipt.');
            $allocations = DB::table('collection_payment_allocations as a')->join('billing_invoices as i', 'i.id', '=', 'a.invoice_id')->where('a.receipt_id', $receipt)->select('a.*', 'i.workflow_status', 'i.currency', 'i.total')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                abort_unless($allocation->workflow_status === 'issued' && $allocation->currency === $record->currency, 409, 'The linked invoice is no longer eligible for this receipt.');
                $allocatedTotal = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('a.invoice_id', $allocation->invoice_id)->whereIn('r.status', ['pending_reconciliation', 'reconciled'])->sum('a.amount');
                abort_if($this->cents($allocatedTotal) > $this->cents($allocation->total), 409, 'This invoice has allocations exceeding its total.');
            }
            $bankMatches = DB::table('collection_bank_transactions')->where('receipt_id', $receipt)->where('status', 'matched')->lockForUpdate()->get();
            $status = $data['decision'] === 'reconcile' ? 'reconciled' : 'rejected';
            DB::table('collection_payment_receipts')->where('id', $receipt)->update(['status' => $status, 'reconciled_by' => $request->user()->id, 'reconciled_at' => now(), 'reconciliation_note' => $data['note'], 'updated_at' => now()]);
            foreach ($bankMatches as $bank) {
                DB::table('collection_bank_transactions')->where('id', $bank->id)->update(['status' => $status === 'reconciled' ? 'reconciled' : 'exception', 'updated_at' => now()]);
            }
            $before = ['status' => $record->status];
            $after = ['status' => $status, 'note' => $data['note'], 'bank_transactions' => $bankMatches->pluck('id')->all(), 'allocations' => $allocations->map(fn ($allocation) => ['invoice_id' => $allocation->invoice_id, 'amount' => $allocation->amount])->all()];
            DB::table('collection_payment_events')->insert(['receipt_id' => $receipt, 'actor_id' => $request->user()->id, 'event' => 'receipt_'.$status, 'before_state' => json_encode($before), 'after_state' => json_encode($after), 'reason' => $data['note'], 'created_at' => now()]);
            $audit->record('collections.receipt.'.$status, null, ['receipt_id' => $receipt, 'before' => $before, 'after' => $after]);

            return DB::table('collection_payment_receipts')->where('id', $receipt)->first();
        });

        return response()->json(['message' => $updated->status === 'reconciled' ? 'Receipt reconciled. Allocated amounts now count toward collections.' : 'Receipt rejected. Its invoice allocations are released.', 'data' => $updated]);
    }

    public function history(Request $request, int $receipt)
    {
        abort_unless($request->user()->can('collections.history.view'), 403);
        abort_unless(DB::table('collection_payment_receipts')->where('id', $receipt)->exists(), 404);

        return response()->json(['data' => [
            'receipt' => $this->receiptQuery()->where('r.id', $receipt)->first(),
            'allocations' => DB::table('collection_payment_allocations as a')->join('billing_invoices as i', 'i.id', '=', 'a.invoice_id')->where('a.receipt_id', $receipt)->select('a.amount', 'i.id as invoice_id', 'i.reference', 'i.official_number', 'i.currency')->get(),
            'events' => DB::table('collection_payment_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')->where('e.receipt_id', $receipt)->select('e.*', 'u.name as actor')->orderBy('e.created_at')->get(),
        ]]);
    }

    public function ledger(Request $request)
    {
        abort_unless($request->user()->can('collections.view'), 403);
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:today'],
        ]);
        $client = DB::table('clients')->where('id', $data['client_id'])->first();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null;
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $invoices = DB::table('billing_invoices')->leftJoin('shipments', 'shipments.id', '=', 'billing_invoices.shipment_id')
            ->where('billing_invoices.client_id', $client->id)->where('billing_invoices.workflow_status', 'issued')
            ->where('billing_invoices.issued_at', '<=', $to)->select('billing_invoices.*', 'shipments.job_number')->orderBy('billing_invoices.issued_at')->get();
        $invoiceIds = $invoices->pluck('id');
        $payments = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->join('billing_invoices as i', 'i.id', '=', 'a.invoice_id')
            ->where('r.client_id', $client->id)->where('r.status', 'reconciled')->where('r.reconciled_at', '<=', $to)->where('a.created_at', '<=', $to)
            ->when($invoiceIds->isNotEmpty(), fn ($q) => $q->whereIn('a.invoice_id', $invoiceIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->select('r.id as receipt_id', 'r.reference as payment_reference', 'r.reconciled_at as effective_at', 'r.currency', 'i.reference as invoice_reference', 'a.amount')
            ->orderBy('r.reconciled_at')->get();
        $credits = DB::table('collection_payment_receipts as r')->leftJoinSub(DB::table('collection_payment_allocations')->where('created_at', '<=', $to)->select('receipt_id')->selectRaw('SUM(amount) as allocated')->groupBy('receipt_id'), 'a', fn ($join) => $join->on('a.receipt_id', '=', 'r.id'))
            ->where('r.client_id', $client->id)->where('r.status', 'reconciled')->where('r.reconciled_at', '<=', $to)->whereRaw('r.amount > COALESCE(a.allocated, 0)')
            ->select('r.reference', 'r.reconciled_at', 'r.currency')->selectRaw('(r.amount - COALESCE(a.allocated, 0)) as unapplied')->get();
        $invoiceRows = $invoices->map(fn ($invoice) => [
            'type' => 'invoice', 'date' => $invoice->issued_at, 'reference' => $invoice->official_number ?: $invoice->reference,
            'description' => 'Issued invoice'.($invoice->job_number ? ' · '.$invoice->job_number : ''), 'currency' => $invoice->currency,
            'debit' => $invoice->total, 'credit' => '0.00', 'balance_effect' => $invoice->total,
            'invoice_id' => $invoice->id, 'shipment' => $invoice->job_number,
        ]);
        $paymentRows = $payments->map(fn ($payment) => [
            'type' => 'payment', 'date' => $payment->effective_at, 'reference' => $payment->payment_reference,
            'description' => 'Verified allocation · '.$payment->invoice_reference, 'currency' => $payment->currency,
            'debit' => '0.00', 'credit' => $payment->amount, 'balance_effect' => '-'.$payment->amount,
            'invoice_id' => null, 'shipment' => null,
        ]);
        $creditRows = $credits->map(fn ($credit) => [
            'type' => 'unapplied_credit', 'date' => $credit->reconciled_at, 'reference' => $credit->reference,
            'description' => 'Verified unapplied customer credit', 'currency' => $credit->currency,
            'debit' => '0.00', 'credit' => $credit->unapplied, 'balance_effect' => '-'.$credit->unapplied,
            'invoice_id' => null, 'shipment' => null,
        ]);
        $allRows = $invoiceRows->concat($paymentRows)->concat($creditRows)->sortBy('date')->values();
        $opening = $from ? $allRows->filter(fn ($row) => Carbon::parse($row['date'])->lt($from))->groupBy('currency')->map(fn ($rows) => $this->money($rows->sum(fn ($row) => $this->cents($row['balance_effect'])))) : collect();
        $rows = $from ? $allRows->filter(fn ($row) => Carbon::parse($row['date'])->gte($from))->values() : $allRows;
        $closing = $allRows->groupBy('currency')->map(fn ($items) => $this->money($items->sum(fn ($row) => $this->cents($row['balance_effect']))));

        return response()->json(['data' => ['client' => $client, 'from' => $from?->toDateString(), 'to' => $to->toDateString(), 'opening' => $opening, 'transactions' => $rows, 'closing' => $closing]]);
    }

    public function followUps(Request $request)
    {
        abort_unless($request->user()->can('collections.followups.view'), 403);
        $data = $request->validate(['status' => ['nullable', Rule::in(['scheduled', 'contacted', 'promise_to_pay', 'awaiting_response', 'escalated', 'resolved'])]]);
        $rows = DB::table('collection_follow_ups as f')->join('clients as c', 'c.id', '=', 'f.client_id')->join('billing_invoices as i', 'i.id', '=', 'f.invoice_id')->join('users as u', 'u.id', '=', 'f.assigned_to')->join('users as creator', 'creator.id', '=', 'f.created_by')
            ->when(isset($data['status']), fn ($q) => $q->where('f.status', $data['status']))
            ->select('f.*', 'c.company_name', 'i.reference as invoice_reference', 'i.currency', 'u.name as assigned_name', 'creator.name as created_by_name')
            ->orderByRaw("CASE WHEN f.status IN ('resolved') THEN 1 ELSE 0 END")->orderBy('f.follow_up_date')->paginate(25);

        return response()->json(['data' => $rows]);
    }

    public function createFollowUp(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.followups.manage'), 403);
        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:billing_invoices,id'], 'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'follow_up_date' => ['required', 'date'], 'contact_method' => ['required', 'string', 'max:40'], 'notes' => ['required', 'string', 'min:3', 'max:5000'],
            'promised_payment_date' => ['nullable', 'date'], 'promised_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'next_action' => ['nullable', 'string', 'max:2000'], 'status' => ['required', Rule::in(['scheduled', 'contacted', 'promise_to_pay', 'awaiting_response', 'escalated', 'resolved'])],
        ]);
        $invoice = DB::table('billing_invoices')->where('id', $data['invoice_id'])->where('workflow_status', 'issued')->first();
        abort_unless($invoice, 422, 'Follow-ups can only be linked to issued invoices.');
        $id = DB::table('collection_follow_ups')->insertGetId(['client_id' => $invoice->client_id, ...$data, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $audit->record('collections.followup.created', null, ['follow_up_id' => $id, 'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'status' => $data['status']]);

        return response()->json(['data' => DB::table('collection_follow_ups')->where('id', $id)->first()], 201);
    }

    public function updateFollowUp(Request $request, int $followUp, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.followups.manage'), 403);
        $data = $request->validate([
            'assigned_to' => ['sometimes', 'required', 'integer', 'exists:users,id'], 'follow_up_date' => ['sometimes', 'required', 'date'],
            'contact_method' => ['sometimes', 'required', 'string', 'max:40'], 'notes' => ['sometimes', 'required', 'string', 'min:3', 'max:5000'],
            'promised_payment_date' => ['sometimes', 'nullable', 'date'], 'promised_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:2000'], 'status' => ['sometimes', 'required', Rule::in(['scheduled', 'contacted', 'promise_to_pay', 'awaiting_response', 'escalated', 'resolved'])],
        ]);
        $updated = DB::transaction(function () use ($followUp, $data, $audit): object {
            $row = DB::table('collection_follow_ups')->where('id', $followUp)->lockForUpdate()->firstOrFail();
            DB::table('collection_follow_ups')->where('id', $followUp)->update([...$data, 'updated_at' => now()]);
            $audit->record('collections.followup.updated', null, ['follow_up_id' => $followUp, 'before' => ['status' => $row->status, 'assigned_to' => $row->assigned_to], 'after' => $data]);

            return DB::table('collection_follow_ups')->where('id', $followUp)->first();
        });

        return response()->json(['data' => $updated]);
    }

    public function applyCredit(Request $request, int $receipt, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.record') && $request->user()->hasRole('Accounting'), 403);
        $data = $request->validate(['allocations' => ['required', 'array', 'min:1', 'max:100'], 'allocations.*.invoice_id' => ['required', 'integer', 'distinct', 'exists:billing_invoices,id'], 'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2']]);
        DB::transaction(function () use ($request, $receipt, $data, $audit): void {
            $record = DB::table('collection_payment_receipts')->where('id', $receipt)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'reconciled', 409, 'Only verified receipts can be allocated as customer credit.');
            $already = DB::table('collection_payment_allocations')->where('receipt_id', $receipt)->sum('amount');
            $requested = collect($data['allocations'])->sum(fn ($row) => $this->cents($row['amount']));
            abort_if($this->cents($already) + $requested > $this->cents($record->amount), 422, 'Allocations exceed the verified unapplied credit.');
            $before = [];
            foreach ($data['allocations'] as $allocation) {
                $invoice = DB::table('billing_invoices')->where('id', $allocation['invoice_id'])->lockForUpdate()->first();
                abort_unless($invoice && $invoice->workflow_status === 'issued' && (int) $invoice->client_id === (int) $record->client_id && $invoice->currency === $record->currency, 422, 'Credit must be allocated to an issued invoice for the same customer and currency.');
                $held = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('a.invoice_id', $invoice->id)->whereIn('r.status', ['pending_reconciliation', 'reconciled'])->sum('a.amount');
                abort_if($this->cents($held) + $this->cents($allocation['amount']) > $this->cents($invoice->total), 422, 'Credit allocation exceeds the remaining invoice balance.');
                DB::table('collection_payment_allocations')->insert(['receipt_id' => $receipt, 'invoice_id' => $invoice->id, 'amount' => $allocation['amount'], 'created_at' => now()]);
                $before[] = ['invoice_id' => $invoice->id, 'amount' => $allocation['amount']];
            }
            DB::table('collection_payment_events')->insert(['receipt_id' => $receipt, 'actor_id' => $request->user()->id, 'event' => 'credit_allocated', 'before_state' => json_encode(['unapplied_before' => $this->money($this->cents($record->amount) - $this->cents($already))]), 'after_state' => json_encode(['allocations' => $before]), 'created_at' => now()]);
            $audit->record('collections.credit.allocated', null, ['receipt_id' => $receipt, 'allocations' => $before]);
        });

        return response()->json(['message' => 'Verified customer credit allocated.', 'data' => ['receipt_id' => $receipt]]);
    }

    public function bankTransactions(Request $request)
    {
        abort_unless($request->user()->can('collections.reconcile') && $request->user()->hasRole('Finance'), 403);
        $rows = DB::table('collection_bank_transactions as b')->leftJoin('collection_payment_receipts as r', 'r.id', '=', 'b.receipt_id')->leftJoin('users as u', 'u.id', '=', 'b.entered_by')
            ->select('b.*', 'r.reference as payment_reference', 'u.name as entered_by_name')->orderByDesc('b.transaction_date')->paginate(25);

        return response()->json(['data' => $rows]);
    }

    public function enterBankTransaction(Request $request, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.reconcile') && $request->user()->hasRole('Finance'), 403);
        $data = $request->validate([
            'statement_key' => ['required', 'string', 'max:180', 'unique:collection_bank_transactions,statement_key'],
            'bank_account_reference' => ['required', 'string', 'max:120'], 'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'value_date' => ['nullable', 'date', 'after_or_equal:transaction_date', 'before_or_equal:today'],
            'transaction_reference' => ['required', 'string', 'max:180'], 'credit_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'counterparty' => ['nullable', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $id = DB::table('collection_bank_transactions')->insertGetId([...$data, 'status' => 'unmatched', 'entered_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $audit->record('collections.bank_transaction.entered', null, ['bank_transaction_id' => $id, 'statement_key' => $data['statement_key'], 'transaction_reference' => $data['transaction_reference'], 'currency' => $data['currency'], 'credit_amount' => $data['credit_amount']]);

        return response()->json(['message' => 'Statement credit entered for reconciliation.', 'data' => DB::table('collection_bank_transactions')->where('id', $id)->first()], 201);
    }

    public function matchBankTransaction(Request $request, int $transaction, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.reconcile') && $request->user()->hasRole('Finance'), 403);
        $data = $request->validate(['receipt_id' => ['required', 'integer', 'exists:collection_payment_receipts,id'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        DB::transaction(function () use ($request, $transaction, $data, $audit): void {
            $bank = DB::table('collection_bank_transactions')->where('id', $transaction)->lockForUpdate()->firstOrFail();
            $receipt = DB::table('collection_payment_receipts')->where('id', $data['receipt_id'])->lockForUpdate()->firstOrFail();
            abort_unless($bank->status === 'unmatched' && $receipt->status === 'pending_reconciliation', 409, 'Only unmatched statement credits and pending receipts can be paired.');
            abort_unless($bank->currency === $receipt->currency && $this->cents($bank->credit_amount) === $this->cents($receipt->amount), 422, 'Statement credit and receipt amount/currency must match exactly.');
            if ($receipt->bank_transaction_reference) {
                abort_unless(hash_equals($receipt->bank_transaction_reference, $bank->transaction_reference), 422, 'The statement reference does not match the recorded payment reference.');
            }
            DB::table('collection_bank_transactions')->where('id', $transaction)->update(['status' => 'matched', 'receipt_id' => $receipt->id, 'matched_by' => $request->user()->id, 'matched_at' => now(), 'updated_at' => now()]);
            DB::table('collection_payment_events')->insert(['receipt_id' => $receipt->id, 'actor_id' => $request->user()->id, 'event' => 'bank_statement_matched', 'after_state' => json_encode(['bank_transaction_id' => $bank->id, 'transaction_reference' => $bank->transaction_reference]), 'reason' => $data['reason'], 'created_at' => now()]);
            $audit->record('collections.bank_transaction.matched', null, ['bank_transaction_id' => $bank->id, 'receipt_id' => $receipt->id, 'reason' => $data['reason']]);
        });

        return response()->json(['message' => 'Statement credit matched. Finance must still complete receipt verification.']);
    }

    public function reverse(Request $request, int $receipt, AuditLogger $audit)
    {
        abort_unless($request->user()->can('collections.reverse') && $request->user()->hasRole('Finance'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        DB::transaction(function () use ($request, $receipt, $data, $audit): void {
            $record = DB::table('collection_payment_receipts')->where('id', $receipt)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'reconciled', 409, 'Only verified receipts can be reversed.');
            $allocations = DB::table('collection_payment_allocations')->where('receipt_id', $receipt)->lockForUpdate()->get();
            if ($allocations->isNotEmpty()) {
                DB::table('billing_invoices')->whereIn('id', $allocations->pluck('invoice_id'))->orderBy('id')->lockForUpdate()->get();
            }
            $now = now();
            DB::table('collection_payment_reversals')->insert(['receipt_id' => $receipt, 'amount' => $record->amount, 'currency' => $record->currency, 'reason' => $data['reason'], 'reversed_by' => $request->user()->id, 'reversed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('collection_payment_receipts')->where('id', $receipt)->update(['status' => 'reversed', 'updated_at' => $now]);
            DB::table('collection_payment_events')->insert(['receipt_id' => $receipt, 'actor_id' => $request->user()->id, 'event' => 'receipt_reversed', 'before_state' => json_encode(['status' => 'reconciled', 'amount' => $record->amount, 'currency' => $record->currency]), 'after_state' => json_encode(['status' => 'reversed']), 'reason' => $data['reason'], 'created_at' => $now]);
            $audit->record('collections.receipt.reversed', null, ['receipt_id' => $receipt, 'amount' => $record->amount, 'currency' => $record->currency, 'reason' => $data['reason']]);
        });

        return response()->json(['message' => 'Receipt reversed with an immutable audit record. No refund or bank transfer was initiated.']);
    }

    private function issuedInvoices()
    {
        return DB::table('billing_invoices')->join('clients', 'clients.id', '=', 'billing_invoices.client_id')->leftJoin('shipments', 'shipments.id', '=', 'billing_invoices.shipment_id')->where('billing_invoices.workflow_status', 'issued')->select('billing_invoices.id', 'billing_invoices.reference', 'billing_invoices.official_number', 'billing_invoices.client_id', 'clients.company_name', 'billing_invoices.shipment_id', 'shipments.job_number', 'billing_invoices.currency', 'billing_invoices.total', 'billing_invoices.invoice_date', 'billing_invoices.due_date');
    }

    private function receivableRows($invoices, ?string $asOf = null)
    {
        $ids = $invoices->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }
        $totalsQuery = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->leftJoin('collection_payment_reversals as reversal', 'reversal.receipt_id', '=', 'r.id')->whereIn('a.invoice_id', $ids);
        if ($asOf) {
            $totalsQuery->whereIn('r.status', ['pending_reconciliation', 'reconciled', 'rejected', 'reversed'])->whereDate('r.created_at', '<=', $asOf);
            $totalsQuery->select('a.invoice_id')->selectRaw("SUM(CASE WHEN r.status IN ('reconciled', 'reversed') AND DATE(r.reconciled_at) <= ? AND (reversal.id IS NULL OR DATE(reversal.reversed_at) > ?) THEN a.amount ELSE 0 END) as collected, SUM(CASE WHEN r.status = 'pending_reconciliation' OR (r.status IN ('reconciled', 'rejected', 'reversed') AND DATE(r.reconciled_at) > ?) THEN a.amount ELSE 0 END) as pending_amount", [$asOf, $asOf, $asOf]);
        } else {
            $totalsQuery->whereIn('r.status', ['pending_reconciliation', 'reconciled'])->select('a.invoice_id')->selectRaw("SUM(CASE WHEN r.status = 'reconciled' THEN a.amount ELSE 0 END) as collected, SUM(CASE WHEN r.status = 'pending_reconciliation' THEN a.amount ELSE 0 END) as pending_amount");
        }
        $totals = $totalsQuery->groupBy('a.invoice_id')->get()->keyBy('invoice_id');

        return $invoices->map(function ($invoice) use ($totals, $asOf): array {
            $allocated = $totals->get($invoice->id);
            $collected = $this->cents($allocated->collected ?? 0);
            $pending = $this->cents($allocated->pending_amount ?? 0);
            $outstanding = max(0, $this->cents($invoice->total) - $collected);
            $days = $invoice->due_date ? Carbon::parse($asOf ?: now()->toDateString())->startOfDay()->diffInDays(Carbon::parse($invoice->due_date)->startOfDay(), false) * -1 : null;
            $bucket = $invoice->due_date === null ? 'no_due_date' : ($days <= 0 ? 'not_due' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90'))));

            return ['id' => $invoice->id, 'reference' => $invoice->reference, 'official_number' => $invoice->official_number, 'client_id' => $invoice->client_id, 'company_name' => $invoice->company_name, 'shipment_id' => $invoice->shipment_id, 'job_number' => $invoice->job_number, 'currency' => $invoice->currency, 'total' => $invoice->total, 'invoice_date' => $invoice->invoice_date, 'due_date' => $invoice->due_date, 'collected' => $this->money($collected), 'pending_amount' => $this->money($pending), 'outstanding' => $this->money($outstanding), 'collection_status' => $outstanding === 0 ? 'settled' : ($collected > 0 ? 'partially_collected' : 'unpaid'), 'aging_days' => max(0, $days ?? 0), 'aging_bucket' => $bucket];
        });
    }

    private function receiptQuery()
    {
        $allocations = DB::table('collection_payment_allocations')->select('receipt_id')->selectRaw('SUM(amount) as allocated')->groupBy('receipt_id');

        return DB::table('collection_payment_receipts as r')->join('clients as c', 'c.id', '=', 'r.client_id')->join('users as recorder', 'recorder.id', '=', 'r.recorded_by')->leftJoin('users as reconciler', 'reconciler.id', '=', 'r.reconciled_by')->leftJoinSub($allocations, 'allocation_totals', fn ($join) => $join->on('allocation_totals.receipt_id', '=', 'r.id'))->select('r.id', 'r.reference', 'r.client_id', 'c.company_name', 'r.currency', 'r.amount', 'r.received_at', 'r.payment_method', 'r.evidence_reference', 'r.provider_name', 'r.bank_transaction_reference', 'r.deposit_reference', 'r.clearing_date', 'r.payment_notes', 'r.status', 'r.recorded_by', 'recorder.name as recorded_by_name', 'r.reconciled_by', 'reconciler.name as reconciled_by_name', 'r.reconciled_at', 'r.reconciliation_note')->selectRaw('COALESCE(allocation_totals.allocated, 0) as allocated_amount')->selectRaw('CASE WHEN r.status = ? AND r.amount > COALESCE(allocation_totals.allocated, 0) THEN r.amount - COALESCE(allocation_totals.allocated, 0) ELSE 0 END as unapplied_credit', ['reconciled']);
    }

    private function cents(mixed $value): int
    {
        $decimal = (string) $value;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $decimal, $parts)) {
            return 0;
        }
        $amount = ((int) $parts[2] * 100) + (int) str_pad($parts[3] ?? '', 2, '0');

        return ($parts[1] ?? '') === '-' ? -$amount : $amount;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
