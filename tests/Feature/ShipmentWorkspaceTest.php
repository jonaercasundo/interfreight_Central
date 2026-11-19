<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShipmentWorkspaceTest extends TestCase
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

    private function client(User $salesOwner): int
    {
        return DB::table('clients')->insertGetId(['company_name' => 'IFC Phase Five Client', 'client_type' => 'FCL', 'sales_owner_id' => $salesOwner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function directPayload(int $clientId, string $department = 'Operations'): array
    {
        return [
            'source_type' => 'direct', 'client_id' => $clientId, 'originating_department' => $department,
            'shipment_type' => 'Import', 'service_type' => $department, 'mode' => 'Sea', 'lcl_fcl' => 'FCL',
            'origin' => 'Manila', 'destination' => 'Singapore', 'cargo' => 'Palletized components', 'weight_kg' => '1800.00',
            'quantity' => '18.000', 'volume_cbm' => '12.500', 'planned_departure' => now()->addDays(4)->toDateString(),
        ];
    }

    public function test_direct_job_registration_is_authorized_idempotent_and_assigns_a_unique_job_number(): void
    {
        $sales = $this->user('Sales');
        $operations = $this->user('Operations');
        $client = $this->client($sales);
        $key = 'phase-five-create-'.fake()->uuid();

        $payload = [...$this->directPayload($client), 'idempotency_key' => $key];
        $first = $this->actingAs($operations)->postJson('/api/shipments-workspace', $payload)->assertCreated();
        $id = $first->json('data.id');
        $jobNumber = $first->json('data.job_number');
        $this->assertMatchesRegularExpression('/^IFC-\d{4}-\d{6}$/', $jobNumber);
        $this->assertStringContainsString('Direct-origin jobs require commercial and funding review', $first->json('financial_readiness.note'));
        $this->assertDatabaseHas('shipment_department_assignments', ['shipment_id' => $id, 'department' => 'Operations']);
        $this->assertDatabaseHas('shipment_timeline_events', ['shipment_id' => $id, 'event_type' => 'job_created']);
        $this->assertDatabaseHas('shipments', ['id' => $id, 'creation_key' => $key]);

        $replay = $this->actingAs($operations)->postJson('/api/shipments-workspace', $payload)->assertOk();
        $this->assertTrue($replay->json('idempotent_replay'));
        $this->assertSame($id, $replay->json('data.id'));
        $this->assertSame(1, DB::table('shipments')->where('creation_key', $key)->count());

        $salesPayload = $this->directPayload($client);
        $salesPayload['originating_department'] = 'Operations';
        $this->actingAs($this->user('Sales'))->withHeader('Idempotency-Key', 'sales-invalid-'.fake()->uuid())->postJson('/api/shipments-workspace', $salesPayload)->assertForbidden();
    }

    public function test_department_assignment_scopes_the_register_and_master_status_stays_independent_of_trip_delivery(): void
    {
        $operations = $this->user('Operations');
        $transport = $this->user('Transport');
        $client = $this->client($this->user('Sales'));
        $shipment = $this->actingAs($operations)->postJson('/api/shipments-workspace', $this->directPayload($client))->assertCreated()->json('data');
        $id = $shipment['id'];

        $this->actingAs($operations)->postJson("/api/shipments-workspace/{$id}/assignments", ['department' => 'Transport', 'assigned_user_id' => $transport->id, 'status' => 'assigned', 'remarks' => 'Road delivery leg'])->assertOk();
        $this->actingAs($transport)->getJson('/api/shipments-workspace')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->actingAs($transport)->postJson("/api/shipments-workspace/{$id}/trips", ['origin' => 'Manila terminal', 'destination' => 'Client warehouse', 'plate_number' => 'ABC1234', 'driver_name' => 'Assigned Driver', 'scheduled_pickup' => now()->addDays(2)->toDateTimeString(), 'scheduled_delivery' => now()->addDays(3)->toDateTimeString()])->assertCreated();
        $this->actingAs($transport)->postJson("/api/shipments-workspace/{$id}/trips", ['origin' => 'Port', 'destination' => 'Warehouse', 'plate_number' => 'ABC1234', 'scheduled_pickup' => now()->addDays(2)->toDateTimeString(), 'scheduled_delivery' => now()->addDays(3)->toDateTimeString()])->assertConflict();

        $this->actingAs($operations)->postJson("/api/shipments-workspace/{$id}/status", ['status' => 'in_progress', 'reason' => 'Departmental execution started', 'expected_version' => 1])->assertOk();
        $milestoneKey = fake()->uuid();
        $milestonePayload = ['department' => 'Operations', 'code' => 'DOCS_READY', 'label' => 'Documents ready', 'status' => 'completed', 'idempotency_key' => $milestoneKey];
        $milestone = $this->actingAs($operations)->postJson("/api/shipments-workspace/{$id}/milestones", $milestonePayload)->assertCreated()->json('data');
        $replayedMilestone = $this->actingAs($operations)->postJson("/api/shipments-workspace/{$id}/milestones", $milestonePayload)->assertOk();
        $this->assertSame($milestone['id'], $replayedMilestone->json('data.id'));
        $trip = DB::table('shipment_transport_trips')->where('shipment_id', $id)->first();
        foreach (['scheduled', 'dispatched', 'picked_up', 'in_transit', 'delivered'] as $status) {
            $response = $this->actingAs($transport)->putJson("/api/shipments-workspace/trips/{$trip->id}/status", ['status' => $status, 'expected_version' => $trip->lock_version, 'remarks' => 'Status recorded']);
            $response->assertOk();
            $trip = DB::table('shipment_transport_trips')->where('id', $trip->id)->first();
        }
        $this->assertSame('delivered', $trip->status);
        $this->assertSame('in_progress', DB::table('shipments')->where('id', $id)->value('master_status'));
    }

    public function test_private_documents_require_department_scope_and_append_version_history(): void
    {
        Storage::fake('local');
        $operations = $this->user('Operations');
        $client = $this->client($this->user('Sales'));
        $shipment = $this->actingAs($operations)->postJson('/api/shipments-workspace', $this->directPayload($client))->assertCreated()->json('data');
        $id = $shipment['id'];
        $uploadKey = fake()->uuid();
        $one = $this->actingAs($operations)->post("/api/shipments-workspace/{$id}/documents", ['category' => 'packing_list', 'upload_key' => $uploadKey, 'file' => UploadedFile::fake()->create('packing.pdf', 100, 'application/pdf')])->assertCreated()->json('data');
        $retry = $this->post("/api/shipments-workspace/{$id}/documents", ['category' => 'packing_list', 'upload_key' => $uploadKey, 'file' => UploadedFile::fake()->create('packing-retry.pdf', 100, 'application/pdf')])->assertOk();
        $this->assertSame($one['id'], $retry->json('data.id'));
        $two = $this->post("/api/shipments-workspace/{$id}/documents", ['category' => 'packing_list', 'file' => UploadedFile::fake()->create('packing-revised.pdf', 120, 'application/pdf')])->assertCreated()->json('data');
        $this->assertSame(1, $one['version']);
        $this->assertSame(2, $two['version']);
        $this->assertSame($one['id'], $two['replaces_document_id']);
        $this->get('/api/shipments-workspace/documents/'.$two['id'].'/download')->assertOk();
        $this->actingAs($this->user('Sales'))->get('/api/shipments-workspace/documents/'.$two['id'].'/download')->assertForbidden();
        $this->assertDatabaseHas('shipment_timeline_events', ['shipment_id' => $id, 'event_type' => 'document_uploaded']);
    }

    public function test_workspace_routes_keep_anonymous_and_unauthorized_department_users_out(): void
    {
        $this->getJson('/api/shipments-workspace')->assertUnauthorized();
        $this->actingAs($this->user('Finance'))->get('/operations/customs')->assertForbidden();
        $this->actingAs($this->user('Sales'))->getJson('/api/shipments-workspace/reconciliation')->assertForbidden();
    }
}
