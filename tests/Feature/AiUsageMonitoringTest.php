<?php

namespace Tests\Feature;

use App\Models\AiExchangeRate;
use App\Models\AiModelPrice;
use App\Models\AiUsageEvent;
use App\Models\User;
use App\Services\AiCredentialService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiUsageMonitoringTest extends TestCase
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

    public function test_usage_page_and_api_are_administrator_only(): void
    {
        $sales = $this->user('Sales');
        $this->actingAs($sales)->get('/admin/ai-usage')->assertForbidden();
        $this->actingAs($sales)->getJson('/api/admin/ai-usage/summary')->assertForbidden();
        $this->actingAs($sales)->postJson('/api/admin/ai-usage/prices', [])->assertForbidden();
        $admin = $this->user('Administrator');
        $this->actingAs($admin)->get('/admin/ai-usage')->assertOk()->assertSee('data-page="ai-usage"', false);
        $this->actingAs($admin)->getJson('/api/admin/ai-usage/summary')->assertOk()->assertJsonPath('cost_coverage', 'no_usage');
    }

    public function test_usage_records_capture_request_type_and_calculate_only_with_verified_price_and_fx(): void
    {
        $admin = $this->user('Administrator');
        $actor = $this->user('Sales');
        AiModelPrice::query()->create(['provider' => 'openai', 'model' => 'synthetic-model', 'input_usd_per_million' => '1.0000000000', 'cached_input_usd_per_million' => '0.5000000000', 'output_usd_per_million' => '2.0000000000', 'source_url' => 'https://example.test/pricing-fixture', 'verified_at' => now(), 'effective_at' => now()->subMinute(), 'created_by' => $admin->id]);
        AiExchangeRate::query()->create(['base_currency' => 'USD', 'target_currency' => 'PHP', 'rate' => '56.0000000000', 'source' => 'Synthetic test fixture', 'source_url' => 'https://example.test/fx-fixture', 'override_reason' => 'Synthetic test fixture, not a live exchange rate.', 'verified_at' => now(), 'effective_at' => now()->subMinute(), 'entered_by' => $admin->id]);

        app(AiCredentialService::class)->recordUsage($actor, 'synthetic-model', 'success', null, 1000, 2000, 75, ['request_type' => 'assistant', 'module' => 'sales_workspace', 'correlation_id' => 'synthetic-correlation', 'cached_input_tokens' => 200]);
        $event = AiUsageEvent::query()->latest('id')->firstOrFail();
        $this->assertSame('Sales', $event->department);
        $this->assertSame('synthetic-correlation', $event->correlation_id);
        $this->assertSame('sales_workspace', $event->module);
        $this->assertSame('estimated', $event->cost_status);
        $this->assertEqualsWithDelta(0.0049, (float) $event->estimated_cost_usd, 0.00000001);
        $this->assertEqualsWithDelta(0.2744, (float) $event->estimated_cost_php, 0.00000001);
        $this->actingAs($admin)->getJson('/api/admin/ai-usage?department=Sales&module=sales_workspace')->assertOk()->assertJsonPath('data.data.0.request_type', 'assistant')->assertJsonPath('data.data.0.module', 'sales_workspace')->assertJsonPath('data.data.0.cost_status', 'estimated');
        $this->actingAs($admin)->get('/admin/ai-usage/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_missing_verified_price_or_fx_is_reported_as_unavailable_not_zero(): void
    {
        $admin = $this->user('Administrator');
        $actor = $this->user('Operations');
        app(AiCredentialService::class)->recordUsage($actor, 'unknown-model', 'success', null, 100, 50, 30);
        $event = AiUsageEvent::query()->latest('id')->firstOrFail();
        $this->assertNull($event->estimated_cost_php);
        $this->assertSame('pricing_unavailable', $event->cost_status);
        $this->actingAs($admin)->getJson('/api/admin/ai-usage/summary')->assertOk()->assertJsonPath('estimated_cost_php', null)->assertJsonPath('cost_coverage', 'unavailable');
    }

    public function test_admin_can_record_versioned_budget_price_and_exchange_rate_with_audit(): void
    {
        $admin = $this->user('Administrator');
        $price = $this->actingAs($admin)->postJson('/api/admin/ai-usage/prices', ['model' => 'synthetic-model', 'input_usd_per_million' => '0.25', 'output_usd_per_million' => '1.00', 'source_url' => 'https://example.test/pricing', 'verified_at' => now()->toIso8601String(), 'effective_at' => now()->toIso8601String()])->assertCreated();
        $this->assertDatabaseHas('ai_model_prices', ['model' => 'synthetic-model']);
        $this->actingAs($admin)->postJson('/api/admin/ai-usage/exchange-rates', ['rate' => '56.10', 'source' => 'Synthetic source', 'source_url' => 'https://example.test/rate', 'override_reason' => 'Synthetic audit test value only.', 'verified_at' => now()->toIso8601String(), 'effective_at' => now()->toIso8601String()])->assertCreated();
        $this->actingAs($admin)->putJson('/api/admin/ai-usage/budget', ['year' => now()->year, 'month' => now()->month, 'budget_php' => '1000', 'warning_percent' => 50, 'critical_percent' => 80])->assertOk();
        $this->assertDatabaseHas('ai_monthly_budgets', ['year' => now()->year, 'month' => now()->month, 'budget_php' => '1000.00']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.usage.pricing_created']);
    }

    public function test_fx_manager_can_fetch_latest_daily_usd_php_reference_rate_without_saving_it(): void
    {
        Cache::flush();
        Http::fake(['https://api.frankfurter.dev/v2/rate/USD/PHP' => Http::response([
            'date' => '2026-10-23', 'base' => 'USD', 'quote' => 'PHP', 'rate' => 58.1234,
        ])]);

        $admin = $this->user('Administrator');
        $this->actingAs($admin)->getJson('/api/admin/ai-usage/exchange-rates/latest')
            ->assertOk()
            ->assertJsonPath('kind', 'daily_reference')
            ->assertJsonPath('rate.rate', '58.1234000000')
            ->assertJsonPath('rate.date', '2026-10-23')
            ->assertJsonPath('rate.base', 'USD')
            ->assertJsonPath('rate.quote', 'PHP');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_exchange_rates', 0);
    }

    public function test_fx_fetch_is_forbidden_without_fx_management_permission_and_handles_provider_failure(): void
    {
        Cache::flush();
        $sales = $this->user('Sales');
        $this->actingAs($sales)->getJson('/api/admin/ai-usage/exchange-rates/latest')->assertForbidden();

        $admin = $this->user('Administrator');
        Http::fake(['https://api.frankfurter.dev/v2/rate/USD/PHP' => Http::response(['message' => 'unavailable'], 503)]);
        $this->actingAs($admin)->getJson('/api/admin/ai-usage/exchange-rates/latest')
            ->assertStatus(503)
            ->assertJsonPath('message', 'The exchange-rate provider returned an unavailable response.');
    }
}
