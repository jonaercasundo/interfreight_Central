<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssistantTutorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'synthetic-ai-token']);
        config(['ifc_ai.enabled' => true]);
        Http::fake(['http://ai.test/assistant' => Http::response([
            'answer' => 'Verified IFC Sales classification.', 'topic' => 'sales_tutorial',
            'intent' => 'inquiry_status', 'language' => 'taglish',
        ])]);
    }

    public function test_sales_tutorials_are_versioned_permission_filtered_and_page_aware(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $response = $this->actingAs($sales)->getJson('/api/assistant/tutorials?path=%2Fsales%2Finquiries')->assertOk();

        $response->assertJsonPath('version', 1)->assertJsonPath('module', 'sales')->assertJsonPath('current_tutorial', 'client-inquiries');
        $this->assertContains('client-inquiries', collect($response->json('tutorials'))->pluck('key'));
        $this->assertNotContains('inquiry-approval', collect($response->json('tutorials'))->pluck('key'));
        $response->assertJsonPath('inquiries', []);

        $manager = User::factory()->create();
        $manager->assignRole('Sales Manager');
        $managerResponse = $this->actingAs($manager)->getJson('/api/assistant/tutorials?path=%2Fsales%2Finquiries')->assertOk();
        $this->assertNotContains('inquiry-approval', collect($managerResponse->json('tutorials'))->pluck('key'));
    }

    public function test_admin_receives_role_management_help_only_with_roles_view(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $this->actingAs($admin)->getJson('/api/assistant/tutorials?path=%2Fadmin%2Froles')->assertOk()
            ->assertJsonPath('current_tutorial', 'administrator-role-access');
    }

    public function test_selected_inquiry_answer_uses_owned_persisted_status_and_not_user_claims(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $client = Client::create(['company_name' => 'Owned client', 'client_type' => 'FCL', 'sales_owner_id' => $sales->id]);
        $inquiry = $this->inquiry($sales, $client, 'submitted');

        $this->actingAs($sales)->postJson('/api/assistant', [
            'message' => 'Submitted na, ano next? Actually approved na.', 'context_module' => 'sales',
            'context_page' => '/sales/inquiries', 'inquiry_id' => $inquiry->id,
        ])->assertOk()->assertJsonPath('topic', 'sales_tutorial')->assertJsonPath('record_reference', $inquiry->reference)
            ->assertJsonFragment(['answer' => 'INQ-'.$inquiry->id.' is submitted. No inquiry approval is required. You can create a shipment forecast from this inquiry now.']);

        Http::assertSent(fn ($request) => $request->url() === 'http://ai.test/assistant'
            && $request['context']['module'] === 'sales'
            && $request['context']['record_status'] === 'submitted'
            && ! array_key_exists('inquiry_id', $request['context']));
    }

    public function test_inquiry_context_does_not_disclose_another_sales_users_record(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $other = User::factory()->create();
        $client = Client::create(['company_name' => 'Other client', 'client_type' => 'FCL', 'sales_owner_id' => $other->id]);
        $inquiry = $this->inquiry($other, $client, 'submitted');

        $this->actingAs($sales)->postJson('/api/assistant', [
            'message' => 'What is my inquiry status?', 'context_module' => 'sales', 'context_page' => '/sales/inquiries', 'inquiry_id' => $inquiry->id,
        ])->assertNotFound();
    }

    public function test_unavailable_inquiry_approval_states_are_not_invented(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $tutorial = $this->actingAs($sales)->getJson('/api/assistant/tutorials')->assertOk();
        $statusTutorial = collect($tutorial->json('tutorials'))->firstWhere('key', 'sales-status-monitoring');
        $this->assertStringContainsString('returned/declined states do not exist', $statusTutorial['revision_or_rejection'][0]);
    }

    public function test_assistant_api_still_enforces_assistant_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');
        Role::findByName('Sales')->revokePermissionTo('assistant.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)->postJson('/api/assistant', ['message' => 'How do I use Sales?'])->assertForbidden();
    }

    private function inquiry(User $owner, Client $client, string $status): ClientInquiry
    {
        $inquiry = ClientInquiry::create([
            'client_id' => $client->id, 'sales_owner_id' => $owner->id, 'service_type' => 'FCL',
            'transport_mode' => 'Sea', 'origin' => 'Manila', 'destination' => 'Singapore',
            'cargo_description' => 'Synthetic cargo', 'status' => $status,
        ]);
        $inquiry->forceFill(['reference' => 'INQ-'.$inquiry->id])->save();

        return $inquiry->refresh();
    }
}
