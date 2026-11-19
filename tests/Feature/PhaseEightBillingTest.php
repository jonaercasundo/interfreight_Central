<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinancialPolicyRegistry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseEightBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Storage::fake('local');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function acceptedShipment(User $sales, bool $createThroughShipmentApi = false): array
    {
        $this->actingAs($sales);
        $client = $this->postJson('/api/sales/clients', ['company_name' => 'Billing Client', 'contact_name' => 'Billing Contact', 'client_type' => 'FCL'])->assertCreated()->json('data');
        $inquiry = $this->postJson('/api/sales/inquiries', ['client_id' => $client['id'], 'service_type' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Cebu', 'cargo_description' => 'Freight test', 'estimated_container_count' => 1, 'container_type' => '40HC', 'commodity_category' => 'General cargo', 'requested_ship_date' => now()->addWeeks(3)->toDateString()])->assertCreated()->json('data');
        $this->postJson("/api/sales/inquiries/{$inquiry['id']}/submit")->assertOk();
        $forecast = $this->postJson('/api/sales/forecasts', ['inquiry_id' => $inquiry['id'], 'forecast_month' => now()->addMonth()->startOfMonth()->toDateString(), 'expected_shipment_count' => 1, 'confidence' => 'probable'])->assertCreated()->json('data');
        $estimate = $this->postJson('/api/commercial/cost-estimates', ['inquiry_id' => $inquiry['id'], 'forecast_id' => $forecast['id'], 'currency' => 'PHP', 'lines' => [['category' => 'shipping_line', 'description' => 'Ocean freight cost', 'supplier' => 'Carrier', 'quantity' => '1', 'unit' => 'shipment', 'unit_cost' => '100.00', 'currency' => 'PHP', 'tax_treatment' => 'none', 'tax_rate' => '0', 'cost_type' => 'direct', 'cost_payer' => 'company', 'recoverability' => 'recoverable', 'funding_required' => true]]])->assertCreated()->json('data');
        $this->postJson("/api/commercial/cost-estimates/{$estimate['id']}/submit")->assertOk();
        $accounting = $this->user('Accounting');
        $this->actingAs($accounting)->postJson("/api/commercial/cost-estimates/{$estimate['id']}/review", ['decision' => 'approved', 'remarks' => 'Reviewed'])->assertOk();
        $quote = $this->actingAs($sales)->postJson('/api/commercial/quotations', ['cost_estimate_id' => $estimate['id'], 'service_scope' => 'FCL sea freight', 'origin' => 'Manila', 'destination' => 'Cebu', 'cargo_description' => 'Freight test', 'valid_until' => now()->addDays(10)->toDateString(), 'payment_terms' => 'Net 15', 'lines' => [['description' => 'Ocean freight service', 'quantity' => '1', 'unit' => 'shipment', 'unit_price' => '150.00', 'discount_type' => 'none', 'discount_value' => '0', 'tax_treatment' => 'none', 'tax_rate' => '0']]])->assertCreated()->json('data');
        $this->postJson("/api/commercial/quotations/{$quote['id']}/submit")->assertOk();
        $this->actingAs($accounting)->postJson("/api/commercial/quotations/{$quote['id']}/review", ['stage' => 'accounting', 'decision' => 'approved', 'remarks' => 'Reviewed'])->assertOk();
        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson("/api/commercial/quotations/{$quote['id']}/review", ['stage' => 'finance', 'decision' => 'approved', 'remarks' => 'Reviewed'])->assertOk();
        $executive = $this->user('Executive');
        $this->actingAs($executive)->postJson("/api/commercial/quotations/{$quote['id']}/approve", ['remarks' => 'Approved'])->assertOk();
        $this->actingAs($sales)->postJson("/api/commercial/quotations/{$quote['id']}/issue")->assertOk();
        $this->postJson("/api/commercial/quotations/{$quote['id']}/client-response", ['response' => 'accepted', 'client_representative' => 'Billing Contact', 'quotation_version' => 1, 'responded_at' => now()->toDateTimeString(), 'evidence_reference' => 'acceptance-ref'])->assertOk();
        $baseline = DB::table('commercial_baselines')->where('quotation_id', $quote['id'])->first();
        if ($createThroughShipmentApi) {
            $shipment = $this->actingAs($sales)->postJson('/api/shipments-workspace', [
                'source_type' => 'accepted_quotation', 'quotation_id' => $quote['id'], 'shipment_type' => 'Import', 'service_type' => 'Full Service',
                'mode' => 'Sea', 'lcl_fcl' => 'FCL', 'origin' => 'Manila', 'destination' => 'Cebu', 'cargo' => 'Freight test',
                'weight_kg' => '1.00', 'currency' => 'PHP', 'idempotency_key' => 'phase16-workflow-'.$quote['id'],
            ])->assertCreated()->json('data.id');
        } else {
            $shipment = DB::table('shipments')->insertGetId(['reference' => 'BILL-SHIP-'.uniqid(), 'job_number' => 'CAF-BILL-0001', 'client_id' => $client['id'], 'inquiry_id' => $inquiry['id'], 'quotation_id' => $quote['id'], 'commercial_baseline_id' => $baseline->id, 'created_by' => $sales->id, 'customer_name' => 'Billing Client', 'origin' => 'Manila', 'destination' => 'Cebu', 'mode' => 'Sea', 'status' => 'Booked', 'master_status' => 'open', 'source_type' => 'quotation', 'service_type' => 'FCL', 'currency' => 'PHP', 'cargo' => 'Freight test', 'weight_kg' => '1.00', 'freight_cost' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['client' => $client, 'quotation' => $quote, 'shipment_id' => $shipment];
    }

    private function issuedInvoice(): array
    {
        $context = $this->acceptedShipment($this->user('Sales'));
        $lineId = DB::table('quotation_lines')->where('quotation_id', $context['quotation']['id'])->value('id');
        $billing = $this->user('Billing');
        $invoice = $this->actingAs($billing)->postJson('/api/billing/invoices', ['shipment_id' => $context['shipment_id'], 'source_keys' => ['quotation_line:'.$lineId], 'idempotency_key' => 'phase-nine-'.uniqid()])->assertCreated()->json('data');
        DB::table('billing_invoices')->where('id', $invoice['id'])->update(['workflow_status' => 'issued', 'invoice_date' => now()->toDateString(), 'due_date' => now()->subDays(12)->toDateString(), 'issued_at' => now(), 'total' => '150.00']);

        return [...$context, 'invoice' => $invoice];
    }

    public function test_billing_endpoints_enforce_login_and_granular_permissions(): void
    {
        $this->getJson('/api/billing/dashboard')->assertUnauthorized();
        $this->getJson('/billing')->assertUnauthorized();
        $sales = $this->user('Sales');
        $this->actingAs($sales)->getJson('/api/billing/dashboard')->assertForbidden();
        $this->actingAs($sales)->getJson('/api/billing/invoices')->assertOk()->assertJsonMissingPath('data.data.0.total');
        $billing = $this->user('Billing');
        $this->actingAs($billing)->get('/billing')->assertOk();
        $this->actingAs($billing)->getJson('/api/billing/dashboard')->assertOk()->assertJsonPath('data.metrics.collection_status', 'reconciled_receipts');
    }

    public function test_phase_sixteen_workflow_uses_one_shipment_and_preserves_independent_department_milestones_and_close_blockers(): void
    {
        $sales = $this->user('Sales');
        $context = $this->acceptedShipment($sales, true);
        $shipmentId = (int) $context['shipment_id'];
        $shipmentCount = DB::table('shipments')->where('quotation_id', $context['quotation']['id'])->count();
        $this->assertSame(1, $shipmentCount);

        $administrator = $this->user('Administrator');
        $departments = [
            'Operations' => $this->user('Operations'),
            'Transport' => $this->user('Transport'),
            'Forwarding' => $this->user('Forwarding'),
        ];
        foreach ($departments as $department => $member) {
            $this->actingAs($administrator)->postJson('/api/shipments-workspace/'.$shipmentId.'/assignments', [
                'department' => $department, 'assigned_user_id' => $member->id, 'status' => 'assigned', 'remarks' => 'Synthetic Phase 16 workflow assignment.',
            ])->assertOk();
            $this->actingAs($member)->postJson('/api/shipments-workspace/'.$shipmentId.'/milestones', [
                'department' => $department, 'code' => strtoupper($department).'_COMPLETE', 'label' => $department.' synthetic completion',
                'status' => 'completed', 'idempotency_key' => 'phase16-'.$department.'-'.$shipmentId,
            ])->assertCreated();
        }
        $this->assertSame(1, DB::table('shipments')->where('id', $shipmentId)->count());
        $this->assertSame(3, DB::table('shipment_milestones')->where('shipment_id', $shipmentId)->where('status', 'completed')->count());
        $this->assertSame(3, DB::table('shipment_department_assignments')->where('shipment_id', $shipmentId)->where('status', 'completed')->count());

        $finance = $this->user('Finance');
        $closing = $this->actingAs($finance)->getJson('/api/financial-closing/shipments/'.$shipmentId)->assertOk()->json('data');
        $this->assertNotEmpty($closing['closing']['blockers']);
        $this->assertNull($closing['financial']['gross_profit'] ?? null);
        $this->assertSame('unconfigured', app(FinancialPolicyRegistry::class)->enginePolicy()['status']);
    }

    public function test_draft_uses_only_accepted_baseline_lines_and_official_issuance_fails_closed(): void
    {
        $sales = $this->user('Sales');
        $context = $this->acceptedShipment($sales);
        $billing = $this->user('Billing');
        $quotationLineId = DB::table('quotation_lines')->where('quotation_id', $context['quotation']['id'])->value('id');
        $this->actingAs($billing)->getJson('/api/billing/shipments/'.$context['shipment_id'].'/charges')->assertOk()->assertJsonPath('data.0.authorization_reference', $context['quotation']['reference'].' / line '.$quotationLineId);
        $this->postJson('/api/billing/invoices', ['shipment_id' => $context['shipment_id'], 'source_keys' => ['expense:42']])->assertUnprocessable();
        $draft = $this->postJson('/api/billing/invoices', ['shipment_id' => $context['shipment_id'], 'source_keys' => ['quotation_line:'.$quotationLineId], 'idempotency_key' => 'billing-test-once'])->assertCreated()->assertJsonPath('data.reference', 'BIL-'.now()->year.'-000001')->assertJsonPath('data.total', fn ($value): bool => (float) $value === 150.0)->json('data');
        $draftRevision = DB::table('billing_invoice_revisions')->where('invoice_id', $draft['id'])->where('revision', 1)->first();
        $this->assertNotNull($draftRevision);
        $this->assertSame(hash('sha256', $draftRevision->snapshot), $draftRevision->sha256);
        $this->get('/api/billing/invoices/'.$draft['id'].'/preview')->assertOk()->assertSee('DRAFT PREVIEW')->assertDontSee('100.00');
        $document = $this->post('/api/billing/invoices/'.$draft['id'].'/documents', ['document' => UploadedFile::fake()->create('support.pdf', 12, 'application/pdf')])->assertCreated()->json('data');
        Storage::disk('local')->assertExists($document['storage_path']);
        $salesViewer = $this->user('Sales');
        $this->actingAs($salesViewer)->get('/api/billing/documents/'.$document['id'])->assertForbidden();
        $this->actingAs($billing)->get('/api/billing/documents/'.$document['id'])->assertOk();
        $this->postJson('/api/billing/invoices', ['shipment_id' => $context['shipment_id'], 'source_keys' => ['quotation_line:'.$quotationLineId], 'idempotency_key' => 'billing-test-once'])->assertCreated()->assertJsonPath('data.id', $draft['id']);
        $this->postJson('/api/billing/invoices', ['shipment_id' => $context['shipment_id'], 'source_keys' => ['quotation_line:'.$quotationLineId], 'idempotency_key' => 'billing-test-duplicate'])->assertUnprocessable();
        $this->actingAs($billing)->postJson('/api/billing/invoices/'.$draft['id'].'/issue')->assertStatus(409)->assertJsonPath('message', 'Official invoice issuance is disabled until company tax registration, invoice format, and statutory numbering are verified.');
        config(['ifc_billing.legal_issuance_verified' => true]);
        $this->actingAs($billing)->postJson('/api/billing/invoices/'.$draft['id'].'/issue')->assertStatus(409)->assertJsonPath('message', 'A Finance and Legal approved statutory profile is required before official invoice issuance.');
        $this->postJson('/api/billing/invoices/'.$draft['id'].'/submit')->assertOk()->assertJsonPath('data.workflow_status', 'submitted');
        $this->actingAs($this->user('Administrator'))->postJson('/api/billing/invoices/'.$draft['id'].'/review', ['action' => 'approve', 'comments' => 'Administrator alone cannot approve'])->assertForbidden();
        $accounting = $this->user('Accounting');
        $this->actingAs($accounting)->postJson('/api/billing/invoices/'.$draft['id'].'/review', ['action' => 'approve', 'comments' => 'Amounts and accepted terms checked'])->assertOk()->assertJsonPath('data.workflow_status', 'approved');
        $this->assertSame(3, DB::table('billing_invoice_revisions')->where('invoice_id', $draft['id'])->count());
        $this->actingAs($billing)->postJson('/api/billing/invoices/'.$draft['id'].'/issue')->assertStatus(409);
        $this->assertSame(1, DB::table('billing_invoices')->count());
        $this->assertSame(0, DB::table('billing_invoice_lines')->where('source_type', 'expense')->count());
        $this->assertDatabaseHas('billing_invoice_events', ['invoice_id' => $draft['id'], 'event' => 'draft_created']);
        $this->assertDatabaseHas('billing_invoice_approvals', ['invoice_id' => $draft['id'], 'actor_id' => $accounting->id, 'action' => 'approve']);
    }

    public function test_administrator_role_alone_cannot_review_invoice(): void
    {
        $this->actingAs($this->user('Administrator'))->postJson('/api/billing/invoices/999/review', ['action' => 'approve', 'comments' => 'Cannot approve'])->assertForbidden();
    }

    public function test_partial_payment_counts_only_after_independent_finance_reconciliation(): void
    {
        $context = $this->issuedInvoice();
        $accounting = $this->user('Accounting');
        $receipt = ['reference' => 'BANK-P9-1001', 'client_id' => $context['client']['id'], 'currency' => 'PHP', 'amount' => '50.00', 'received_at' => now()->subDay()->toDateTimeString(), 'payment_method' => 'bank_transfer', 'evidence_reference' => 'Deposit slip DS-1001', 'allocations' => [['invoice_id' => $context['invoice']['id'], 'amount' => '50.00']]];
        $receiptId = $this->actingAs($accounting)->postJson('/api/collections/receipts', $receipt)->assertCreated()->assertJsonPath('data.status', 'pending_reconciliation')->json('data.id');
        $this->getJson('/api/collections/invoices')->assertOk()->assertJsonPath('data.data.0.collected', '0.00')->assertJsonPath('data.data.0.pending_amount', '50.00')->assertJsonPath('data.data.0.outstanding', '150.00')->assertJsonPath('data.data.0.aging_bucket', '1_30');

        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson('/api/collections/receipts/'.$receiptId.'/reconcile', ['decision' => 'reconcile', 'note' => 'Matched bank credit and supporting deposit slip.'])->assertOk();
        $this->getJson('/api/collections/invoices')->assertOk()->assertJsonPath('data.data.0.collected', '50.00')->assertJsonPath('data.data.0.outstanding', '100.00')->assertJsonPath('data.data.0.collection_status', 'partially_collected');
        $this->getJson('/api/collections/receipts')->assertOk()->assertJsonPath('data.data.0.allocations.0.reference', $context['invoice']['reference']);
        $this->assertDatabaseHas('collection_payment_events', ['receipt_id' => $receiptId, 'event' => 'receipt_reconciled', 'actor_id' => $finance->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $finance->id, 'action' => 'collections.receipt.reconciled']);
    }

    public function test_pending_allocations_reserve_balance_and_rejection_releases_it(): void
    {
        $context = $this->issuedInvoice();
        $accounting = $this->user('Accounting');
        $first = ['reference' => 'BANK-P9-2001', 'client_id' => $context['client']['id'], 'currency' => 'PHP', 'amount' => '100.00', 'received_at' => now()->subDay()->toDateTimeString(), 'payment_method' => 'bank_transfer', 'evidence_reference' => 'Deposit slip DS-2001', 'allocations' => [['invoice_id' => $context['invoice']['id'], 'amount' => '100.00']]];
        $firstReceiptId = $this->actingAs($accounting)->postJson('/api/collections/receipts', $first)->assertCreated()->json('data.id');
        $second = [...$first, 'reference' => 'BANK-P9-2002', 'evidence_reference' => 'Deposit slip DS-2002', 'allocations' => [['invoice_id' => $context['invoice']['id'], 'amount' => '60.00']]];
        $this->postJson('/api/collections/receipts', $second)->assertUnprocessable();
        $this->actingAs($this->user('Finance'))->postJson('/api/collections/receipts/'.$firstReceiptId.'/reconcile', ['decision' => 'reject', 'note' => 'Bank statement has no matching credit.'])->assertOk();
        $this->actingAs($accounting)->postJson('/api/collections/receipts', $second)->assertCreated();
        $this->getJson('/api/collections/invoices')->assertOk()->assertJsonPath('data.data.0.pending_amount', '60.00')->assertJsonPath('data.data.0.collected', '0.00');
    }

    public function test_collection_routes_enforce_role_scope_and_invoice_eligibility(): void
    {
        $this->getJson('/api/collections/dashboard')->assertUnauthorized();
        $sales = $this->user('Sales');
        $this->actingAs($sales)->get('/collections')->assertForbidden();
        $this->getJson('/api/collections/receipts')->assertForbidden();
        $context = $this->issuedInvoice();
        $accounting = $this->user('Accounting');
        $draft = DB::table('billing_invoices')->where('id', $context['invoice']['id'])->update(['workflow_status' => 'approved']);
        $payload = ['reference' => 'BANK-P9-3001', 'client_id' => $context['client']['id'], 'currency' => 'PHP', 'amount' => '10.00', 'received_at' => now()->subDay()->toDateTimeString(), 'payment_method' => 'bank_transfer', 'evidence_reference' => 'Deposit slip DS-3001', 'allocations' => [['invoice_id' => $context['invoice']['id'], 'amount' => '10.00']]];
        $this->actingAs($accounting)->postJson('/api/collections/receipts', $payload)->assertUnprocessable();
        DB::table('billing_invoices')->where('id', $context['invoice']['id'])->update(['workflow_status' => 'issued']);
        $receiptId = $this->postJson('/api/collections/receipts', $payload)->assertCreated()->json('data.id');
        $accounting->assignRole('Finance');
        $this->actingAs($accounting)->postJson('/api/collections/receipts/'.$receiptId.'/reconcile', ['decision' => 'reconcile', 'note' => 'Attempt to approve own receipt.'])->assertForbidden();
        $this->assertDatabaseHas('collection_payment_receipts', ['reference' => 'BANK-P9-3001', 'status' => 'pending_reconciliation']);
    }

    public function test_manual_statement_matching_requires_exact_credit_and_separate_verification(): void
    {
        $context = $this->issuedInvoice();
        $accounting = $this->user('Accounting');
        $receiptId = $this->actingAs($accounting)->postJson('/api/collections/receipts', ['reference' => 'BANK-P9-MATCH-1', 'client_id' => $context['client']['id'], 'currency' => 'PHP', 'amount' => '50.00', 'received_at' => now()->subDay()->toDateTimeString(), 'payment_method' => 'bank_transfer', 'bank_transaction_reference' => 'TXN-P9-1', 'evidence_reference' => 'Deposit slip TXN-P9-1', 'allocations' => [['invoice_id' => $context['invoice']['id'], 'amount' => '50.00']]])->assertCreated()->json('data.id');
        $finance = $this->user('Finance');
        $bank = ['statement_key' => 'STMT-P9-1', 'bank_account_reference' => 'OPERATING-001', 'transaction_date' => now()->subDay()->toDateString(), 'transaction_reference' => 'TXN-P9-1', 'credit_amount' => '49.00', 'currency' => 'PHP'];
        $firstBankId = $this->actingAs($finance)->postJson('/api/collections/bank-transactions', $bank)->assertCreated()->json('data.id');
        $this->postJson('/api/collections/bank-transactions/'.$firstBankId.'/match', ['receipt_id' => $receiptId, 'reason' => 'Candidate amount differs.'])->assertUnprocessable();
        $bank['statement_key'] = 'STMT-P9-2';
        $bank['credit_amount'] = '50.00';
        $secondBankId = $this->postJson('/api/collections/bank-transactions', $bank)->assertCreated()->json('data.id');
        $this->postJson('/api/collections/bank-transactions/'.$secondBankId.'/match', ['receipt_id' => $receiptId, 'reason' => 'Exact bank reference and amount match.'])->assertOk();
        $this->assertDatabaseHas('collection_bank_transactions', ['id' => $secondBankId, 'status' => 'matched']);
        $this->assertDatabaseHas('collection_payment_receipts', ['id' => $receiptId, 'status' => 'pending_reconciliation']);
        $this->postJson('/api/collections/receipts/'.$receiptId.'/reconcile', ['decision' => 'reconcile', 'note' => 'Reviewed statement line and receipt evidence.'])->assertOk();
        $this->assertDatabaseHas('collection_bank_transactions', ['id' => $secondBankId, 'status' => 'reconciled']);
    }

    public function test_followups_ledger_and_historical_aging_use_authorized_records(): void
    {
        $context = $this->issuedInvoice();
        DB::table('billing_invoices')->where('id', $context['invoice']['id'])->update(['issued_at' => now()->subWeeks(2)]);
        $secondInvoice = (array) DB::table('billing_invoices')->where('id', $context['invoice']['id'])->first();
        unset($secondInvoice['id']);
        $secondInvoice['reference'] = 'BIL-P9-CREDIT-2';
        $secondInvoice['idempotency_key'] = 'phase-nine-credit-'.uniqid();
        $secondInvoice['official_number'] = null;
        $secondInvoice['created_at'] = now();
        $secondInvoice['updated_at'] = now();
        $secondInvoiceId = DB::table('billing_invoices')->insertGetId($secondInvoice);
        $accounting = $this->user('Accounting');
        $payload = ['reference' => 'BANK-P9-LEDGER-1', 'client_id' => $context['client']['id'], 'currency' => 'PHP', 'amount' => '170.00', 'received_at' => now()->subDays(2)->toDateTimeString(), 'payment_method' => 'online_payment', 'evidence_reference' => 'Provider settlement 1', 'allocations' => [['invoice_id' => $context['invoice']['id'], 'amount' => '150.00']]];
        $receiptId = $this->actingAs($accounting)->postJson('/api/collections/receipts', $payload)->assertCreated()->json('data.id');
        $this->actingAs($this->user('Finance'))->postJson('/api/collections/receipts/'.$receiptId.'/reconcile', ['decision' => 'reconcile', 'note' => 'Provider settlement confirmed against statement.'])->assertOk();
        $this->actingAs($accounting)->getJson('/api/collections/receipts')->assertOk()->assertJsonPath('data.data.0.unapplied_credit', '20.00');
        $this->postJson('/api/collections/receipts/'.$receiptId.'/allocate-credit', ['allocations' => [['invoice_id' => $secondInvoiceId, 'amount' => '20.00']]])->assertOk();
        $this->postJson('/api/collections/receipts/'.$receiptId.'/allocate-credit', ['allocations' => [['invoice_id' => $secondInvoiceId, 'amount' => '1.00']]])->assertUnprocessable();
        $this->getJson('/api/collections/ledger?client_id='.$context['client']['id'].'&from=&to='.now()->toDateString())->assertOk()->assertJsonPath('data.closing.PHP', '130.00');
        $this->getJson('/api/collections/invoices?as_of='.now()->subDay()->toDateString())->assertOk()->assertJsonPath('data.data.0.collected', '0.00');
        $followUpId = $this->postJson('/api/collections/follow-ups', ['invoice_id' => $context['invoice']['id'], 'assigned_to' => $accounting->id, 'follow_up_date' => now()->addDay()->toDateString(), 'contact_method' => 'phone', 'notes' => 'Discuss the remaining customer credit.', 'status' => 'scheduled'])->assertCreated()->json('data.id');
        $this->patchJson('/api/collections/follow-ups/'.$followUpId, ['status' => 'contacted'])->assertOk();
        $this->getJson('/api/collections/follow-ups')->assertOk()->assertJsonPath('data.data.0.status', 'contacted');
        $this->assertDatabaseHas('collection_payment_events', ['receipt_id' => $receiptId, 'event' => 'receipt_reconciled']);
    }

    public function test_receipt_reversal_is_finance_authorized_audited_and_does_not_delete_history(): void
    {
        $context = $this->issuedInvoice();
        $accounting = $this->user('Accounting');
        $payload = ['reference' => 'BANK-P9-REVERSE-1', 'client_id' => $context['client']['id'], 'currency' => 'PHP', 'amount' => '40.00', 'received_at' => now()->subDay()->toDateTimeString(), 'payment_method' => 'cash', 'evidence_reference' => 'Cash receipt 1', 'allocations' => [['invoice_id' => $context['invoice']['id'], 'amount' => '40.00']]];
        $receiptId = $this->actingAs($accounting)->postJson('/api/collections/receipts', $payload)->assertCreated()->json('data.id');
        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson('/api/collections/receipts/'.$receiptId.'/reconcile', ['decision' => 'reconcile', 'note' => 'Cash receipt independently checked and entered.'])->assertOk();
        $this->postJson('/api/collections/receipts/'.$receiptId.'/reverse', ['reason' => 'Receipt was duplicated in the verified deposit record.'])->assertOk();
        $this->assertDatabaseHas('collection_payment_receipts', ['id' => $receiptId, 'status' => 'reversed']);
        $this->assertDatabaseHas('collection_payment_reversals', ['receipt_id' => $receiptId, 'reversed_by' => $finance->id]);
        $this->assertDatabaseHas('collection_payment_events', ['receipt_id' => $receiptId, 'event' => 'receipt_reversed']);
        $this->getJson('/api/collections/invoices')->assertOk()->assertJsonPath('data.data.0.collected', '0.00')->assertJsonPath('data.data.0.outstanding', '150.00');
        DB::table('collection_payment_reversals')->where('receipt_id', $receiptId)->update(['reversed_at' => now()->addDay()]);
        $this->getJson('/api/collections/invoices?as_of='.now()->toDateString())->assertOk()->assertJsonPath('data.data.0.collected', '40.00')->assertJsonPath('data.data.0.outstanding', '110.00');
        $this->postJson('/api/collections/receipts/'.$receiptId.'/reverse', ['reason' => 'Attempt a duplicate reversal.'])->assertStatus(409);
    }
}
