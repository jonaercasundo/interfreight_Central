<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiConnectionFailure;
use App\Services\AiServiceClient;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiConfigurationTest extends TestCase
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

    public function test_only_administrators_can_view_and_change_configuration(): void
    {
        $this->get('/api/internal/ai-credential')->assertUnauthorized();
        $this->get('/admin/settings/ai')->assertRedirect('/login');
        $sales = $this->user('Sales');
        $this->assertFalse($sales->can('admin.ai-config.view'));
        $this->assertFalse($sales->can('admin.ai-config.manage'));
        $this->assertFalse($sales->can('admin.ai-config.test'));
        $this->actingAs($sales)->get('/admin/settings/ai')->assertForbidden();
        $this->actingAs($sales)->getJson('/api/admin/ai-configuration')->assertForbidden();
        $admin = $this->user('Administrator');
        $this->assertTrue($admin->can('admin.ai-config.view'));
        $this->assertTrue($admin->can('admin.ai-config.manage'));
        $this->assertTrue($admin->can('admin.ai-config.test'));
        $this->actingAs($admin)->get('/admin/settings/ai')->assertOk()->assertSee('data-page="ai-configuration"', false);
    }

    public function test_ai_settings_page_and_read_endpoint_explain_when_tables_are_missing(): void
    {
        $admin = $this->user('Administrator');
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_configurations');

        $this->actingAs($admin)->get('/admin/settings/ai')->assertOk()->assertSee('data-page="ai-configuration"', false);
        $this->actingAs($admin)->getJson('/api/admin/ai-configuration')
            ->assertOk()
            ->assertJsonPath('settings.database_ready', false)
            ->assertJsonPath('usage.requests', 0);
        $this->actingAs($admin)->putJson('/api/admin/ai-configuration', [])->assertStatus(503)->assertJsonPath('message', 'AI settings database tables are not installed. Apply the pending IFC migrations, then reload this page.');
    }

    public function test_api_key_is_encrypted_and_never_returned_in_browser_or_audit_payloads(): void
    {
        $admin = $this->user('Administrator');
        $secret = 'sk-test-only-never-a-real-openai-key';
        $response = $this->actingAs($admin)->putJson('/api/admin/ai-configuration', [
            'enabled' => true, 'tutorials_enabled' => true, 'model' => 'gpt-4.1-mini',
            'timeout_seconds' => 24, 'max_output_tokens' => 600,
            'api_key' => $secret, 'confirm_credential_change' => true,
        ])->assertOk();

        $this->assertStringNotContainsString($secret, $response->getContent());
        $raw = DB::table('ai_configurations')->value('api_key_ciphertext');
        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));
        $this->assertDatabaseHas('ai_configurations', ['timeout_seconds' => 24, 'max_output_tokens' => 600]);
        $response->assertJsonPath('settings.credential_source', 'administrator')
            ->assertJsonPath('settings.masked_credential', '••••••••'.substr($secret, -4));
        $this->assertStringNotContainsString($secret, json_encode(DB::table('audit_logs')->get()->toArray()));
    }

    public function test_credential_rotation_requires_confirmation_and_removal_requires_explicit_confirmation(): void
    {
        $admin = $this->user('Administrator');
        $payload = ['enabled' => false, 'tutorials_enabled' => true, 'model' => 'gpt-4.1-mini', 'api_key' => 'sk-test-only-never-a-real-openai-key'];
        $this->actingAs($admin)->putJson('/api/admin/ai-configuration', $payload)->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/admin/ai-configuration', [...$payload, 'confirm_credential_change' => true])->assertOk();
        $this->actingAs($admin)->deleteJson('/api/admin/ai-configuration/credential', ['confirmation' => 'no'])->assertUnprocessable();
        $this->actingAs($admin)->deleteJson('/api/admin/ai-configuration/credential', ['confirmation' => 'REMOVE'])->assertOk()->assertJsonPath('settings.credential_configured', false);
    }

    public function test_connection_test_calls_only_the_signed_internal_endpoint_after_explicit_request(): void
    {
        config(['services.ai.url' => 'https://ai.example.test', 'services.ai.token' => 'synthetic-internal-token', 'services.ai.verify_tls' => true]);
        $admin = $this->user('Administrator');
        $this->actingAs($admin)->putJson('/api/admin/ai-configuration', [
            'enabled' => true, 'tutorials_enabled' => true, 'model' => 'gpt-4.1-mini', 'api_key' => 'sk-synthetic-api-key-for-tests', 'confirm_credential_change' => true,
        ])->assertOk();

        Http::fake(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://ai.example.test/internal/openai-test', $request->url());
            $this->assertSame('gpt-4.1-mini', $request['model']);
            $this->assertNotSame('sk-synthetic-api-key-for-tests', $request->body());
            $this->assertNotEmpty($request->header('X-IFC-Signature'));

            return Http::response(['status' => 'connected', 'model' => 'gpt-4.1-mini'], 200);
        });

        $this->actingAs($admin)->postJson('/api/admin/ai-configuration/test')->assertOk()
            ->assertJsonPath('result.status', 'connected')
            ->assertJsonPath('settings.last_test_status', 'connected');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_events', ['user_id' => $admin->id, 'status' => 'success']);
    }

    public function test_missing_signed_service_configuration_is_reported_without_overwriting_saved_key(): void
    {
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => '']);
        $admin = $this->user('Administrator');
        $secret = 'sk-synthetic-key-that-must-remain-saved';
        $this->actingAs($admin)->putJson('/api/admin/ai-configuration', [
            'enabled' => true, 'tutorials_enabled' => true, 'model' => 'gpt-4.1-mini',
            'api_key' => $secret, 'confirm_credential_change' => true,
        ])->assertOk();

        $this->actingAs($admin)->postJson('/api/admin/ai-configuration/test')->assertOk()
            ->assertJsonPath('result.status', 'error')
            ->assertJsonPath('result.category', 'configuration_missing')
            ->assertJsonPath('result.message', 'Configure AI_SERVICE_URL and AI_SERVICE_TOKEN in Laravel, match AI_SERVICE_TOKEN in FastAPI, set AI_LARAVEL_CREDENTIAL_URL to Laravel’s signed credential endpoint, then restart both services.')
            ->assertJsonPath('settings.last_test_status', 'configuration_missing');
        $stored = DB::table('ai_configurations')->value('api_key_ciphertext');
        $this->assertSame($secret, Crypt::decryptString($stored));
    }

    public function test_connection_test_preserves_sanitized_provider_categories(): void
    {
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => 'synthetic-internal-token']);
        $category = null;
        Http::fake(function () use (&$category) {
            return Http::response(['status' => 'error', 'category' => $category], 200);
        });
        foreach (['invalid_api_key', 'model_not_found', 'model_access_denied', 'insufficient_quota', 'rate_limited', 'ssl_certificate_error', 'connection_timeout', 'dns_error', 'provider_unavailable', 'invalid_provider_response'] as $category) {
            try {
                app(AiServiceClient::class)->testOpenAi('gpt-4.1-mini');
                $this->fail('The failed provider response should throw a sanitized diagnostic.');
            } catch (AiConnectionFailure $failure) {
                $this->assertSame($category, $failure->category);
                $this->assertStringNotContainsString('sk-synthetic', $failure->getMessage());
            }
        }
    }

    public function test_disabling_ai_blocks_assistant_requests_and_disabling_tutorials_hides_tutorial_content(): void
    {
        config(['ifc_ai.enabled' => false, 'ifc_ai.tutorials_enabled' => false]);
        $sales = $this->user('Sales');
        $this->actingAs($sales)->postJson('/api/assistant', ['message' => 'How do I create an inquiry?', 'context_module' => 'sales'])->assertStatus(503);
        $this->actingAs($sales)->getJson('/api/assistant/tutorials')->assertOk()->assertJsonPath('tutorials_enabled', false)->assertJsonPath('tutorials', []);
    }
}
