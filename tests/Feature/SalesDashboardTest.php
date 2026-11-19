<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ShipmentForecast;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_sales_lands_on_dedicated_dashboard_after_login_and_dashboard_resolves_by_role(): void
    {
        $sales = User::factory()->create(['password' => 'a-strong-test-password']);
        $sales->assignRole('Sales');

        $this->post('/login', ['email' => $sales->email, 'password' => 'a-strong-test-password'])->assertRedirect('/sales/dashboard');
        $this->get('/sales/dashboard')->assertOk()->assertSee('data-page="sales-dashboard"', false);
        $this->get('/dashboard')->assertRedirect('/sales/dashboard');

        $executive = User::factory()->create();
        $executive->assignRole('Executive');
        $this->actingAs($executive)->get('/dashboard')->assertRedirect('/executive');

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('data-page="dashboard"', false);

        $mixed = User::factory()->create();
        $mixed->assignRole(['Sales', 'Executive']);
        $this->actingAs($mixed)->get('/dashboard')->assertRedirect('/executive');
        $mixed->assignRole('Administrator');
        $this->get('/dashboard')->assertOk()->assertSee('data-page="dashboard"', false);
    }

    public function test_sales_dashboard_permissions_are_enforced_without_executive_privileges(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->assertFalse($sales->can('executive.overview.view'));
        $this->actingAs($sales)->get('/sales/dashboard')->assertOk();

        Role::findByName('Sales')->revokePermissionTo('sales.dashboard.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($sales)->get('/sales/dashboard')->assertForbidden();

        Role::findByName('Sales')->revokePermissionTo('dashboard.view');
        $this->get('/dashboard')->assertRedirect('/sales');
        $this->get('/sales/dashboard')->assertForbidden();
    }

    public function test_authenticated_users_land_on_an_authorized_page_instead_of_an_unauthorized_intended_url(): void
    {
        $sales = User::factory()->create(['password' => 'a-strong-test-password']);
        $sales->assignRole('Sales');

        // Mimic a bookmarked restricted route being stored as the intended URL.
        $this->get('/admin/users')->assertRedirect('/login');
        $this->post('/login', ['email' => $sales->email, 'password' => 'a-strong-test-password'])
            ->assertRedirect('/sales/dashboard');

        $role = Role::findByName('Sales');
        $role->syncPermissions(['profile.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($sales)->get('/dashboard')->assertRedirect('/profile');
    }

    public function test_dashboard_returns_403_only_when_the_account_has_no_authorized_page(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        Role::findByName('Sales')->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($sales)->get('/dashboard')->assertForbidden();
    }

    public function test_dashboard_summary_and_calendar_are_owner_scoped_and_forecasts_are_not_shipments(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $other = User::factory()->create();
        $client = Client::create(['company_name' => 'Owned Client', 'client_type' => 'FCL', 'sales_owner_id' => $sales->id]);
        $otherClient = Client::create(['company_name' => 'Other Client', 'client_type' => 'FCL', 'sales_owner_id' => $other->id]);
        $requestedDate = today()->addDays(10);
        $month = $requestedDate->copy()->startOfMonth();
        $ownedInquiry = $this->inquiry($sales, $client, $requestedDate->toDateString());
        $this->inquiry($sales, $client, $requestedDate->toDateString(), 'draft');
        $this->inquiry($sales, $client, $requestedDate->toDateString(), 'forecasted');
        $otherInquiry = $this->inquiry($other, $otherClient, $requestedDate->toDateString());
        ShipmentForecast::create(['inquiry_id' => $ownedInquiry->id, 'sales_owner_id' => $sales->id, 'forecast_month' => $month->toDateString(), 'expected_shipment_count' => 3, 'confidence' => 'probable', 'status' => 'active']);
        ShipmentForecast::create(['inquiry_id' => $otherInquiry->id, 'sales_owner_id' => $other->id, 'forecast_month' => $month->toDateString(), 'expected_shipment_count' => 7, 'confidence' => 'probable', 'status' => 'active']);

        $this->actingAs($sales)->getJson('/api/sales/dashboard/summary')->assertOk()
            ->assertJsonPath('data.total_inquiries', 3)
            ->assertJsonPath('data.draft_inquiries', 1)
            ->assertJsonPath('data.submitted_inquiries', 1)
            ->assertJsonPath('data.under_review_inquiries', 0)
            ->assertJsonPath('data.forecasted_inquiries', 1)
            ->assertJsonPath('data.forecast_shipments', 3)->assertJsonPath('data.upcoming_shipments', 0);
        $response = $this->getJson('/api/sales/calendar?from='.$requestedDate->toDateString().'&to='.$requestedDate->copy()->addDays(1)->toDateString())->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame('forecast', $response->json('data.data.0.kind'));
        $this->assertSame('Owned Client', $response->json('data.data.0.client'));
        $this->assertSame([$client->id], collect($response->json('clients'))->pluck('id')->all());
        $this->getJson('/api/sales/calendar/inquiry/'.$otherInquiry->id)->assertNotFound();
    }

    public function test_sales_manager_can_manage_capacity_but_cannot_over_reserve_or_overwrite_stale_changes(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Sales Manager');
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $payload = [
            'origin' => 'Manila', 'destination' => 'Singapore', 'transport_mode' => 'Sea', 'service_type' => 'FCL',
            'departure_date' => today()->addDays(12)->toDateString(), 'confirmed_capacity' => 20,
            'capacity_unit' => 'TEU', 'source_type' => 'carrier_verified', 'source_reference' => 'SYNTHETIC-CARRIER-CONFIRMATION',
            'valid_from' => today()->toDateString(),
        ];
        $this->actingAs($sales)->postJson('/api/sales/availability', $payload)->assertForbidden();
        $created = $this->actingAs($manager)->postJson('/api/sales/availability', $payload)->assertCreated()->assertJsonPath('data.available_capacity', '20');
        $id = $created->json('data.id');
        $version = $created->json('data.lock_version');
        $this->assertDatabaseHas('sales_capacity_events', ['availability_id' => $id, 'event_type' => 'created', 'actor_id' => $manager->id]);

        $this->putJson('/api/sales/availability/'.$id, ['lock_version' => $version, 'confirmed_capacity' => 19, 'reserved_capacity' => 20])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed_capacity');
        $updated = $this->putJson('/api/sales/availability/'.$id, ['lock_version' => $version, 'confirmed_capacity' => 25, 'reserved_capacity' => 5])->assertOk();
        $this->assertSame('20', $updated->json('data.available_capacity'));
        $this->putJson('/api/sales/availability/'.$id, ['lock_version' => $version, 'confirmed_capacity' => 30])->assertStatus(409);
        $this->assertDatabaseHas('sales_capacity_events', ['availability_id' => $id, 'event_type' => 'updated', 'actor_id' => $manager->id]);
    }

    private function inquiry(User $owner, Client $client, string $requestedDate, string $status = 'submitted'): ClientInquiry
    {
        return ClientInquiry::create([
            'client_id' => $client->id, 'sales_owner_id' => $owner->id, 'service_type' => 'FCL', 'transport_mode' => 'Sea',
            'origin' => 'Manila', 'destination' => 'Singapore', 'cargo_description' => 'Synthetic test cargo',
            'requested_ship_date' => $requestedDate, 'status' => $status,
        ]);
    }
}
