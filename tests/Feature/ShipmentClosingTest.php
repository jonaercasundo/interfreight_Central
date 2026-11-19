<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShipmentClosingTest extends TestCase
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

    private function shipment(): int
    {
        return DB::table('shipments')->insertGetId([
            'reference' => 'CLOSE-'.fake()->unique()->numerify('########'), 'job_number' => 'IFC-CLOSE-'.fake()->unique()->numerify('####'),
            'customer_name' => 'Closing Test Client', 'origin' => 'Manila', 'destination' => 'Cebu', 'mode' => 'Sea',
            'status' => 'Booked', 'master_status' => 'open', 'cargo' => 'Test freight', 'weight_kg' => '10.00',
            'currency' => 'PHP', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_closing_register_is_scoped_and_administrator_does_not_get_business_close_authority(): void
    {
        $shipment = $this->shipment();
        $this->getJson('/api/financial-closing/shipments')->assertUnauthorized();
        $this->actingAs($this->user('Sales'))->getJson('/api/financial-closing/shipments')->assertForbidden();
        $administrator = $this->user('Administrator');
        $this->actingAs($administrator)->get('/finance/shipment-closing')->assertForbidden();
        $this->actingAs($administrator)->postJson("/api/financial-closing/shipments/{$shipment}/prepare", ['reason' => 'Unauthorized close request'])->assertForbidden();
        $finance = $this->user('Finance');
        $response = $this->actingAs($finance)->getJson('/api/financial-closing/shipments')->assertOk();
        $this->assertSame($shipment, $response->json('data.data.0.shipment.id'));
    }

    public function test_completion_dimensions_are_independent_and_unknown_recognized_revenue_blocks_closing(): void
    {
        $shipment = $this->shipment();
        $operations = $this->user('Operations');
        $this->actingAs($operations)->get('/finance/shipment-closing')->assertOk();
        DB::table('shipment_department_assignments')->insert(['shipment_id' => $shipment, 'department' => 'Operations', 'assigned_user_id' => $operations->id, 'status' => 'assigned', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($operations)->postJson("/api/financial-closing/shipments/{$shipment}/milestones/operations/verify", ['lock_version' => 1, 'notes' => 'Source records reviewed'])->assertOk()->assertJsonPath('data.milestones.operations.status', 'completed');

        $detail = $this->actingAs($this->user('Finance'))->getJson("/api/financial-closing/shipments/{$shipment}")->assertOk();
        $this->assertSame('blocked', $detail->json('data.milestones.billing.status'));
        $this->assertNull($detail->json('data.financial.recognized_revenue'));
        $this->assertNull($detail->json('data.financial.gross_profit'));
        $this->assertSame('not_yet_determinable', $detail->json('data.financial.status'));
        $this->postJson("/api/financial-closing/shipments/{$shipment}/prepare", ['reason' => 'Submit after review of financial evidence'])->assertConflict();
        $this->assertDatabaseCount('financial_closing_requests', 0);
        $this->assertDatabaseCount('financial_closing_snapshots', 0);
    }
}
