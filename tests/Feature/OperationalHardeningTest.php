<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OperationalHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_content_security_policy_is_enforced_without_wildcard_sources(): void
    {
        $response = $this->get('/login')->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringNotContainsString('*', $policy);
    }

    public function test_private_dashboard_api_uses_the_same_origin_web_session_and_csrf_middleware(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/dashboard', 'GET'));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('active', $middleware);
    }

    public function test_queue_health_is_permission_checked_and_exposes_only_aggregate_counts(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');
        $this->actingAs($sales)->getJson('/api/admin/queue-health')->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $response = $this->actingAs($admin)->getJson('/api/admin/queue-health');

        $this->assertContains($response->status(), [200, 503]);
        $response->assertJsonStructure(['status', 'connection', 'queue', 'backlog', 'failed_jobs', 'checked_at']);
        $this->assertStringNotContainsString('payload', $response->getContent());
    }

    public function test_quarantine_files_have_no_unsigned_public_storage_url(): void
    {
        $response = $this->get('/storage/quarantine/phase17-synthetic.txt');

        $this->assertNotSame(200, $response->status());
    }
}
