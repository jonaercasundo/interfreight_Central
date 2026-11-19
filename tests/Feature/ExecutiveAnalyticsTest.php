<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExecutiveAnalyticsTest extends TestCase
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

    public function test_executive_dashboard_is_restricted_and_returns_distinct_master_job_metrics(): void
    {
        $this->actingAs($this->user('Executive'))->get('/executive')->assertOk();
        $this->actingAs($this->user('Administrator'))->get('/executive')->assertForbidden();
        $this->actingAs($this->user('Finance'))->getJson('/api/executive/dashboard')->assertForbidden();
        $mixed = $this->user('Executive');
        $mixed->assignRole('Administrator');
        $this->actingAs($mixed)->getJson('/api/executive/dashboard')->assertForbidden();

        $month = now()->format('Y-m');
        foreach ([['EXEC-A', 'open'], ['EXEC-B', 'cancelled']] as [$reference, $status]) {
            DB::table('shipments')->insert([
                'reference' => $reference, 'job_number' => $reference, 'customer_name' => 'Analytics Test',
                'origin' => 'Manila', 'destination' => 'Cebu', 'mode' => 'Sea', 'status' => 'Booked',
                'master_status' => $status, 'cargo' => 'Test freight', 'weight_kg' => 10,
                'currency' => 'PHP', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->user('Executive'))->getJson('/api/executive/dashboard?month='.$month)->assertOk();
        $this->assertSame(0, $response->json('metrics.forecasted_shipments'));
        $this->assertSame(1, $response->json('metrics.actual_master_shipments'));
        $this->assertNull($response->json('metrics.forecast_accuracy_previous_month_percent'));
        $this->assertSame('not_available', $response->json('metrics.recognized_revenue.status'));
        $this->assertSame(12, count($response->json('series')));
    }

    public function test_reporting_month_is_validated(): void
    {
        $this->actingAs($this->user('Executive'))->getJson('/api/executive/dashboard?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_forecast_generation_uses_authenticated_service_and_persists_scoped_aggregates(): void
    {
        config(['services.ai.url' => 'http://127.0.0.1:8001', 'services.ai.token' => 'forecast-test-secret']);
        Http::fake(['*/api/v1/ai/forecast/shipments' => Http::response([
            'status' => 'insufficient_data', 'target' => 'monthly_master_shipment_demand', 'model_version' => 'moving-average-3.0.1',
            'training_cutoff' => now()->subMonth()->startOfMonth()->toDateString(), 'input_start' => now()->subMonths(36)->startOfMonth()->toDateString(),
            'input_end' => now()->subMonth()->startOfMonth()->toDateString(), 'horizon_months' => 3, 'forecasts' => [],
            'evaluation' => null, 'limitation' => 'Insufficient historical data for reliable prediction.',
        ])]);
        $executive = $this->user('Executive');

        $result = $this->actingAs($executive)->postJson('/api/executive/forecast', ['horizon_months' => 3])->assertOk()
            ->assertJsonPath('status', 'insufficient_data');
        $this->assertDatabaseHas('ai_prediction_runs', ['request_id' => $result->json('run_id'), 'service_correlation_id' => $result->json('correlation_id'), 'generated_by' => $executive->id, 'status' => 'insufficient_data']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $executive->id, 'action' => 'ai.forecast.shipments.generated']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/ai/forecast/shipments')
            && $request->hasHeader('X-IFC-Signature') && $request->hasHeader('X-IFC-Nonce')
            && $request->hasHeader('X-Correlation-ID') && count($request->data()['history']) === 36);

        $this->actingAs($this->user('Administrator'))->postJson('/api/executive/forecast', ['horizon_months' => 3])->assertForbidden();
        $this->assertDatabaseCount('ai_prediction_runs', 1);
    }
}
