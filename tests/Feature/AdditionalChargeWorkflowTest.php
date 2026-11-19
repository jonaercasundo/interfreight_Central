<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdditionalChargeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ?User $salesOwner = null;

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

    private function shipment(User $operations): int
    {
        $sales = $this->user('Sales');
        $this->salesOwner = $sales;
        $clientId = DB::table('clients')->insertGetId(['company_name' => 'ACR Test Client', 'client_type' => 'FCL', 'sales_owner_id' => $sales->id, 'created_at' => now(), 'updated_at' => now()]);
        $response = $this->actingAs($operations)->postJson('/api/shipments-workspace', ['source_type' => 'direct', 'client_id' => $clientId, 'originating_department' => 'Operations', 'shipment_type' => 'Import', 'service_type' => 'Operations', 'mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Cebu', 'cargo' => 'ACR test cargo', 'departments' => ['Operations']])->assertCreated();

        return $response->json('data.id');
    }

    private function request(User $operations, int $shipment): int
    {
        $created = $this->actingAs($operations)->postJson('/api/additional-charges', ['shipment_id' => $shipment, 'department' => 'Operations', 'category' => 'demurrage', 'description' => 'Unexpected port demurrage', 'justification' => 'Carrier charged additional storage after a port delay.', 'actual_cost' => '10000.00', 'proposed_amount' => '12000.00', 'currency' => 'PHP'])->assertCreated();
        $this->assertMatchesRegularExpression('/^ACR-\d{4}-\d{6}$/', $created->json('data.reference'));

        return $created->json('data.id');
    }

    public function test_request_requires_auth_and_role_permission_and_shipment_assignment(): void
    {
        $this->getJson('/api/additional-charges')->assertUnauthorized();
        $sales = $this->user('Sales');
        $this->actingAs($sales)->postJson('/api/additional-charges', [])->assertForbidden();
        $operations = $this->user('Operations');
        $shipment = $this->shipment($operations);
        $this->actingAs($this->user('Transport'))->postJson('/api/additional-charges', ['shipment_id' => $shipment])->assertUnprocessable();
        $this->assertSame(0, DB::table('additional_charge_requests')->count());
    }

    public function test_only_verified_client_approval_evidence_creates_billing_eligibility_and_partial_amount_is_preserved(): void
    {
        $operations = $this->user('Operations');
        $id = $this->request($operations, $this->shipment($operations));
        $this->actingAs($operations)->post('/api/additional-charges/'.$id.'/evidence', ['file' => UploadedFile::fake()->create('carrier.pdf', 20, 'application/pdf'), 'document_type' => 'carrier_invoice'])->assertCreated();
        $this->actingAs($operations)->postJson('/api/additional-charges/'.$id.'/submit')->assertOk();

        $sales = $this->salesOwner;
        $this->actingAs($operations)->postJson('/api/additional-charges/'.$id.'/review', ['decision' => 'approve', 'reason' => 'Requester cannot approve their own request'])->assertForbidden();
        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson('/api/additional-charges/'.$id.'/review', ['decision' => 'approve', 'reason' => 'Finance reviewed the exposure'])->assertOk();
        $this->actingAs($sales)->postJson('/api/additional-charges/'.$id.'/client-request', [])->assertOk();
        $evidence = $this->actingAs($sales)->post('/api/additional-charges/'.$id.'/evidence', ['file' => UploadedFile::fake()->create('client-approval.pdf', 20, 'application/pdf'), 'document_type' => 'signed_decision'])->assertCreated()->json('data.evidence');
        $evidenceId = collect($evidence)->firstWhere('document_type', 'signed_decision')['id'];
        $this->actingAs($finance)->postJson('/api/additional-charges/'.$id.'/evidence/'.$evidenceId.'/verify', ['decision' => 'verified', 'reason' => 'Signed client document verified'])->assertOk();
        $this->actingAs($sales)->postJson('/api/additional-charges/'.$id.'/client-decision', ['decision' => 'partially_approved', 'approved_amount' => '6000.00', 'representative' => 'Client approver', 'remarks' => 'Client accepted partial amount.'])->assertOk()->assertJsonPath('data.approved_amount', '6000.00');

        $this->actingAs($this->user('Billing'))->getJson('/api/additional-charges/billing-eligibility')->assertOk()->assertJsonPath('data.data.0.approved_amount', '6000.00');
        $this->assertSame(1, DB::table('commercial_change_authorizations')->where('request_id', $id)->count());
        $this->assertEquals(12000.00, DB::table('additional_charge_requests')->where('id', $id)->value('proposed_amount'));
        $this->assertDatabaseHas('additional_charge_events', ['request_id' => $id, 'event' => 'commercial_change_authorized']);
    }

    public function test_unverified_decision_evidence_cannot_authorize_charge_and_rejection_remains_nonbillable(): void
    {
        $operations = $this->user('Operations');
        $id = $this->request($operations, $this->shipment($operations));
        $this->actingAs($operations)->post('/api/additional-charges/'.$id.'/evidence', ['file' => UploadedFile::fake()->create('carrier.pdf', 20, 'application/pdf'), 'document_type' => 'carrier_invoice'])->assertCreated();
        $this->actingAs($operations)->postJson('/api/additional-charges/'.$id.'/submit')->assertOk();
        $finance = $this->user('Finance');
        $this->actingAs($finance)->postJson('/api/additional-charges/'.$id.'/review', ['decision' => 'approve', 'reason' => 'Reviewed'])->assertOk();
        $sales = $this->salesOwner;
        $this->actingAs($sales)->postJson('/api/additional-charges/'.$id.'/client-decision', ['decision' => 'approved', 'approved_amount' => '12000.00', 'remarks' => 'Should fail without verified client decision evidence.'])->assertUnprocessable();
        $this->actingAs($sales)->postJson('/api/additional-charges/'.$id.'/client-request', [])->assertOk();
        $this->actingAs($sales)->postJson('/api/additional-charges/'.$id.'/client-decision', ['decision' => 'rejected', 'remarks' => 'Client rejected the charge.'])->assertOk()->assertJsonPath('data.client_status', 'rejected');
        $this->actingAs($this->user('Billing'))->getJson('/api/additional-charges/billing-eligibility')->assertOk()->assertJsonPath('data.total', 0);
        $this->assertSame(0, DB::table('commercial_change_authorizations')->count());
        $this->assertDatabaseHas('additional_charge_requests', ['id' => $id, 'actual_cost' => '10000.00', 'classification' => 'company_absorbed']);
    }
}
