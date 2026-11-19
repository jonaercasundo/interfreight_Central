<?php

namespace Tests\Feature;

use App\Models\AssistantConversation;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private User $allowed;

    private User $denied;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->allowed = User::factory()->create();
        $this->allowed->assignRole('Operations');
        $this->denied = User::factory()->create();
        $this->denied->assignRole('Executive');
        config([
            'services.ai.url' => 'http://127.0.0.1:8001',
            'services.ai.token' => 'test-service-token',
            'services.ai.connect_timeout' => 1,
            'services.ai.timeout' => 2,
            'ifc_ai.enabled' => true,
        ]);
    }

    public function test_authenticated_authorized_user_can_use_assistant(): void
    {
        Http::fake(['*' => Http::response(['answer' => 'Plan buffers by lane.', 'topic' => 'delays'], 200)]);

        $this->actingAs($this->allowed)
            ->postJson('/api/assistant', ['message' => 'How can we reduce delays?'])
            ->assertOk()
            ->assertHeader('X-Correlation-ID')
            ->assertExactJson(['answer' => 'Plan buffers by lane.', 'topic' => 'delays']);

        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer test-service-token')
            && $request->hasHeader('X-Correlation-ID')
            && $request->url() === 'http://127.0.0.1:8001/assistant'
            && $request['message'] === 'How can we reduce delays?'
        );
    }

    public function test_conversation_history_is_forwarded_and_persisted_for_the_owner(): void
    {
        Http::fake(['*' => Http::response(['answer' => 'Kumusta! I can help with that.', 'topic' => 'general', 'language' => 'taglish'], 200)]);
        $conversationId = $this->actingAs($this->allowed)->postJson('/api/assistant/conversations')->assertCreated()->json('data.conversation_id');

        $this->postJson('/api/assistant', ['message' => 'Paano gumawa ng inquiry?', 'conversation_id' => $conversationId])->assertOk()->assertJsonPath('live_ai', true);
        $this->postJson('/api/assistant', ['message' => 'Actually, Taglish please.', 'conversation_id' => $conversationId])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://127.0.0.1:8001/assistant'
            && $request['message'] === 'Actually, Taglish please.'
            && ($request['context']['conversation_history'][0]['content'] ?? null) === 'Paano gumawa ng inquiry?'
            && ($request['context']['conversation_history'][1]['content'] ?? null) === 'Kumusta! I can help with that.'
        );
        $this->getJson('/api/assistant/conversations/'.$conversationId)->assertOk()
            ->assertJsonPath('data.messages.2.content', 'Actually, Taglish please.')
            ->assertJsonPath('data.messages.3.content', 'Kumusta! I can help with that.');
        $this->assertDatabaseHas('assistant_conversations', ['conversation_uuid' => $conversationId, 'user_id' => $this->allowed->id]);
    }

    public function test_conversations_are_private_to_their_owner_and_can_be_deleted(): void
    {
        $conversationId = $this->actingAs($this->allowed)->postJson('/api/assistant/conversations')->assertCreated()->json('data.conversation_id');
        $other = User::factory()->create();
        $other->assignRole('Operations');
        $this->actingAs($other)->getJson('/api/assistant/conversations/'.$conversationId)->assertNotFound();
        $this->actingAs($other)->deleteJson('/api/assistant/conversations/'.$conversationId)->assertNotFound();
        $this->actingAs($this->allowed)->getJson('/api/assistant/conversations')->assertJsonCount(1, 'data');
        $this->deleteJson('/api/assistant/conversations/'.$conversationId)->assertNoContent();
        $this->assertDatabaseMissing('assistant_conversations', ['conversation_uuid' => $conversationId]);
    }

    public function test_expired_conversations_are_inaccessible_and_pruned(): void
    {
        $expired = AssistantConversation::query()->create([
            'user_id' => $this->allowed->id,
            'conversation_uuid' => (string) Str::uuid(),
            'messages' => [],
            'last_activity_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->allowed)->getJson('/api/assistant/conversations/'.$expired->conversation_uuid)->assertNotFound();
        $this->artisan('ifc:assistant-prune-conversations')->assertSuccessful();
        $this->assertDatabaseMissing('assistant_conversations', ['id' => $expired->id]);
    }

    public function test_conversation_rename_is_owner_scoped(): void
    {
        $conversationId = $this->actingAs($this->allowed)->postJson('/api/assistant/conversations')->assertCreated()->json('data.conversation_id');
        $other = User::factory()->create();
        $other->assignRole('Operations');

        $this->actingAs($other)->patchJson('/api/assistant/conversations/'.$conversationId, ['title' => 'Unauthorized rename'])->assertNotFound();
        $this->actingAs($this->allowed)->patchJson('/api/assistant/conversations/'.$conversationId, ['title' => 'Client onboarding'])->assertOk()->assertJsonPath('data.title', 'Client onboarding');
        $this->getJson('/api/assistant/conversations')->assertJsonPath('data.0.title', 'Client onboarding');
    }

    public function test_proxy_communicates_with_a_running_python_service(): void
    {
        $baseUrl = getenv('AI_E2E_URL');
        $token = getenv('AI_E2E_TOKEN') ?: config('services.ai.token');

        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($token) || $token === '') {
            $this->markTestSkipped('Set AI_E2E_URL and AI_E2E_TOKEN to run the local Python integration check.');
        }

        config(['services.ai.url' => $baseUrl, 'services.ai.token' => $token]);

        $this->actingAs($this->allowed)
            ->postJson('/api/assistant', ['message' => 'How do we prevent delays?'])
            ->assertOk()
            ->assertJsonPath('topic', 'delays')
            ->assertJsonPath('answer', fn ($answer): bool => is_string($answer) && str_contains($answer, 'buffer time'));
    }

    public function test_assistant_requires_authenticated_user(): void
    {
        $this->postJson('/api/assistant', ['message' => 'How can we reduce delays?'])
            ->assertUnauthorized();
    }

    public function test_assistant_requires_authorized_user(): void
    {
        $this->actingAs($this->denied)
            ->postJson('/api/assistant', ['message' => 'How can we reduce delays?'])
            ->assertForbidden();
    }

    public function test_assistant_rejects_blank_message(): void
    {
        $this->actingAs($this->allowed)
            ->postJson('/api/assistant', ['message' => " \t "])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_python_service_failure_returns_safe_unavailable_response(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'Internal failure'], 500)]);

        $this->actingAs($this->allowed)
            ->postJson('/api/assistant', ['message' => 'How can we reduce delays?'])
            ->assertStatus(503)
            ->assertExactJson(['detail' => 'AI service is temporarily unavailable. Open Tutorials for verified static guidance.']);
    }

    public function test_python_service_timeout_returns_safe_unavailable_response(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        $this->actingAs($this->allowed)
            ->postJson('/api/assistant', ['message' => 'How can we reduce delays?'])
            ->assertStatus(503)
            ->assertExactJson(['detail' => 'AI service is temporarily unavailable. Open Tutorials for verified static guidance.']);
    }

    public function test_invalid_python_response_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['answer' => ['unexpected'], 'topic' => 'unknown'], 200)]);

        $this->actingAs($this->allowed)
            ->postJson('/api/assistant', ['message' => 'How can we reduce delays?'])
            ->assertStatus(503)
            ->assertExactJson(['detail' => 'AI service is temporarily unavailable. Open Tutorials for verified static guidance.']);
    }
}
