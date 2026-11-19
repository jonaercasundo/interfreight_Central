<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class ShipmentClosingService
{
    public function detail(int $shipmentId): array
    {
        $shipment = DB::table('shipments as s')->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->where('s.id', $shipmentId)->whereNull('s.archived_at')
            ->select('s.*', 'c.company_name as client_name')->first();
        abort_unless($shipment, 404);

        $milestones = DB::table('shipment_completion_milestones')->where('shipment_id', $shipmentId)->get()->keyBy('dimension');
        $source = $this->sourceFacts($shipmentId, $shipment);
        $dimensions = [];
        foreach (['operations', 'billing', 'collections', 'expense_reconciliation'] as $dimension) {
            $row = $milestones->get($dimension);
            $blockers = $source[$dimension]['blockers'];
            $status = $row?->status ?? ($blockers ? 'blocked' : 'not_started');
            if ($status === 'completed' && $blockers) {
                $status = 'blocked';
            }
            $dimensions[$dimension] = [
                'status' => $status,
                'verified_at' => $row?->verified_at,
                'verified_by' => $row?->verified_by,
                'notes' => $row?->notes,
                'lock_version' => (int) ($row?->lock_version ?? 1),
                'blockers' => $blockers,
                'facts' => $source[$dimension]['facts'],
            ];
        }

        $financial = $source['financial'];
        $financialBlockers = collect($dimensions)->flatMap(fn ($d) => $d['blockers'])->values()->all();
        $financialBlockers = [...$financialBlockers, ...$financial['blockers']];
        foreach ($dimensions as $name => $dimension) {
            if ($dimension['status'] !== 'completed') {
                $financialBlockers[] = str_replace('_', ' ', ucfirst($name)).' has not been independently verified complete.';
            }
        }
        $request = DB::table('financial_closing_requests')->where('shipment_id', $shipmentId)->orderByDesc('version')->first();

        return [
            'shipment' => ['id' => $shipment->id, 'job_number' => $shipment->job_number ?: $shipment->reference, 'client' => $shipment->client_name ?: $shipment->customer_name, 'service' => $shipment->service_type, 'mode' => $shipment->mode, 'department' => $shipment->originating_department, 'currency' => $shipment->currency],
            'milestones' => $dimensions,
            'financial' => $financial,
            'closing' => ['status' => $request->status ?? 'open', 'version' => $request->version ?? 0, 'lock_version' => (int) ($request->lock_version ?? 1), 'blockers' => array_values(array_unique($financialBlockers)), 'request_id' => $request->id ?? null, 'submitted_at' => $request->submitted_at ?? null, 'closed_at' => $request->closed_at ?? null],
        ];
    }

    private function sourceFacts(int $shipmentId, object $shipment): array
    {
        $activePolicy = app(FinancialPolicyRegistry::class)->active();
        $openExceptions = DB::table('shipment_exceptions')->where('shipment_id', $shipmentId)->where('status', 'open')->count();
        $pendingDocs = DB::table('shipment_documents')->where('shipment_id', $shipmentId)->where('verification_status', 'pending')->count();
        $milestoneCount = DB::table('shipment_milestones')->where('shipment_id', $shipmentId)->whereNotIn('status', ['completed', 'skipped'])->count();
        $tripsPending = DB::table('shipment_transport_trips')->where('shipment_id', $shipmentId)->whereNotIn('status', ['delivered', 'completed'])->count();
        $podPending = DB::table('shipment_deliveries')->where('shipment_id', $shipmentId)->whereNotIn('pod_status', ['verified'])->count();
        $customs = DB::table('shipment_customs_processes')->where('shipment_id', $shipmentId)->first();
        $bookings = DB::table('shipment_forwarding_bookings')->where('shipment_id', $shipmentId)->get();
        $containersPending = DB::table('shipment_containers')->where('shipment_id', $shipmentId)->whereNotIn('status', ['empty_returned', 'released', 'completed'])->count();

        $opsBlockers = [];
        if ($milestoneCount) {
            $opsBlockers[] = "$milestoneCount shipment milestone(s) are not complete.";
        }
        if ($tripsPending) {
            $opsBlockers[] = "$tripsPending transport trip(s) are not delivered or completed.";
        }
        if ($podPending) {
            $opsBlockers[] = "$podPending delivery proof(s) are not verified.";
        }
        if ($customs && ! in_array($customs->status, ['released', 'not_required'], true)) {
            $opsBlockers[] = 'Customs release is not recorded.';
        }
        if ($bookings->contains(fn ($booking) => ! in_array($booking->status, ['arrived', 'completed', 'delivered'], true))) {
            $opsBlockers[] = 'A forwarding booking has no completed arrival status.';
        }
        if ($containersPending) {
            $opsBlockers[] = "$containersPending container(s) have no completed return/release status.";
        }
        if ($pendingDocs) {
            $opsBlockers[] = "$pendingDocs shipment document(s) are awaiting verification.";
        }
        if ($openExceptions) {
            $opsBlockers[] = "$openExceptions operational exception(s) remain open.";
        }

        $invoiceRows = DB::table('billing_invoices')->where('shipment_id', $shipmentId)->get();
        $issued = $invoiceRows->where('workflow_status', 'issued');
        $invoiceTotal = $issued->sum(fn ($invoice) => $this->cents($invoice->total));
        $invoicePending = $invoiceRows->whereIn('workflow_status', ['draft', 'submitted', 'approved', 'revision_requested'])->count();
        $billingBlockers = [];
        if ($invoicePending) {
            $billingBlockers[] = "$invoicePending invoice(s) are not issued or formally resolved.";
        }
        if (! $shipment->commercial_baseline_id) {
            $billingBlockers[] = 'No accepted commercial baseline is linked to this shipment.';
        }
        $unbilledCents = 0;
        if ($shipment->quotation_id) {
            $quotationLines = DB::table('quotation_lines')->where('quotation_id', $shipment->quotation_id)->get();
            foreach ($quotationLines as $line) {
                $issuedLineCents = $this->cents(DB::table('billing_invoice_lines as l')->join('billing_invoices as i', 'i.id', '=', 'l.invoice_id')->where('l.source_type', 'quotation_line')->where('l.source_id', $line->id)->where('i.workflow_status', 'issued')->sum('l.line_amount'));
                $unbilledCents += max(0, $this->cents($line->line_total) - $issuedLineCents);
            }
        }
        $authorizations = DB::table('commercial_change_authorizations')->where('shipment_id', $shipmentId)->get();
        foreach ($authorizations as $authorization) {
            $issuedLineCents = $this->cents(DB::table('billing_invoice_lines as l')->join('billing_invoices as i', 'i.id', '=', 'l.invoice_id')->where('l.source_type', 'commercial_change')->where('l.source_id', $authorization->id)->where('i.workflow_status', 'issued')->sum('l.line_amount'));
            $unbilledCents += max(0, $this->cents($authorization->approved_amount) - $issuedLineCents);
        }
        if ($unbilledCents > 0) {
            $billingBlockers[] = 'Authorized commercial value remains unbilled: '.$this->money($unbilledCents).'.';
        }
        if ($issued->contains(fn ($invoice) => $shipment->currency && $invoice->currency !== $shipment->currency)) {
            $billingBlockers[] = 'Issued invoices use a different currency from the shipment; no FX basis is configured.';
        }
        $chargePending = DB::table('additional_charge_requests')->where('shipment_id', $shipmentId)->where(function ($query): void {
            $query->whereNotIn('internal_status', ['internally_approved', 'internally_rejected'])->orWhereIn('client_status', ['pending', 'awaiting_request']);
        })->count();
        if ($chargePending) {
            $billingBlockers[] = "$chargePending additional charge request(s) need resolution.";
        }

        $paidByInvoice = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('r.status', 'reconciled')->whereIn('a.invoice_id', $issued->pluck('id'))->select('a.invoice_id')->selectRaw('SUM(a.amount) as collected')->groupBy('a.invoice_id')->pluck('collected', 'invoice_id');
        $collectedCents = $issued->sum(fn ($invoice) => min($this->cents($invoice->total), $this->cents($paidByInvoice->get($invoice->id, 0))));
        $outstandingCents = max(0, $invoiceTotal - $collectedCents);
        $pendingReceipts = DB::table('collection_payment_allocations as a')->join('collection_payment_receipts as r', 'r.id', '=', 'a.receipt_id')->where('r.status', 'pending_reconciliation')->whereIn('a.invoice_id', $issued->pluck('id'))->count();
        $collectionBlockers = [];
        if ($outstandingCents > 0) {
            $collectionBlockers[] = 'Issued invoices have an outstanding reconciled receivable balance.';
        }
        if ($pendingReceipts) {
            $collectionBlockers[] = "$pendingReceipts payment allocation(s) await reconciliation.";
        }
        if ($invoicePending) {
            $collectionBlockers[] = 'Collection completion depends on billing resolution.';
        }
        if ($issued->isEmpty() && $shipment->commercial_baseline_id) {
            $collectionBlockers[] = 'No issued receivable exists; confirm the authorized billing outcome before collection completion.';
        }

        $expenses = DB::table('actual_expenses')->where('shipment_id', $shipmentId)->get();
        $verifiedExpenses = $expenses->where('status', 'verified');
        $unverified = $expenses->whereNotIn('status', ['verified', 'rejected', 'cancelled'])->count();
        $currencies = $verifiedExpenses->pluck('currency')->unique();
        $expenseBlockers = [];
        if ($unverified) {
            $expenseBlockers[] = "$unverified expense claim(s) are not verified.";
        }
        if ($currencies->count() > 1) {
            $expenseBlockers[] = 'Verified expenses use multiple currencies without a closing FX basis.';
        }
        $expenseCurrency = $currencies->first() ?: $shipment->currency;
        $verifiedCostCents = $currencies->count() === 1 ? $verifiedExpenses->sum(fn ($expense) => $this->cents($expense->amount)) : null;
        $openAdvances = DB::table('cash_advances')->where('shipment_id', $shipmentId)->whereNotIn('status', ['liquidated', 'returned', 'rejected', 'cancelled'])->count();
        if ($openAdvances) {
            $expenseBlockers[] = "$openAdvances cash advance(s) remain unresolved.";
        }

        // There is no configured revenue-recognition event in Phases 1-9. Issued invoices and collections
        // are intentionally reported as billing/cash measures and never promoted to recognized revenue.
        $financialBlockers = [];
        if (! $activePolicy) {
            $financialBlockers[] = 'No active, independently approved Finance policy version is effective.';
        }
        $financialBlockers[] = 'Recognized revenue is not determinable: an authoritative recognition data source is not connected.';
        if ($verifiedCostCents === null) {
            $financialBlockers[] = 'Final cost is not determinable in one reporting currency.';
        }
        if ($shipment->currency && $expenseCurrency && $shipment->currency !== $expenseCurrency) {
            $financialBlockers[] = 'Shipment and expense currencies differ; no documented FX conversion basis is available.';
        }

        $baseline = $shipment->commercial_baseline_id ? DB::table('commercial_baselines')->where('id', $shipment->commercial_baseline_id)->first() : null;
        $baselineSnapshot = $baseline ? (json_decode($baseline->snapshot, true) ?: []) : [];
        $estimate = $baselineSnapshot['cost_estimate'] ?? [];
        $quotation = $baselineSnapshot['quotation'] ?? [];
        $estimateCurrency = $estimate['currency'] ?? null;
        $quotationCurrency = $quotation['currency'] ?? null;
        $estimatedCostCents = isset($estimate['estimated_cost_total']) ? $this->cents($estimate['estimated_cost_total']) : null;
        $authorizedValueCents = isset($quotation['total']) ? $this->cents($quotation['total']) : null;
        $estimatedProfitCents = $estimatedCostCents !== null && $authorizedValueCents !== null && $estimateCurrency === $quotationCurrency
            ? $authorizedValueCents - $estimatedCostCents
            : null;
        $estimatedMargin = $estimatedProfitCents !== null && $authorizedValueCents > 0
            ? (string) BigDecimal::of($estimatedProfitCents)->dividedBy($authorizedValueCents, 6, RoundingMode::HalfUp)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
            : null;

        return [
            'operations' => ['blockers' => $opsBlockers, 'facts' => ['open_exceptions' => $openExceptions, 'pending_documents' => $pendingDocs]],
            'billing' => ['blockers' => $billingBlockers, 'facts' => ['issued_invoice_amount' => $this->money($invoiceTotal), 'currency' => $issued->first()->currency ?? $shipment->currency, 'issued_invoice_count' => $issued->count()]],
            'collections' => ['blockers' => $collectionBlockers, 'facts' => ['collected_amount' => $this->money($collectedCents), 'outstanding_receivables' => $this->money($outstandingCents), 'currency' => $issued->first()->currency ?? $shipment->currency]],
            'expense_reconciliation' => ['blockers' => $expenseBlockers, 'facts' => ['verified_actual_cost' => $verifiedCostCents === null ? null : $this->money($verifiedCostCents), 'currency' => $expenseCurrency, 'verified_expenses' => $verifiedExpenses->count(), 'unresolved_advances' => $openAdvances]],
            'financial' => ['status' => 'not_yet_determinable', 'policy_status' => $activePolicy ? 'approved' : 'unconfigured', 'policy_version' => $activePolicy?->version, 'policy_version_id' => $activePolicy?->id, 'currency' => $shipment->currency, 'estimated_authorized_value' => $authorizedValueCents === null ? null : $this->money($authorizedValueCents), 'estimated_approved_cost' => $estimatedCostCents === null ? null : $this->money($estimatedCostCents), 'estimated_gross_profit' => $estimatedProfitCents === null ? null : $this->money($estimatedProfitCents), 'estimated_gross_margin_percent' => $estimatedMargin, 'recognized_revenue' => null, 'final_cost' => $verifiedCostCents === null ? null : $this->money($verifiedCostCents), 'gross_profit' => null, 'gross_margin_percent' => null, 'issued_invoice_amount' => $this->money($invoiceTotal), 'collected_amount' => $this->money($collectedCents), 'outstanding_receivables' => $this->money($outstandingCents), 'blockers' => $financialBlockers],
        ];
    }

    private function cents(mixed $amount): int
    {
        if (! is_numeric($amount)) {
            return 0;
        }
        $value = trim((string) $amount);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $cents = ((int) $whole * 100) + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $negative ? -$cents : $cents;
    }

    private function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
