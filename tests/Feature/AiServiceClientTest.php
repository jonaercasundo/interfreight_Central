<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiServiceClient;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => 'diagnostic-secret', 'services.ai.connect_timeout' => 1, 'services.ai.timeout' => 2]);
    }

    public function test_dashboard_makes_a_signed_protected_diagnostic_call(): void
    {
        Http::fake(['*' => Http::response(['status' => 'authenticated', 'correlation_id' => 'test'], 200)]);
        $user = User::factory()->create();
        $user->assignRole('Operations');
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('integration', 'authenticated');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8001/internal/diagnostic'
            && $request->hasHeader('X-IFC-Signature') && $request->hasHeader('X-IFC-Nonce')
            && $request->hasHeader('X-Correlation-ID') && $request->body() === '{}');
    }

    public function test_wrong_service_credentials_and_service_downtime_fail_safely(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'Invalid service credentials'], 401)]);
        $user = User::factory()->create();
        $user->assignRole('Operations');
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('integration', 'unavailable');
        Http::fake(fn () => throw new ConnectionException('offline'));
        $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->assertJsonPath('integration', 'unavailable');
    }

    public function test_integration_health_separates_public_health_from_signed_authentication_and_hides_credentials(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'service' => 'ifc-ai']),
            '*/internal/diagnostic' => Http::response(['status' => 'authenticated', 'correlation_id' => 'test-correlation']),
        ]);
        config(['services.ai.token' => 'private-test-credential']);
        $user = User::factory()->create();
        $user->assignRole('Administrator');

        $page = $this->actingAs($user)->get('/admin/integrations')->assertOk()->assertSee('data-integration-health', false);
        $this->assertStringNotContainsString('private-test-credential', $page->getContent());
        $this->assertStringNotContainsString('127.0.0.1:8001', $page->getContent());
        $this->getJson('/admin/integrations/status')->assertOk()->assertJsonPath('service.status', 'connected')->assertJsonPath('authentication.status', 'authenticated')->assertJsonMissing(['private-test-credential']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/internal/diagnostic')
            && $request->hasHeader('X-IFC-Signature') && $request->hasHeader('X-IFC-Nonce') && $request->body() === '{}');
    }

    public function test_integration_health_reports_auth_rejection_and_unavailable_service_safely(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok']),
            '*/internal/diagnostic' => Http::response(['detail' => 'Invalid service credentials'], 401),
        ]);
        $result = app(AiServiceClient::class)->healthSnapshot();
        $this->assertSame('connected', $result['service']['status']);
        $this->assertSame('rejected', $result['authentication']['status']);
        $this->assertStringNotContainsString('private-test-credential', json_encode($result));

        Http::fake(fn () => throw new ConnectionException('offline'));
        $result = app(AiServiceClient::class)->healthSnapshot();
        $this->assertSame('unavailable', $result['service']['status']);
        $this->assertSame('unavailable', $result['authentication']['status']);
    }

    public function test_integration_health_reports_missing_authentication_configuration_without_exposing_it(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
        config(['services.ai.token' => null]);

        $result = app(AiServiceClient::class)->healthSnapshot();
        $this->assertSame('connected', $result['service']['status']);
        $this->assertSame('not_configured', $result['authentication']['status']);
        $this->assertNull($result['last_successful_check']);
    }

    public function test_live_signed_diagnostic_when_configured(): void
    {
        $baseUrl = getenv('AI_E2E_URL');
        $token = getenv('AI_E2E_TOKEN') ?: config('services.ai.token');
        if (! is_string($baseUrl) || $baseUrl === '' || ! is_string($token) || $token === '') {
            $this->markTestSkipped('Set AI_E2E_URL and AI_E2E_TOKEN to run the live protected diagnostic check.');
        }
        config(['services.ai.url' => $baseUrl, 'services.ai.token' => $token]);
        $result = app(AiServiceClient::class)->diagnostic();
        $this->assertSame('authenticated', $result['status']);
        $this->assertNotEmpty($result['correlation_id']);
    }
}
