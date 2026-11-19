<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryLocationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    private function salesUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        return $user;
    }

    private function clientFor(User $sales): array
    {
        return $this->actingAs($sales)->postJson('/api/sales/clients', [
            'company_name' => 'Location Test Imports',
            'client_type' => 'FCL',
        ])->assertCreated()->json('data');
    }

    public function test_location_directory_filters_by_mode_and_country(): void
    {
        $sales = $this->salesUser();
        $this->actingAs($sales)->getJson('/api/sales/location-options?mode=Sea&country=PH')
            ->assertOk()
            ->assertJsonFragment(['code' => 'PH', 'name' => 'Philippines'])
            ->assertJsonFragment(['id' => 'unlocode:PHMNL', 'code' => 'PHMNL', 'type' => 'seaport'])
            ->assertJsonMissing(['code' => 'MNL', 'type' => 'airport']);

        $this->getJson('/api/sales/location-options?mode=Air&country=PH&search=manila')
            ->assertOk()->assertJsonFragment(['id' => 'iata:MNL', 'code' => 'MNL', 'type' => 'airport'])
            ->assertJsonMissing(['id' => 'unlocode:PHMNL', 'type' => 'seaport']);

        $this->getJson('/api/sales/location-options?mode=Road&country=CN&search=shanghai')
            ->assertOk()->assertJsonFragment(['id' => 'unlocode:CNSHA', 'type' => 'city']);
    }

    public function test_structured_inquiry_stores_standard_codes_and_keeps_legacy_route_strings(): void
    {
        $sales = $this->salesUser();
        $client = $this->clientFor($sales);

        $inquiry = $this->actingAs($sales)->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'],
            'service_type' => 'FCL',
            'transport_mode' => 'Sea',
            'origin_country_code' => 'PH',
            'origin_location_id' => 'unlocode:PHMNL',
            'origin_location_type' => 'seaport',
            'origin_location_code_system' => 'unlocode',
            'destination_country_code' => 'SG',
            'destination_location_id' => 'unlocode:SGSIN',
            'destination_location_type' => 'seaport',
            'destination_location_code_system' => 'unlocode',
            'cargo_description' => 'Synthetic route test freight',
        ])->assertCreated()
            ->assertJsonPath('data.origin', 'Manila, Philippines')
            ->assertJsonPath('data.destination', 'Singapore, Singapore')
            ->assertJsonPath('data.origin_country_code', 'PH')
            ->assertJsonPath('data.origin_location_id', 'unlocode:PHMNL')
            ->assertJsonPath('data.destination_location_type', 'seaport')
            ->json('data');

        $this->assertDatabaseHas('client_inquiries', ['id' => $inquiry['id'], 'origin' => 'Manila, Philippines', 'origin_location_id' => 'unlocode:PHMNL']);
    }

    public function test_selected_location_must_match_country_and_transport_mode(): void
    {
        $sales = $this->salesUser();
        $client = $this->clientFor($sales);

        $this->actingAs($sales)->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'], 'service_type' => 'FCL', 'transport_mode' => 'Sea',
            'origin_country_code' => 'PH', 'origin_location_id' => 'iata:MNL',
            'destination_country_code' => 'SG', 'destination_location_id' => 'unlocode:SGSIN',
            'cargo_description' => 'Synthetic invalid mode test',
        ])->assertUnprocessable()->assertJsonValidationErrors(['origin_location_id']);

        $this->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'], 'service_type' => 'FCL', 'transport_mode' => 'Air',
            'origin_country_code' => 'CN', 'origin_location_id' => 'unlocode:PHMNL',
            'destination_country_code' => 'SG', 'destination_location_id' => 'iata:SIN',
            'cargo_description' => 'Synthetic invalid country test',
        ])->assertUnprocessable()->assertJsonValidationErrors(['origin_location_id']);
    }

    public function test_custom_locations_require_a_country_and_keep_user_supplied_details(): void
    {
        $sales = $this->salesUser();
        $client = $this->clientFor($sales);

        $this->actingAs($sales)->postJson('/api/sales/inquiries', [
            'client_id' => $client['id'], 'service_type' => 'LCL', 'transport_mode' => 'Road',
            'origin_country_code' => 'PH', 'origin_custom_details' => 'Barangay delivery point, Cebu',
            'destination_country_code' => 'US', 'destination_country_name' => 'United States',
            'destination_custom_details' => 'Customer warehouse gate 4',
            'cargo_description' => 'Synthetic custom location test',
        ])->assertCreated()
            ->assertJsonPath('data.origin_location_type', 'custom')
            ->assertJsonPath('data.origin_custom_details', 'Barangay delivery point, Cebu')
            ->assertJsonPath('data.destination_country_code', 'US')
            ->assertJsonPath('data.destination_location_id', null)
            ->assertJsonPath('data.destination_custom_details', 'Customer warehouse gate 4');
    }

    public function test_location_directory_respects_inquiry_permissions(): void
    {
        $this->getJson('/api/sales/location-options?mode=Sea')->assertUnauthorized();
        $operations = User::factory()->create();
        $operations->assignRole('Operations');
        $this->actingAs($operations)->getJson('/api/sales/location-options?mode=Sea')->assertForbidden();
        $this->actingAs($this->salesUser())->getJson('/api/sales/location-options?mode=Multimodal')->assertUnprocessable();
    }
}
