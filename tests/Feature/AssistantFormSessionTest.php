<?php

namespace Tests\Feature;

use App\Models\AssistantFormSession;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantFormSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_sales_employee_can_complete_review_and_confirm_a_validated_inquiry_draft(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $client = $this->postJson('/api/sales/clients', ['company_name' => 'Harbor Imports', 'client_type' => 'LCL'])->assertCreated()->json('data');
        $session = $this->postJson('/api/assistant/form-sessions', ['session_uuid' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->assertSame('client_kind', $session['current_field']);
        $this->assertSame(0, $session['progress']);

        foreach (['Existing client', 'Harbor Imports', 'LCL', 'Sea', 'Vietnam', 'Ho Chi Minh City', 'Philippines', 'Manila', 'Coffee beans'] as $answer) {
            $session = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => $answer])->assertOk()->json('data');
        }
        $this->assertSame('review', $session['status']);
        $this->assertSame(100, $session['progress']);
        $this->assertSame('Harbor Imports', $session['values']['client_name']);
        $this->assertSame('LCL', $session['values']['service_type']);
        $this->assertSame('Sea', $session['values']['transport_mode']);
        $this->assertSame('Ho Chi Minh City, Vietnam', $session['values']['origin']);
        $this->assertSame('Manila, Philippines', $session['values']['destination']);
        $session = $this->putJson('/api/assistant/form-sessions/'.$session['id'].'/fields/origin', ['value' => 'Singapore Container Terminal, Singapore'])->assertOk()->json('data');
        $this->assertSame('Singapore Container Terminal, Singapore', $session['values']['origin']);

        $saveResponse = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/draft', ['confirmed' => true])->assertCreated();
        $saveResponse->assertJsonPath('action_results.0.action', 'sales_inquiry.created')
            ->assertJsonPath('action_results.0.entity_type', 'sales_inquiry')
            ->assertJsonPath('action_results.0.reference_number', $saveResponse->json('data.draft.reference'))
            ->assertJsonPath('action_results.0.status', 'draft')
            ->assertJsonPath('action_results.0.operation_status', 'success')
            ->assertJsonPath('action_results.0.next_steps.status', 'draft')
            ->assertJsonPath('action_results.0.next_steps.actions.0.key', 'review_submit')
            ->assertJsonPath('action_results.0.next_steps.actions.1.key', 'edit_details')
            ->assertJsonPath('action_results.0.next_steps.actions.2.key', 'create_another');
        $this->assertTrue(Str::isUuid($saveResponse->json('action_results.0.correlation_id')));
        $draft = $saveResponse->json('data');
        $this->assertSame('draft', $draft['draft']['status']);
        $this->assertSame('Singapore Container Terminal, Singapore', $draft['draft']['origin']);
        $this->assertDatabaseHas('assistant_form_sessions', ['id' => $session['id'], 'user_id' => $sales->id, 'status' => 'saved', 'draft_id' => $draft['draft']['id']]);
        $again = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/draft', ['confirmed' => true])->assertCreated()->json('data');
        $this->assertSame($draft['draft']['id'], $again['draft']['id']);
        $this->assertDatabaseCount('client_inquiries', 1);
    }

    public function test_client_lookup_is_owner_scoped_and_session_isolation_is_enforced(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $other = User::factory()->create();
        $other->assignRole('Sales');
        $this->actingAs($other)->postJson('/api/sales/clients', ['company_name' => 'Other Company', 'client_type' => 'LCL'])->assertCreated();
        $this->actingAs($sales);
        $session = $this->postJson('/api/assistant/form-sessions', ['session_uuid' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => 'Existing client'])->assertOk();
        $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => 'Other Company'])->assertUnprocessable()->assertJsonValidationErrors('answer');

        $this->actingAs($other)->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => 'Other Company'])->assertNotFound();
    }

    public function test_ambiguous_client_match_requests_a_choice_without_exposing_ids(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $this->postJson('/api/sales/clients', ['company_name' => 'Pinetech Philippines', 'client_type' => 'LCL'])->assertCreated();
        $this->postJson('/api/sales/clients', ['company_name' => 'Pinetech Logistics', 'client_type' => 'FCL'])->assertCreated();
        $session = $this->postJson('/api/assistant/form-sessions', ['session_uuid' => (string) Str::uuid()])->assertCreated()->json('data');
        $session = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => 'Existing client'])->assertOk()->json('data');
        $matches = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => 'Pinetech'])
            ->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->assertStringNotContainsString('"id":', $matches->getContent());
        $selected = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => '1'])->assertOk()->json('data');
        $this->assertSame('Pinetech Logistics', $selected['values']['client_name']);
    }

    public function test_session_cannot_save_without_employee_confirmation(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $session = AssistantFormSession::query()->create(['user_id' => $sales->id, 'session_uuid' => (string) Str::uuid(), 'form_key' => 'sales_inquiry', 'field_values' => [], 'field_order' => ['client_id'], 'current_step' => 0, 'status' => 'collecting']);
        $this->actingAs($sales)->postJson('/api/assistant/form-sessions/'.$session->id.'/draft', ['confirmed' => false])->assertConflict()->assertJsonMissingPath('action_results');
    }

    public function test_new_client_and_inquiry_are_created_together_only_after_confirmation(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $session = $this->postJson('/api/assistant/form-sessions', ['session_uuid' => (string) Str::uuid()])->assertCreated()->json('data');
        foreach (['New client', 'Pinetech Freight', 'Mixed', 'LCL', 'Sea', 'Vietnam', 'Ho Chi Minh City', 'Philippines', 'Cebu', 'Electronics'] as $answer) {
            $session = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => $answer])->assertOk()->json('data');
        }
        $this->assertSame('review', $session['status']);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('client_inquiries', 0);
        $savedResponse = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/draft', ['confirmed' => true])->assertCreated();
        $savedResponse->assertJsonPath('action_results.0.action', 'sales_inquiry.created')
            ->assertJsonPath('action_results.1.action', 'client.created');
        $saved = $savedResponse->json('data');
        $this->assertSame('draft', $saved['draft']['status']);
        $this->assertSame('Pinetech Freight', $saved['draft']['client']['company_name']);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_inquiries', 1);
    }

    public function test_conversation_linked_form_session_can_be_resumed_after_refresh_and_corrected(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $this->postJson('/api/sales/clients', ['company_name' => 'Harbor Imports', 'client_type' => 'LCL'])->assertCreated();
        $conversation = $this->postJson('/api/assistant/conversations')->assertCreated()->json('data.conversation_id');
        $session = $this->postJson('/api/assistant/form-sessions', ['conversation_uuid' => $conversation])->assertCreated()->json('data');
        foreach (['Existing client', 'Harbor Imports', 'LCL', 'Sea', 'Vietnam', 'Ho Chi Minh City', 'Philippines'] as $answer) {
            $session = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => $answer])->assertOk()->json('data');
        }
        $this->assertSame($conversation, $session['conversation_uuid']);
        $this->assertSame('destination_location_id', $session['current_field']);
        $corrected = $this->postJson('/api/assistant/form-sessions/'.$session['id'].'/answer', ['answer' => 'No, destination should be Cebu, not Manila.'])->assertOk()->json('data');
        $this->assertSame('Cebu, Philippines', $corrected['values']['destination']);
        $this->getJson('/api/assistant/form-sessions')->assertJsonPath('data.0.conversation_uuid', $conversation);
        $this->getJson('/api/assistant/conversations/'.$conversation)->assertJsonPath('data.messages.0.content', 'Existing client');
    }

    public function test_legacy_review_session_returns_to_location_collection_when_only_countries_were_saved(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $client = $this->postJson('/api/sales/clients', ['company_name' => 'Harbor Imports', 'client_type' => 'LCL'])->assertCreated()->json('data');
        AssistantFormSession::query()->create([
            'user_id' => $sales->id,
            'session_uuid' => (string) Str::uuid(),
            'form_key' => 'sales_inquiry',
            'field_values' => [
                'client_kind' => 'existing', 'client_id' => $client['id'], 'service_type' => 'LCL', 'transport_mode' => 'Sea',
                'origin' => 'Vietnam', 'destination' => 'China', 'cargo_description' => 'just a sock',
            ],
            'field_order' => ['client_kind', 'client_id', 'service_type', 'transport_mode', 'origin', 'destination', 'cargo_description'],
            'current_step' => 7,
            'status' => 'review',
        ]);

        $this->getJson('/api/assistant/form-sessions')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'collecting')
            ->assertJsonPath('data.0.current_field', 'origin_location_id')
            ->assertJsonPath('data.0.values.origin_country_code', 'VN')
            ->assertJsonPath('data.0.values.destination_country_code', 'CN');
    }
}
