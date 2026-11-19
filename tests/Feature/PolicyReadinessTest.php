<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinancialPolicyEngine;
use App\Services\FinancialPolicyRegistry;
use App\Services\StatutoryBillingRegistry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyReadinessTest extends TestCase
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

    private function policyPayload(): array
    {
        return [
            'revenue_recognition' => ['source' => 'Finance policy source reference', 'rule' => 'Finance-approved recognition rule text supplied by policy owner.'],
            'final_cost_accrual' => ['source' => 'Finance cost source reference', 'method' => 'Finance-approved accrual method supplied by policy owner.'],
            'reporting_currency' => 'PHP',
            'fx' => ['source' => 'Approved source reference', 'pairs' => ['USD/PHP']],
            'effective_from' => now()->subMinute()->toDateTimeString(),
        ];
    }

    public function test_financial_policy_is_durable_audited_maker_checker_and_only_active_after_approval(): void
    {
        $proposer = $this->user('Finance');
        $approver = $this->user('Finance');
        $proposal = $this->actingAs($proposer)->postJson('/api/finance/policies', $this->policyPayload())
            ->assertCreated()->assertJsonPath('data.status', 'pending_approval')->json('data');

        $this->assertNull(app(FinancialPolicyRegistry::class)->active());
        $this->actingAs($proposer)->postJson('/api/finance/policies/'.$proposal['version'].'/approve', [
            'approval_reference' => 'FIN-APPROVAL-16-001', 'confirmation' => 'finance_approved',
        ])->assertForbidden();
        $this->actingAs($approver)->postJson('/api/finance/policies/'.$proposal['version'].'/approve', [
            'approval_reference' => 'FIN-APPROVAL-16-001', 'confirmation' => 'finance_approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $active = app(FinancialPolicyRegistry::class)->active();
        $this->assertSame($proposal['id'], $active->id);
        $this->assertSame('USD/PHP', app(FinancialPolicyRegistry::class)->enginePolicy()['fx_pairs'][0]);
        $this->assertDatabaseHas('financial_policy_events', ['policy_version_id' => $active->id, 'event' => 'proposed']);
        $this->assertDatabaseHas('financial_policy_events', ['policy_version_id' => $active->id, 'event' => 'approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'financial_policy.approved', 'actor_id' => $approver->id]);
    }

    public function test_non_finance_cannot_manage_policy_and_incomplete_inputs_cannot_be_proposed(): void
    {
        $sales = $this->user('Sales');
        $this->actingAs($sales)->postJson('/api/finance/policies', $this->policyPayload())->assertForbidden();
        $finance = $this->user('Finance');
        $payload = $this->policyPayload();
        unset($payload['fx']['source']);
        $this->actingAs($finance)->postJson('/api/finance/policies', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('financial_policy_versions', 0);
    }

    public function test_invoice_policy_profile_requires_independent_finance_and_legal_references_and_does_not_issue(): void
    {
        $maker = $this->user('Finance');
        $approver = $this->user('Finance');
        $profile = [
            'legal_entity' => ['legal_name' => 'Synthetic IFC Entity', 'country_code' => 'PH', 'tax_registration_id' => 'TEST-REG-001', 'registered_address' => 'Synthetic test address only'],
            'tax_rules' => [['code' => 'SYNTHETIC', 'jurisdiction' => 'Test only', 'treatment' => 'unconfigured', 'rate' => null, 'effective_from' => now()->toDateString()]],
            'numbering_rules' => ['prefix' => 'TEST', 'pattern' => 'TEST-{YEAR}-{SEQUENCE}', 'reset_rule' => 'test-only'],
            'document_rules' => ['template_identifier' => 'synthetic-v1', 'required_fields' => ['legal_entity', 'invoice_lines']],
            'effective_from' => now()->subMinute()->toDateTimeString(),
        ];
        $row = $this->actingAs($maker)->postJson('/api/billing/statutory-profile', $profile)->assertCreated()->json('data');
        $this->actingAs($maker)->postJson('/api/billing/statutory-profile/'.$row['version'].'/approve', [
            'finance_approval_reference' => 'FIN-TEST-001', 'legal_approval_reference' => 'LEGAL-TEST-001', 'confirmation' => 'finance_and_legal_approved',
        ])->assertForbidden();
        $this->actingAs($approver)->postJson('/api/billing/statutory-profile/'.$row['version'].'/approve', [
            'finance_approval_reference' => 'FIN-TEST-001', 'legal_approval_reference' => 'LEGAL-TEST-001', 'confirmation' => 'finance_and_legal_approved',
        ])->assertOk();

        $this->assertSame((int) $row['id'], (int) app(StatutoryBillingRegistry::class)->active()->id);
        config(['ifc_billing.legal_issuance_verified' => true, 'ifc_billing.issuance_provider' => null]);
        $this->actingAs($this->user('Billing'))->postJson('/api/billing/invoices/999/issue')
            ->assertStatus(409)
            ->assertJsonPath('message', 'A configured statutory issuance provider is required before official invoice issuance can be enabled.');
        $this->assertDatabaseHas('billing_issuance_profiles', ['id' => $row['id'], 'status' => 'approved']);
    }

    public function test_financial_engine_still_keeps_profitability_unknown_without_authoritative_values(): void
    {
        $policy = app(FinancialPolicyRegistry::class)->enginePolicy();
        $result = app(FinancialPolicyEngine::class)->reconcile(null, null, null, null, 'PHP', $policy);
        $this->assertSame('not_determinable', $result['status']);
        $this->assertNull($result['gross_profit']);
        $this->assertNull($result['gross_margin_percent']);
    }
}
