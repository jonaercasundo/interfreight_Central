<?php

namespace Tests\Feature;

use App\Models\AssistantFormSession;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantActionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_natural_action_phrases_start_one_persisted_inquiry_workflow(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        foreach (['Start new client inquiries.', 'Gawa tayo ng inquiry.', 'I need a new shipment inquiry.', 'Can you prepare an inquiry for me?'] as $message) {
            $conversation = $this->postJson('/api/assistant/conversations')->assertCreated()->json('data.conversation_id');
            $response = $this->postJson('/api/assistant', ['message' => $message, 'conversation_id' => $conversation])
                ->assertOk()
                ->assertJsonPath('intent', 'CREATE_SALES_INQUIRY')
                ->assertJsonPath('answer', "Sure! I'll help you create a new Sales Inquiry. Is this for an existing client or a new client?")
                ->assertJsonPath('workflow_session.current_field', 'client_kind');
            $this->assertSame($conversation, $response->json('workflow_session.conversation_uuid'));
        }
        $this->assertSame(4, AssistantFormSession::query()->where('user_id', $sales->id)->count());
        Http::assertNothingSent();
    }

    public function test_informational_question_does_not_start_a_workflow(): void
    {
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => 'test-service-token', 'ifc_ai.enabled' => true]);
        Http::fake(['*' => Http::response(['answer' => 'Would you like me to start one?', 'topic' => 'sales_tutorial', 'intent' => 'create_inquiry', 'action_requested' => true], 200)]);
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales)->postJson('/api/assistant', ['message' => 'How do I create an inquiry?'])
            ->assertOk()->assertJsonMissingPath('workflow_session');
        $this->assertDatabaseCount('assistant_form_sessions', 0);
    }

    public function test_unauthorized_employee_cannot_start_an_inquiry_workflow(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('Operations');
        $this->actingAs($operations)->postJson('/api/assistant', ['message' => 'Start new client inquiries.'])
            ->assertForbidden();
        $this->assertDatabaseCount('assistant_form_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_help_me_fill_form_on_client_management_starts_client_form_instead_of_tutorial(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $conversation = $this->postJson('/api/assistant/conversations')->assertCreated()->json('data.conversation_id');

        $this->postJson('/api/assistant', [
            'message' => 'Help me fill up the form.', 'conversation_id' => $conversation,
            'context_module' => 'sales', 'context_page' => '/sales/clients',
        ])->assertOk()
            ->assertJsonPath('intent', 'CREATE_CLIENT')
            ->assertJsonPath('workflow_session.form_key', 'client_profile')
            ->assertJsonPath('workflow_session.current_field', 'company_name')
            ->assertJsonPath('workflow_session.question', 'What company or client name should I enter?')
            ->assertJsonMissingPath('tutorial_key');

        $this->assertDatabaseHas('assistant_form_sessions', ['user_id' => $sales->id, 'conversation_uuid' => $conversation, 'form_key' => 'client_profile']);
        Http::assertNothingSent();
    }

    public function test_client_form_uses_real_choices_requires_confirmation_and_saves_exactly_once(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $conversation = $this->postJson('/api/assistant/conversations')->assertCreated()->json('data.conversation_id');
        $sessionId = $this->postJson('/api/assistant', [
            'message' => 'Create a client for me.', 'conversation_id' => $conversation,
        ])->assertOk()->assertJsonPath('intent', 'CREATE_CLIENT')->assertJsonPath('workflow_session.current_field', 'company_name')->json('workflow_session.id');

        $answers = ['Harbor Imports', 'fcl', 'Taylor Ng', 'taylor@example.test', '+65 6123 4567', '10 Marina Boulevard'];
        foreach ($answers as $answer) {
            $response = $this->postJson("/api/assistant/client-form-sessions/{$sessionId}/answer", ['answer' => $answer, 'conversation_uuid' => $conversation])->assertOk();
        }
        $response->assertJsonPath('data.status', 'review')->assertJsonPath('data.values.client_type', 'FCL');
        $this->assertDatabaseCount('clients', 0);
        $this->postJson("/api/assistant/client-form-sessions/{$sessionId}/save", ['confirmed' => false, 'conversation_uuid' => $conversation])->assertUnprocessable();
        $this->assertDatabaseCount('clients', 0);

        $saved = $this->postJson("/api/assistant/client-form-sessions/{$sessionId}/save", ['confirmed' => true, 'conversation_uuid' => $conversation])
            ->assertOk()->assertJsonPath('data.status', 'saved')->assertJsonPath('client.company_name', 'Harbor Imports')
            ->assertJsonPath('client.client_type', 'FCL')->assertJsonPath('action_results.0.action', 'client.created')
            ->assertJsonPath('next_steps.actions.0.key', 'start_sales_inquiry');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->postJson("/api/assistant/client-form-sessions/{$sessionId}/save", ['confirmed' => true, 'conversation_uuid' => $conversation])->assertOk()->assertJsonCount(0, 'action_results');
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'clients.created']);
        $this->assertSame($saved->json('client.reference'), $this->getJson('/api/sales/clients')->assertOk()->json('data.0.reference'));
    }

    public function test_employee_without_client_create_permission_cannot_start_client_form(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('Operations');
        $this->actingAs($operations)->postJson('/api/assistant', ['message' => 'Create a client for me.'])->assertForbidden();
        $this->assertDatabaseCount('assistant_form_sessions', 0);
        $this->assertDatabaseCount('clients', 0);
        Http::assertNothingSent();
    }
}
