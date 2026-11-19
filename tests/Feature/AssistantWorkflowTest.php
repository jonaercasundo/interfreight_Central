<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInquiry;
use App\Models\ShipmentForecast;
use App\Models\User;
use App\Services\InquiryNextStepRecommendationEngine;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssistantWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_assistant_recommends_only_permitted_draft_actions_and_submission_requires_confirmation(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales);
        $client = Client::create(['company_name' => 'Guidance Client', 'client_type' => 'LCL', 'sales_owner_id' => $sales->id]);
        $inquiry = $this->draftInquiry($sales, $client);

        $guidance = $this->getJson("/api/assistant/workflows/inquiries/{$inquiry->id}")->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('next_steps.actions.0.key', 'review_submit')
            ->assertJsonPath('next_steps.actions.1.key', 'edit_details')
            ->assertJsonPath('next_steps.actions.2.key', 'create_another');
        $this->assertStringContainsString('not been submitted', $guidance->json('next_steps.message'));

        $this->postJson("/api/assistant/workflows/inquiries/{$inquiry->id}/submit", [])->assertUnprocessable();
        $this->assertDatabaseHas('client_inquiries', ['id' => $inquiry->id, 'status' => 'draft']);

        $submitted = $this->postJson("/api/assistant/workflows/inquiries/{$inquiry->id}/submit", ['confirmed' => true])->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('action_results.0.action', 'sales_inquiry.updated')
            ->assertJsonPath('action_results.0.status', 'submitted')
            ->assertJsonPath('next_steps.status', 'submitted');
        $this->assertTrue($submitted->json('action_results.0.correlation_id') !== null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inquiries.submitted', 'actor_id' => $sales->id]);

        $status = $this->getJson("/api/assistant/workflows/inquiries/{$inquiry->id}")->assertOk()
            ->assertJsonPath('next_steps.status', 'submitted');
        $this->assertStringContainsString('No inquiry approval is required', $status->json('next_steps.message'));
        $this->assertSame(['create_forecast', 'create_another', 'view_inquiry', 'check_status'], collect($status->json('next_steps.actions'))->pluck('key')->all());
    }

    public function test_assistant_workflow_cannot_read_or_submit_another_sales_owners_inquiry(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Sales');
        $other = User::factory()->create();
        $other->assignRole('Sales');
        $client = Client::create(['company_name' => 'Private Workflow Client', 'client_type' => 'LCL', 'sales_owner_id' => $owner->id]);
        $inquiry = $this->draftInquiry($owner, $client);

        $this->actingAs($other)->getJson("/api/assistant/workflows/inquiries/{$inquiry->id}")->assertNotFound();
        $this->postJson("/api/assistant/workflows/inquiries/{$inquiry->id}/submit", ['confirmed' => true])->assertNotFound();
        $this->assertDatabaseHas('client_inquiries', ['id' => $inquiry->id, 'status' => 'draft', 'sales_owner_id' => $owner->id]);
    }

    public function test_assistant_workflow_rejects_users_without_submission_permission(): void
    {
        $sales = User::factory()->create();
        $role = Role::create(['name' => 'Inquiry Assistant Viewer', 'guard_name' => 'web']);
        $role->givePermissionTo(['assistant.use', 'inquiries.view', 'inquiries.create']);
        $sales->assignRole($role);
        $client = Client::create(['company_name' => 'Restricted Workflow Client', 'client_type' => 'LCL', 'sales_owner_id' => $sales->id]);
        $inquiry = $this->draftInquiry($sales, $client);
        $this->actingAs($sales)->postJson("/api/assistant/workflows/inquiries/{$inquiry->id}/submit", ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseHas('client_inquiries', ['id' => $inquiry->id, 'status' => 'draft']);
    }

    public function test_incomplete_draft_cannot_be_submitted_and_guidance_identifies_missing_details(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $client = Client::create(['company_name' => 'Incomplete Inquiry Client', 'client_type' => 'LCL', 'sales_owner_id' => $sales->id]);
        $inquiry = $this->draftInquiry($sales, $client);
        $inquiry->update(['cargo_description' => '']);

        $guidance = $this->actingAs($sales)->getJson("/api/assistant/workflows/inquiries/{$inquiry->id}")
            ->assertOk()->assertJsonPath('next_steps.missing_fields.0', 'a cargo description');
        $this->assertFalse(collect($guidance->json('next_steps.actions'))->contains('key', 'review_submit'));
        $this->postJson("/api/assistant/workflows/inquiries/{$inquiry->id}/submit", ['confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseHas('client_inquiries', ['id' => $inquiry->id, 'status' => 'draft']);
    }

    public function test_forecasted_and_under_review_inquiries_offer_only_verified_available_next_steps(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $client = Client::create(['company_name' => 'Next Step Forecast Client', 'client_type' => 'FCL', 'sales_owner_id' => $sales->id]);
        $inquiry = $this->draftInquiry($sales, $client);
        $inquiry->update(['status' => 'forecasted']);
        $forecast = ShipmentForecast::create([
            'inquiry_id' => $inquiry->id, 'sales_owner_id' => $sales->id, 'forecast_month' => today()->startOfMonth()->toDateString(),
            'expected_shipment_count' => 1, 'confidence' => 'probable', 'status' => 'active',
        ]);
        $engine = app(InquiryNextStepRecommendationEngine::class);

        $estimateGuidance = $engine->recommend($sales, $inquiry->fresh());
        $this->assertTrue(collect($estimateGuidance['actions'])->contains('key', 'prepare_cost_estimate'));

        $inquiry->update(['status' => 'under_review']);
        $forecastGuidance = $engine->recommend($sales, $inquiry->fresh());
        $this->assertTrue(collect($forecastGuidance['actions'])->contains('key', 'create_forecast'));
        $this->assertSame('active', $forecast->fresh()->status);
    }

    public function test_recommendations_support_taglish_and_filipino_without_inventing_unimplemented_states(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $client = Client::create(['company_name' => 'Language Guidance Client', 'client_type' => 'LCL', 'sales_owner_id' => $sales->id]);
        $inquiry = new ClientInquiry([
            'sales_owner_id' => $sales->id, 'client_id' => $client->id, 'service_type' => 'LCL', 'transport_mode' => 'Sea',
            'origin' => 'Manila', 'destination' => 'Singapore', 'cargo_description' => 'Sample cargo', 'status' => 'draft',
        ]);
        $inquiry->setAttribute('reference', 'INQ-2026-TEST');
        $engine = app(InquiryNextStepRecommendationEngine::class);

        $this->assertStringContainsString('Hindi pa ito submitted', $engine->recommend($sales, $inquiry, 'taglish')['message']);
        $this->assertStringContainsString('Hindi pa ito naipapasa', $engine->recommend($sales, $inquiry, 'filipino')['message']);

        $inquiry->status = 'returned';
        $recommendation = $engine->recommend($sales, $inquiry);
        $this->assertStringContainsString('does not define a verified next action', $recommendation['message']);
        $this->assertFalse(collect($recommendation['actions'])->contains('key', 'review_submit'));
    }

    private function draftInquiry(User $owner, Client $client): ClientInquiry
    {
        return ClientInquiry::create([
            'client_id' => $client->id,
            'sales_owner_id' => $owner->id,
            'service_type' => 'LCL',
            'transport_mode' => 'Sea',
            'origin' => 'Manila, Philippines',
            'destination' => 'Singapore, Singapore',
            'cargo_description' => 'Sample cargo',
            'status' => 'draft',
            'revision' => 1,
        ]);
    }
}
