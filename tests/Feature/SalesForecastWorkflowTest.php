<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesForecastWorkflowTest extends TestCase
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

    public function test_sales_user_can_capture_client_inquiry_and_multi_month_forecasts_with_audit_history(): void
    {
        $sales = $this->userWithRole('Sales');
        $this->actingAs($sales);

        $client = $this->postJson('/api/sales/clients', [
            'company_name' => 'Northstar Imports', 'contact_name' => 'A. Lee', 'email' => 'ops@northstar.test', 'client_type' => 'FCL',
        ])->assertCreated()->assertJsonPath('data.company_name', 'Northstar Imports')->json('data');
        $this->assertStringStartsWith('CLI-', $client['reference']);

        $inquiry = $this->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'], 'service_type' => 'FCL', 'transport_mode' => 'Sea',
            'origin' => 'Singapore', 'destination' => 'Los Angeles', 'cargo_description' => 'Consumer goods',
            'estimated_container_count' => 2, 'container_type' => '40HC', 'commodity_category' => 'General cargo',
            'requested_ship_date' => now()->addWeeks(3)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertStringStartsWith('INQ-', $inquiry['reference']);
        $this->putJson("/api/sales/inquiries/{$inquiry['id']}", ['cargo_description' => 'Revised consumer goods'])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.cargo_description', 'Revised consumer goods');

        $this->postJson("/api/sales/inquiries/{$inquiry['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.submitted_by', $sales->id);
        $this->assertNotNull(\App\Models\ClientInquiry::find($inquiry['id'])->submitted_at);
        $this->getJson('/api/sales/inquiry-approvals')->assertNotFound();
        $this->postJson("/api/sales/inquiry-approvals/{$inquiry['id']}/approve")->assertNotFound();
        $this->postJson("/api/sales/inquiries/{$inquiry['id']}/status", ['status' => 'under_review'])->assertUnprocessable();
        $november = now()->addMonth()->startOfMonth()->toDateString();
        $forecast = $this->postJson('/api/sales/forecasts', [
            'inquiry_id' => $inquiry['id'], 'forecast_month' => $november, 'expected_shipment_count' => 2, 'confidence' => 'probable',
        ])->assertCreated()->assertJsonPath('data.revision', 1)->json('data');
        $this->assertDatabaseHas('client_inquiries', ['id' => $inquiry['id'], 'status' => 'forecasted']);
        $this->postJson('/api/sales/forecasts', ['inquiry_id' => $inquiry['id'], 'forecast_month' => $november, 'expected_shipment_count' => 2, 'confidence' => 'probable'])->assertUnprocessable();
        $this->putJson("/api/sales/forecasts/{$forecast['id']}", ['expected_shipment_count' => 3, 'confidence' => 'confirmed'])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.expected_shipment_count', 3);
        $december = now()->addMonths(2)->startOfMonth()->toDateString();
        $this->postJson('/api/sales/forecasts', [
            'inquiry_id' => $inquiry['id'], 'forecast_month' => $december, 'expected_shipment_count' => 1, 'confidence' => 'tentative',
        ])->assertCreated();

        $this->getJson('/api/sales/forecasts')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/sales/forecasts/history')->assertOk()->assertJsonFragment(['revision' => 2, 'action' => 'updated']);
        $this->assertDatabaseHas('forecast_revisions', ['forecast_id' => $forecast['id'], 'revision' => 2, 'action' => 'updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'forecasts.created', 'actor_id' => $sales->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inquiries.submitted', 'actor_id' => $sales->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inquiries.status_changed', 'actor_id' => $sales->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'forecasts.updated', 'actor_id' => $sales->id]);
        $this->assertGreaterThanOrEqual(4, AuditLog::where('actor_id', $sales->id)->count());
    }

    public function test_sales_validation_rejects_invalid_service_mode_count_and_month(): void
    {
        $sales = $this->userWithRole('Sales');
        $this->actingAs($sales)->postJson('/api/sales/inquiries', [
            'client_id' => 12345, 'service_type' => 'Bulk', 'transport_mode' => 'Ocean', 'origin' => '',
            'destination' => 'Port', 'cargo_description' => 'Goods',
        ])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'service_type', 'transport_mode', 'origin']);
        $this->actingAs($sales)->postJson('/api/sales/forecasts', [
            'inquiry_id' => 123, 'forecast_month' => '2026-11-12', 'expected_shipment_count' => 0, 'confidence' => 'certain',
        ])->assertUnprocessable()->assertJsonValidationErrors(['inquiry_id', 'forecast_month', 'expected_shipment_count', 'confidence']);
    }

    public function test_client_routes_use_the_web_session_and_return_401_or_403_appropriately(): void
    {
        $this->getJson('/api/sales/clients')->assertUnauthorized();
        $this->getJson('/api/sales/summary')->assertUnauthorized();
        $this->getJson('/api/sales/inquiries')->assertUnauthorized();
        $this->getJson('/api/sales/forecasts')->assertUnauthorized();
        $operations = $this->userWithRole('Operations');
        $this->actingAs($operations)->getJson('/api/sales/clients')->assertForbidden();
        $sales = $this->userWithRole('Sales');
        $this->actingAs($sales)->get('/sales/clients')->assertOk()->assertSee('data-page="sales-clients"', false);
        $this->getJson('/api/sales/clients')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/sales/summary')->assertOk();
        $this->getJson('/api/sales/inquiries')->assertOk();
        $this->getJson('/api/sales/forecasts')->assertOk();

        foreach (['api.sales.clients.store', 'api.sales.summary', 'api.sales.inquiries.index', 'api.sales.forecasts.index'] as $routeName) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertContains('web', $route->gatherMiddleware(), "{$routeName} must use the web session and CSRF middleware.");
        }
    }

    public function test_sales_client_crud_detail_search_filters_pagination_and_owner_scope(): void
    {
        $sales = $this->userWithRole('Sales');
        $this->actingAs($sales);
        $first = null;
        for ($index = 1; $index <= 16; $index++) {
            $client = $this->postJson('/api/sales/clients', [
                'company_name' => sprintf('Client %02d', $index),
                'contact_name' => 'Contact '.$index,
                'email' => 'client'.$index.'@example.test',
                'client_type' => $index % 2 === 0 ? 'FCL' : 'LCL',
            ])->assertCreated()->assertJsonPath('data.sales_owner_id', $sales->id)->json('data');
            $first ??= $client;
        }
        $this->getJson('/api/sales/clients')->assertOk()->assertJsonPath('total', 16)->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2);
        $this->getJson('/api/sales/clients?search=Client%2001')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/sales/clients?client_type=FCL')->assertOk()->assertJsonPath('total', 8);
        $this->getJson('/api/sales/clients?page=2')->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(1, 'data');

        $this->getJson('/api/sales/clients/'.$first['id'])->assertOk()->assertJsonPath('data.company_name', 'Client 01');
        $this->putJson('/api/sales/clients/'.$first['id'], ['company_name' => 'Updated Client 01'])->assertOk()->assertJsonPath('data.company_name', 'Updated Client 01');
        $other = $this->userWithRole('Sales');
        $otherClient = $this->actingAs($other)->postJson('/api/sales/clients', ['company_name' => 'Private Client', 'client_type' => 'Mixed'])->assertCreated()->json('data');
        $this->actingAs($sales)->getJson('/api/sales/clients/'.$otherClient['id'])->assertNotFound();
        $this->putJson('/api/sales/clients/'.$otherClient['id'], ['company_name' => 'Attempted access'])->assertNotFound();
        $this->assertSame($other->id, $otherClient['sales_owner_id']);
    }

    public function test_sales_users_cannot_read_or_mutate_another_representatives_records(): void
    {
        $owner = $this->userWithRole('Sales');
        $other = $this->userWithRole('Sales');
        $this->actingAs($owner);
        $client = $this->postJson('/api/sales/clients', ['company_name' => 'Private Account', 'client_type' => 'LCL'])->assertCreated()->json('data');
        $inquiry = $this->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'], 'service_type' => 'LCL', 'transport_mode' => 'Air', 'origin' => 'SG', 'destination' => 'PH', 'cargo_description' => 'Samples',
        ])->assertCreated()->json('data');
        $this->actingAs($other)->getJson('/api/sales/inquiries')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/sales/inquiries/{$inquiry['id']}")->assertNotFound();
        $this->putJson("/api/sales/inquiries/{$inquiry['id']}", ['cargo_description' => 'Unauthorized change'])->assertNotFound();
    }

    public function test_accounting_can_read_probable_forecasts_but_cannot_mutate_sales_records(): void
    {
        $sales = $this->userWithRole('Sales');
        $this->actingAs($sales);
        $client = $this->postJson('/api/sales/clients', ['company_name' => 'Accounted Client', 'client_type' => 'FCL'])->assertCreated()->json('data');
        $inquiry = $this->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'], 'service_type' => 'FCL', 'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Tokyo', 'cargo_description' => 'Auto parts',
        ])->assertCreated()->json('data');
        $this->postJson("/api/sales/inquiries/{$inquiry['id']}/submit");
        $this->postJson('/api/sales/forecasts', ['inquiry_id' => $inquiry['id'], 'forecast_month' => now()->addMonth()->startOfMonth()->toDateString(), 'expected_shipment_count' => 4, 'confidence' => 'probable'])->assertCreated();

        $accounting = $this->userWithRole('Accounting');
        $this->actingAs($accounting)->getJson('/api/accounting/forecasts')->assertOk()
            ->assertJsonPath('data.0.expected_shipments', 4)->assertJsonPath('data.0.estimated_shipping_fee', null)
            ->assertJsonPath('data.0.cash_advance', null)->assertJsonPath('data.0.cost_estimate_status', 'not_estimated');
        $this->actingAs($accounting)->postJson('/api/sales/forecasts', [])->assertForbidden();
    }

    public function test_phase_two_pages_preserve_permission_based_access(): void
    {
        $unassigned = User::factory()->create();
        $this->actingAs($unassigned)->get('/sales/clients')->assertForbidden();
        $this->actingAs($unassigned)->get('/accounting/forecasts')->assertForbidden();
        $sales = $this->userWithRole('Sales');
        $this->actingAs($sales)->get('/sales/forecasts/calendar')->assertOk();
        $accounting = $this->userWithRole('Accounting');
        $this->actingAs($accounting)->get('/accounting/forecasts')->assertOk();
    }
}
