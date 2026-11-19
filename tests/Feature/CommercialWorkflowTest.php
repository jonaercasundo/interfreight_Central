<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Models\User;
use App\Services\CommercialCalculator;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function commercialContext(User $sales): array
    {
        $this->actingAs($sales);
        $client = $this->postJson('/api/sales/clients', ['company_name' => 'Harbor Imports', 'contact_name' => 'Taylor Ng', 'email' => 'taylor@example.test', 'client_type' => 'FCL'])->assertCreated()->json('data');
        $inquiry = $this->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'], 'service_type' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Singapore', 'destination' => 'Los Angeles',
            'cargo_description' => 'Consumer goods', 'estimated_container_count' => 1, 'container_type' => '40HC', 'commodity_category' => 'General cargo',
            'requested_ship_date' => now()->addWeeks(3)->toDateString(),
        ])->assertCreated()->json('data');
        $this->postJson("/api/sales/inquiries/{$inquiry['id']}/submit")->assertOk();
        $forecast = $this->postJson('/api/sales/forecasts', [
            'inquiry_id' => $inquiry['id'], 'forecast_month' => now()->addMonth()->startOfMonth()->toDateString(), 'expected_shipment_count' => 1, 'confidence' => 'probable',
        ])->assertCreated()->json('data');

        return [$client, $inquiry, $forecast];
    }

    private function costLine(string $value = '100.00'): array
    {
        return ['category' => 'shipping_line', 'description' => 'Ocean freight', 'supplier' => 'Carrier', 'quantity' => '1', 'unit' => 'shipment', 'unit_cost' => $value, 'currency' => 'PHP', 'tax_treatment' => 'none', 'tax_rate' => '0', 'cost_type' => 'direct', 'cost_payer' => 'company', 'recoverability' => 'recoverable', 'funding_required' => true];
    }

    private function quoteLine(string $value = '150.00'): array
    {
        return ['description' => 'Freight service', 'quantity' => '1', 'unit' => 'shipment', 'unit_price' => $value, 'discount_type' => 'none', 'discount_value' => '0', 'tax_treatment' => 'none', 'tax_rate' => '0'];
    }

    public function test_decimal_calculator_uses_expected_rounding_and_margin_formulas(): void
    {
        $calculator = app(CommercialCalculator::class);
        $line = $calculator->costLine($this->costLine('100.125'), 'PHP');
        $this->assertSame('100.13', $line['estimate_currency_total']);
        $totals = $calculator->quotationTotals([$this->quoteLine('150.00')]);
        $this->assertSame('150.00', $totals['total']);
        $this->assertSame(['selling_price' => '150.00', 'total_estimated_cost' => '100.13', 'gross_profit' => '49.87', 'gross_margin_percent' => '33.2467', 'markup_percent' => '49.8053', 'required_selling_price_at_target_margin' => null, 'warning' => null], $calculator->margin('100.13', '150.00', null));
        $this->assertSame('133.51', $calculator->margin('100.13', '150.00', '25')['required_selling_price_at_target_margin']);
        $this->assertSame('loss', $calculator->margin('100.00', '90.00')['warning']);
        $this->expectException(ValidationException::class);
        $calculator->costLine([...$this->costLine(), 'exchange_rate_to_estimate' => '0'], 'USD');
    }

    public function test_sales_estimate_review_quote_approval_issue_acceptance_and_baseline_work_end_to_end(): void
    {
        $sales = $this->userWithRole('Sales');
        [$client, $inquiry, $forecast] = $this->commercialContext($sales);
        $estimate = $this->postJson('/api/commercial/cost-estimates', [
            'inquiry_id' => $inquiry['id'], 'forecast_id' => $forecast['id'], 'currency' => 'PHP', 'assumptions' => 'Rates checked', 'lines' => [$this->costLine()],
        ])->assertCreated()->assertJsonPath('data.estimated_cost_total', '100.00')->json('data');
        $this->postJson("/api/commercial/cost-estimates/{$estimate['id']}/submit")->assertOk();
        $accounting = $this->userWithRole('Accounting');
        $this->actingAs($accounting)->postJson("/api/commercial/cost-estimates/{$estimate['id']}/review", ['decision' => 'approved', 'remarks' => 'Costs and funding checked'])->assertOk();

        $this->actingAs($sales)->postJson('/api/commercial/quotations', [
            'cost_estimate_id' => $estimate['id'], 'service_scope' => 'FCL sea freight', 'origin' => 'Singapore', 'destination' => 'Los Angeles',
            'cargo_description' => 'Consumer goods', 'valid_until' => now()->addDays(10)->toDateString(), 'payment_terms' => 'Net 15', 'internal_notes' => 'Internal only', 'lines' => [$this->quoteLine()],
        ])->assertCreated()->assertJsonPath('data.total', '150.00')->assertJsonMissingPath('data.cost_snapshot');
        $quote = Quotation::firstOrFail();
        $this->postJson("/api/commercial/quotations/{$quote->id}/submit")->assertOk();
        $this->actingAs($accounting)->postJson("/api/commercial/quotations/{$quote->id}/review", ['stage' => 'accounting', 'decision' => 'approved', 'remarks' => 'Cost basis verified'])->assertOk();
        $this->postJson("/api/commercial/quotations/{$quote->id}/review", ['stage' => 'accounting', 'decision' => 'approved', 'remarks' => 'Duplicate approval attempt'])->assertStatus(409);
        $finance = $this->userWithRole('Finance');
        $this->actingAs($finance)->postJson("/api/commercial/quotations/{$quote->id}/review", ['stage' => 'finance', 'decision' => 'approved', 'remarks' => 'Margin exposure reviewed'])->assertOk();
        $this->postJson("/api/commercial/quotations/{$quote->id}/approve", ['remarks' => 'Reviewer must not self-approve'])->assertForbidden();
        $executive = $this->userWithRole('Executive');
        $this->actingAs($executive)->postJson("/api/commercial/quotations/{$quote->id}/approve", ['remarks' => 'Approved'])->assertOk();
        $this->actingAs($sales)->postJson("/api/commercial/quotations/{$quote->id}/issue")->assertOk();
        $this->postJson("/api/commercial/quotations/{$quote->id}/client-response", [
            'response' => 'accepted', 'client_representative' => 'Taylor Ng', 'quotation_version' => 1, 'responded_at' => now()->toDateTimeString(), 'evidence_reference' => 'secure://acceptance/001', 'remarks' => 'Accepted by email',
        ])->assertOk()->assertJsonPath('data.status', 'client_accepted');
        $this->assertDatabaseHas('commercial_baselines', ['quotation_id' => $quote->id, 'quotation_version' => 1, 'is_active' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'commercial_baselines.created', 'actor_id' => $sales->id]);
        $this->actingAs($sales)->get("/sales/quotations/{$quote->id}/print")->assertOk()->assertSee('Harbor Imports')->assertDontSee('Carrier')->assertDontSee('100.00');
        $revision = $this->postJson("/api/commercial/quotations/{$quote->id}/revise", ['cost_estimate_id' => $estimate['id'], 'reason' => 'Client requested a scope update'])
            ->assertCreated()->assertJsonMissingPath('data.cost_snapshot')->json('data');
        $this->assertSame(2, $revision['version']);
        $this->assertDatabaseHas('quotations', ['id' => $revision['id'], 'parent_quotation_id' => $quote->id, 'revision_reason' => 'Client requested a scope update']);
        $this->putJson("/api/commercial/quotations/{$revision['id']}", [
            'cost_estimate_id' => $estimate['id'], 'service_scope' => 'FCL sea freight plus handling', 'origin' => 'Singapore', 'destination' => 'Los Angeles',
            'cargo_description' => 'Consumer goods', 'valid_until' => now()->addDays(10)->toDateString(), 'terms' => 'Updated service scope', 'lines' => [$this->quoteLine('160.00')],
        ])->assertOk();
        $comparison = $this->getJson("/api/commercial/quotations/{$revision['id']}/compare")->assertOk()->json('data');
        $this->assertSame('10.00', $comparison['difference']);
        $this->assertContains('terms', $comparison['changed_terms']);
        $this->assertDatabaseHas('commercial_baselines', ['quotation_id' => $quote->id, 'is_active' => 1]);
        $this->actingAs($sales)->putJson("/api/commercial/quotations/{$quote->id}", [
            'service_scope' => 'FCL sea freight', 'origin' => 'Singapore', 'destination' => 'Los Angeles', 'cargo_description' => 'Consumer goods',
            'valid_until' => now()->addDays(10)->toDateString(), 'lines' => [$this->quoteLine()],
        ])->assertStatus(409);
    }

    public function test_commercial_endpoints_deny_anonymous_and_unauthorized_users_and_validate_payloads(): void
    {
        $this->getJson('/api/commercial/cost-estimates')->assertUnauthorized();
        $sales = $this->userWithRole('Sales');
        $this->actingAs($sales)->postJson('/api/commercial/cost-estimates', [])->assertUnprocessable();
        $operations = $this->userWithRole('Operations');
        $this->actingAs($operations)->getJson('/api/commercial/cost-estimates')->assertForbidden();
        $this->actingAs($operations)->get('/sales/cost-estimates')->assertForbidden();
    }
}
