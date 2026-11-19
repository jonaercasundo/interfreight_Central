<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BudgetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function draft(User $accounting, ?string $month = null): array
    {
        return $this->actingAs($accounting)->postJson('/api/accounting/budgets', [
            'budget_month' => $month ?? now()->addMonth()->format('Y-m'), 'currency' => 'PHP', 'explanation' => 'Based on approved monthly demand.',
            'lines' => [
                ['category' => 'shipping_line', 'requested_amount' => '1250.25', 'description' => 'Carrier remittance'],
                ['category' => 'trucking_transport', 'requested_amount' => '250.00', 'description' => 'Delivery budget'],
            ],
        ])->assertCreated()->json('data');
    }

    private function approvedRequirement(User $sales, string $month, string $amount): string
    {
        $client = DB::table('clients')->insertGetId(['company_name' => 'Allocation Client', 'client_type' => 'FCL', 'sales_owner_id' => $sales->id, 'created_at' => now(), 'updated_at' => now()]);
        $inquiry = DB::table('client_inquiries')->insertGetId(['client_id' => $client, 'sales_owner_id' => $sales->id, 'service_type' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Singapore', 'cargo_description' => 'Cargo', 'status' => 'forecasted', 'created_at' => now(), 'updated_at' => now()]);
        $forecast = DB::table('shipment_forecasts')->insertGetId(['inquiry_id' => $inquiry, 'sales_owner_id' => $sales->id, 'forecast_month' => $month.'-01', 'expected_shipment_count' => 1, 'confidence' => 'confirmed', 'status' => 'active', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $estimate = DB::table('cost_estimates')->insertGetId(['reference' => 'CST-ALLOC-'.$inquiry, 'inquiry_id' => $inquiry, 'forecast_id' => $forecast, 'sales_owner_id' => $sales->id, 'version' => 1, 'created_by' => $sales->id, 'updated_by' => $sales->id, 'shipment_classification' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Singapore', 'currency' => 'PHP', 'status' => 'approved', 'funding_required_total' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        $line = DB::table('cost_estimate_lines')->insertGetId(['cost_estimate_id' => $estimate, 'category' => 'shipping_line', 'description' => 'Carrier fee', 'quantity' => '1', 'unit' => 'shipment', 'unit_cost' => $amount, 'currency' => 'PHP', 'subtotal' => $amount, 'tax_amount' => '0.00', 'line_total' => $amount, 'estimate_currency_total' => $amount, 'funding_required' => true, 'created_at' => now(), 'updated_at' => now()]);

        return 'CST-ALLOC-'.$inquiry.'-v1-L'.$line;
    }

    public function test_accounting_can_prepare_submit_finance_can_approve_and_allocation_is_capped(): void
    {
        $accounting = $this->user('Accounting');
        $requirement = $this->approvedRequirement($this->user('Sales'), now()->addMonth()->format('Y-m'), '2000.00');
        $plan = $this->draft($accounting);
        $this->assertSame('1500.25', (string) $plan['requested_total']);
        $this->postJson("/api/accounting/budgets/{$plan['id']}/submit", ['remarks' => 'Monthly plan ready'])->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->actingAs($accounting)->postJson("/api/accounting/budgets/{$plan['id']}/review", ['decision' => 'approved', 'remarks' => 'Self approval'])->assertForbidden();
        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson("/api/accounting/budgets/{$plan['id']}/review", ['decision' => 'approved', 'remarks' => 'Funding coverage reviewed'])->assertOk()->assertJsonPath('data.status', 'approved');

        $line = DB::table('budget_lines')->where('budget_plan_id', $plan['id'])->where('category', 'shipping_line')->first();
        $this->actingAs($accounting)->postJson("/api/accounting/budgets/{$plan['id']}/allocate", ['budget_line_id' => $line->id, 'requirement_source' => $requirement, 'amount' => '1200.00', 'purpose' => 'Reserve carrier payment authority'])->assertCreated();
        $this->postJson("/api/accounting/budgets/{$plan['id']}/allocate", ['budget_line_id' => $line->id, 'requirement_source' => $requirement, 'amount' => '100.26', 'purpose' => 'Exceed approved line'])->assertUnprocessable();
        $variance = $this->getJson('/api/accounting/budgets')->assertOk()->json('data.0');
        $this->assertSame('0.00', $variance['approved_request_variance']);
        $this->assertSame('300.25', $variance['approved_allocation_variance']);
        $this->assertDatabaseHas('budget_approval_steps', ['budget_plan_id' => $plan['id'], 'action' => 'approved', 'actor_id' => $finance->id]);
        $this->assertDatabaseHas('budget_audit_history', ['budget_plan_id' => $plan['id'], 'action' => 'allocation_created']);
    }

    public function test_financial_routes_are_private_and_sales_cannot_read_company_funding(): void
    {
        $this->getJson('/api/accounting/dashboard')->assertUnauthorized();
        $sales = $this->user('Sales');
        $this->actingAs($sales)->getJson('/api/accounting/dashboard')->assertForbidden();
        $this->get('/accounting/budget-plans')->assertForbidden();
        $accounting = $this->user('Accounting');
        $this->actingAs($accounting)->get('/accounting')->assertOk();
        $this->get('/accounting/budget-allocations')->assertOk();
        $this->getJson('/api/accounting/dashboard')->assertOk()->assertJsonPath('actual_expenses', 'Not integrated')->assertJsonPath('cash_position', 'Not recorded');
        $finance = $this->user('Finance');
        $this->actingAs($finance)->get('/accounting/budget-plans')->assertOk();
        $this->get('/accounting/budget-allocations')->assertForbidden();
        $administrator = $this->user('Administrator');
        $this->actingAs($administrator)->get('/accounting')->assertForbidden();
    }

    public function test_approved_budget_is_locked_and_revision_preserves_parent_snapshot(): void
    {
        $accounting = $this->user('Accounting');
        $plan = $this->draft($accounting);
        $this->postJson("/api/accounting/budgets/{$plan['id']}/submit", [])->assertOk();
        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson("/api/accounting/budgets/{$plan['id']}/review", ['decision' => 'approved', 'remarks' => 'Approved plan'])->assertOk();
        $lineBefore = DB::table('budget_lines')->where('budget_plan_id', $plan['id'])->first();
        $this->actingAs($accounting)->putJson("/api/accounting/budgets/{$plan['id']}", ['lines' => [['category' => 'shipping_line', 'requested_amount' => '9999.00']]])->assertStatus(409);
        $revision = $this->postJson("/api/accounting/budgets/{$plan['id']}/revise", ['reason' => 'Carrier rates updated'])->assertCreated()->json('data');
        $this->assertSame(2, $revision['version']);
        $this->assertSame('superseded', DB::table('monthly_budget_plans')->where('id', $plan['id'])->value('status'));
        $this->assertSame('1250.25', (string) DB::table('budget_lines')->where('budget_plan_id', $plan['id'])->where('category', $lineBefore->category)->value('approved_amount'));
        $this->assertSame('1250.25', (string) DB::table('budget_lines')->where('budget_plan_id', $revision['id'])->where('category', $lineBefore->category)->value('requested_amount'));
    }

    public function test_dashboard_shortfall_and_available_authority_follow_real_allocations(): void
    {
        $month = now()->format('Y-m');
        $requirement = $this->approvedRequirement($this->user('Sales'), $month, '100.00');
        $accounting = $this->user('Accounting');
        $plan = $this->draft($accounting, $month);
        $this->postJson("/api/accounting/budgets/{$plan['id']}/submit", [])->assertOk();
        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson("/api/accounting/budgets/{$plan['id']}/review", ['decision' => 'approved', 'remarks' => 'Approved for the month'])->assertOk();
        $dashboard = $this->actingAs($accounting)->getJson('/api/accounting/dashboard')->assertOk()->json();
        $this->assertSame('100.00', $dashboard['forecast_shortfall'][0]['shortfall']);
        $this->assertSame('1500.25', $dashboard['available_to_allocate'][0]['amount']);
        $line = DB::table('budget_lines')->where('budget_plan_id', $plan['id'])->where('category', 'shipping_line')->first();
        $this->postJson("/api/accounting/budgets/{$plan['id']}/allocate", ['budget_line_id' => $line->id, 'requirement_source' => $requirement, 'amount' => '25.00', 'purpose' => 'Part cover verified estimate funding need'])->assertCreated();
        $dashboard = $this->getJson('/api/accounting/dashboard')->assertOk()->json();
        $this->assertSame('75.00', $dashboard['forecast_shortfall'][0]['shortfall']);
        $this->assertSame('1475.25', $dashboard['available_to_allocate'][0]['amount']);
        $this->postJson("/api/accounting/budgets/{$plan['id']}/close", ['remarks' => 'Monthly cycle closed'])->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_funding_demand_uses_estimate_costs_and_excludes_quotation_selling_prices(): void
    {
        $salesOwner = $this->user('Sales');
        $clientId = DB::table('clients')->insertGetId(['company_name' => 'Test Client', 'client_type' => 'FCL', 'sales_owner_id' => $salesOwner->id, 'created_at' => now(), 'updated_at' => now()]);
        $inquiryId = DB::table('client_inquiries')->insertGetId(['client_id' => $clientId, 'sales_owner_id' => $salesOwner->id, 'service_type' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Singapore', 'cargo_description' => 'Goods', 'status' => 'forecasted', 'created_at' => now(), 'updated_at' => now()]);
        $month = now()->startOfMonth()->toDateString();
        $forecastId = DB::table('shipment_forecasts')->insertGetId(['inquiry_id' => $inquiryId, 'sales_owner_id' => $salesOwner->id, 'forecast_month' => $month, 'expected_shipment_count' => 3, 'confidence' => 'tentative', 'status' => 'active', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $estimateId = DB::table('cost_estimates')->insertGetId(['reference' => 'CST-TEST-0001', 'inquiry_id' => $inquiryId, 'forecast_id' => $forecastId, 'sales_owner_id' => $salesOwner->id, 'version' => 1, 'created_by' => $salesOwner->id, 'updated_by' => $salesOwner->id, 'shipment_classification' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Singapore', 'currency' => 'PHP', 'status' => 'approved', 'funding_required_total' => '100.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cost_estimate_lines')->insert(['cost_estimate_id' => $estimateId, 'category' => 'shipping_line', 'description' => 'Carrier fee', 'quantity' => '1', 'unit' => 'shipment', 'unit_cost' => '100.0000', 'currency' => 'PHP', 'subtotal' => '100.00', 'tax_amount' => '0.00', 'line_total' => '100.00', 'estimate_currency_total' => '100.00', 'funding_required' => true, 'created_at' => now(), 'updated_at' => now()]);
        $draftEstimateId = DB::table('cost_estimates')->insertGetId(['reference' => 'CST-TEST-0001-V2', 'inquiry_id' => $inquiryId, 'forecast_id' => $forecastId, 'sales_owner_id' => $salesOwner->id, 'version' => 2, 'created_by' => $salesOwner->id, 'updated_by' => $salesOwner->id, 'shipment_classification' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Singapore', 'currency' => 'PHP', 'status' => 'draft', 'funding_required_total' => '120.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cost_estimate_lines')->insert(['cost_estimate_id' => $draftEstimateId, 'category' => 'shipping_line', 'description' => 'Revised carrier fee pending approval', 'quantity' => '1', 'unit' => 'shipment', 'unit_cost' => '120.0000', 'currency' => 'PHP', 'subtotal' => '120.00', 'tax_amount' => '0.00', 'line_total' => '120.00', 'estimate_currency_total' => '120.00', 'funding_required' => true, 'created_at' => now(), 'updated_at' => now()]);
        $accounting = $this->user('Accounting');
        $result = $this->actingAs($accounting)->getJson('/api/accounting/funding-requirements?month='.now()->format('Y-m'))->assertOk()->json('data');
        $this->assertCount(2, $result);
        $approved = collect($result)->firstWhere('source_status', 'approved_estimate');
        $provisional = collect($result)->firstWhere('source_status', 'provisional_estimate');
        $this->assertSame('300.00', $approved['estimated_amount']);
        $this->assertSame('approved_estimate', $approved['source_status']);
        $this->assertSame('360.00', $provisional['estimated_amount']);
        $this->assertSame('provisional_estimate', $provisional['source_status']);
        $dashboard = $this->getJson('/api/accounting/dashboard')->assertOk()->json();
        $this->assertSame('300.00', $dashboard['demand']['totals_by_currency'][0]['amount']);
        $this->assertSame('360.00', $dashboard['demand']['provisional_totals_by_currency'][0]['amount']);
    }
}
