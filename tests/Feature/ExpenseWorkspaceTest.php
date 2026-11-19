<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseWorkspaceTest extends TestCase
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

    private function shipment(User $operations): int
    {
        $sales = $this->user('Sales');
        $clientId = DB::table('clients')->insertGetId(['company_name' => 'Phase Six Test Client', 'client_type' => 'FCL', 'sales_owner_id' => $sales->id, 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->actingAs($operations)->postJson('/api/shipments-workspace', [
            'source_type' => 'direct', 'client_id' => $clientId, 'originating_department' => 'Operations', 'shipment_type' => 'Import',
            'service_type' => 'Operations', 'mode' => 'Sea', 'lcl_fcl' => 'FCL', 'origin' => 'Manila', 'destination' => 'Singapore',
            'cargo' => 'Expense test cargo', 'weight_kg' => '100.00', 'quantity' => '1.000', 'planned_departure' => now()->addDays(3)->toDateString(),
        ])->assertCreated();

        return $response->json('data.id');
    }

    public function test_expense_api_requires_login_and_phase_six_permissions(): void
    {
        $this->getJson('/api/expense-workspace/expenses')->assertUnauthorized();
        $this->actingAs($this->user('Sales'))->postJson('/api/expense-workspace/expenses', [])->assertForbidden();
        $this->actingAs($this->user('Operations'))->getJson('/api/expense-workspace/options')->assertOk()->assertJsonStructure(['categories', 'shipments', 'allocations']);
    }

    public function test_department_can_create_idempotent_expense_only_for_assigned_shipment(): void
    {
        $operations = $this->user('Operations');
        $shipmentId = $this->shipment($operations);
        $categoryId = DB::table('expense_categories')->where('code', 'fuel')->value('id');
        $key = 'phase-six-'.fake()->uuid();
        $payload = [
            'shipment_id' => $shipmentId, 'department' => 'Operations', 'category_id' => $categoryId, 'description' => 'Diesel for delivery',
            'incurred_on' => now()->toDateString(), 'amount' => '125.50', 'currency' => 'PHP', 'recoverability' => 'company_nonrecoverable',
            'idempotency_key' => $key,
        ];
        $created = $this->actingAs($operations)->postJson('/api/expense-workspace/expenses', $payload)->assertCreated();
        $this->assertMatchesRegularExpression('/^EXP-\d{4}-\d{6}$/', $created->json('data.reference'));
        $this->actingAs($operations)->postJson('/api/expense-workspace/expenses', $payload)->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertSame(1, DB::table('actual_expenses')->where('idempotency_key', $key)->count());
        $this->actingAs($this->user('Sales'))->postJson('/api/expense-workspace/expenses', $payload)->assertForbidden();
    }

    public function test_supporting_document_alone_does_not_satisfy_the_receipt_verification_gate(): void
    {
        Storage::fake('local');
        $operations = $this->user('Operations');
        $accounting = $this->user('Accounting');
        $shipmentId = $this->shipment($operations);
        $expense = $this->actingAs($operations)->postJson('/api/expense-workspace/expenses', [
            'shipment_id' => $shipmentId, 'department' => 'Operations', 'category_id' => DB::table('expense_categories')->where('code', 'fuel')->value('id'),
            'description' => 'Fuel expense', 'incurred_on' => now()->toDateString(), 'amount' => '40.00', 'currency' => 'PHP', 'recoverability' => 'undetermined',
        ])->assertCreated()->json('data');

        $support = $this->actingAs($operations)->post('/api/expense-workspace/expenses/'.$expense['id'].'/documents', ['file' => UploadedFile::fake()->create('approval.pdf', 20, 'application/pdf'), 'category' => 'supporting_document'])->assertCreated()->json('data');
        $this->actingAs($accounting)->postJson('/api/expense-workspace/documents/'.$support['id'].'/verify', ['decision' => 'verified', 'reason' => 'Supporting approval reviewed'])->assertOk()->assertJsonPath('receipt_status', 'missing');
        $this->actingAs($operations)->postJson('/api/expense-workspace/expenses/'.$expense['id'].'/submit')->assertOk()->assertJsonPath('data.receipt_status', 'missing');
        $this->actingAs($accounting)->postJson('/api/expense-workspace/expenses/'.$expense['id'].'/verify', ['decision' => 'verified', 'reason' => 'Review complete'])->assertUnprocessable();
    }
}
