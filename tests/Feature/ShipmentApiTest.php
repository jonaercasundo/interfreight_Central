<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShipmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Http::fake([
            '*/health' => Http::response(['status' => 'ok']),
            '*/internal/diagnostic' => Http::response(['status' => 'authenticated']),
        ]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Administrator');
    }

    public function test_dashboard_returns_summary_metrics(): void
    {
        $this->actingAs($this->admin)->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['metrics' => ['users', 'active_users', 'inactive_users', 'roles'], 'integration', 'recent_events', 'recent_users', 'system_health' => ['application', 'database', 'service', 'authentication', 'checked_at']]);
    }

    public function test_administrator_dashboard_metrics_users_and_recent_events_use_database_records(): void
    {
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => 'diagnostic-secret']);
        $this->admin->forceFill(['created_at' => now()->subDay()])->save();
        User::factory()->create(['name' => 'Inactive Account', 'is_active' => false, 'created_at' => now()->subDays(2)]);
        $recent = User::factory()->create(['name' => 'Recently Created', 'email' => 'recent@example.test']);
        $recent->assignRole('Sales');
        AuditLog::create(['actor_id' => $recent->id, 'action' => 'auth.login_succeeded', 'created_at' => now()->subHour()]);
        AuditLog::create(['actor_id' => $this->admin->id, 'action' => 'users.created', 'target_type' => User::class, 'target_id' => $recent->id, 'metadata' => ['password' => 'never-expose-this'], 'created_at' => now()]);

        $payload = $this->actingAs($this->admin)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('metrics.users', 3)
            ->assertJsonPath('metrics.active_users', 2)
            ->assertJsonPath('metrics.inactive_users', 1)
            ->assertJsonPath('metrics.roles', 10)
            ->assertJsonPath('metrics.audit_events_today', 2)
            ->assertJsonPath('recent_users.0.name', 'Recently Created')
            ->assertJsonPath('recent_users.0.roles.0', 'Sales')
            ->assertJsonPath('recent_events.0.event', 'User Account Created')
            ->assertJsonMissing(['never-expose-this']);

        $this->assertNotNull($payload->json('system_health.checked_at'));
        $this->assertNotEmpty($payload->json('recent_users.0.last_login_at'));
        $this->assertSame('connected', $payload->json('system_health.database.status'));
        $this->assertSame('authenticated', $payload->json('system_health.authentication.status'));
    }

    public function test_fastapi_failure_does_not_break_administrator_dashboard_data(): void
    {
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => 'diagnostic-secret']);
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->actingAs($this->admin)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('system_health.application.status', 'connected')
            ->assertJsonPath('system_health.database.status', 'connected')
            ->assertJsonPath('system_health.service.status', 'unavailable')
            ->assertJsonPath('system_health.authentication.status', 'unavailable');
    }

    public function test_department_dashboard_does_not_receive_administrator_data(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');

        $this->actingAs($sales)->getJson('/api/dashboard')->assertOk()
            ->assertJsonMissingPath('recent_users')
            ->assertJsonMissingPath('system_health')
            ->assertJsonPath('metrics.users', null)
            ->assertJsonPath('recent_events', []);
    }

    public function test_administrator_takes_dashboard_priority_for_multiple_roles(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['Sales', 'Administrator']);

        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()
            ->assertJsonStructure(['recent_users', 'system_health', 'metrics' => ['users', 'roles']]);
    }

    public function test_shipment_can_be_created_and_listed(): void
    {
        $payload = ['customer_name' => 'Northstar Retail', 'origin' => 'Singapore, SG', 'destination' => 'Los Angeles, US', 'mode' => 'Ocean', 'cargo' => 'Electronics', 'weight_kg' => 840, 'freight_cost' => 2450, 'estimated_delivery' => '2026-11-20'];
        $created = $this->actingAs($this->admin)->postJson('/api/shipments', $payload)->assertCreated()->assertJsonPath('customer_name', 'Northstar Retail');
        $this->assertMatchesRegularExpression('/^IFC-\d{4}-\d{6}$/', $created->json('job_number'));
        $this->actingAs($this->admin)->getJson('/api/shipments')->assertOk()->assertJsonPath('data.0.origin', 'Singapore, SG');
    }

    public function test_compatibility_api_obeys_sales_scope_and_archives_instead_of_deleting(): void
    {
        $payload = ['customer_name' => 'Private Customer', 'origin' => 'Manila', 'destination' => 'Singapore', 'mode' => 'Ocean', 'cargo' => 'Palletized goods', 'weight_kg' => 100, 'freight_cost' => 0];
        $shipment = $this->actingAs($this->admin)->postJson('/api/shipments', $payload)->assertCreated()->json();

        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales)->getJson('/api/shipments')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($sales)->getJson('/api/shipments/'.$shipment['id'])->assertForbidden();
        $this->actingAs($this->admin)->deleteJson('/api/shipments/'.$shipment['id'])->assertNoContent();
        $this->assertDatabaseHas('shipments', ['id' => $shipment['id']]);
        $this->assertNotNull(DB::table('shipments')->where('id', $shipment['id'])->value('archived_at'));
    }

    public function test_shipment_validation_rejects_missing_route(): void
    {
        $this->actingAs($this->admin)->postJson('/api/shipments', ['customer_name' => 'Example'])->assertUnprocessable()->assertJsonValidationErrors(['origin', 'destination', 'mode', 'cargo', 'weight_kg']);
    }
}
