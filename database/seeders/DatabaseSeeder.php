<?php

namespace Database\Seeders;

use App\Models\Shipment;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);
        foreach ([['Northstar Retail', 'Shanghai, CN', 'Los Angeles, US', 'Ocean', 'In transit', 'Electronics'], ['Morrow & Co.', 'Rotterdam, NL', 'Singapore, SG', 'Air', 'Customs', 'Medical supplies'], ['Atlas Foods', 'Bangkok, TH', 'Kuala Lumpur, MY', 'Road', 'Booked', 'Refrigerated produce'], ['Verde Living', 'Ho Chi Minh City, VN', 'Sydney, AU', 'Ocean', 'Delayed', 'Home furnishings'], ['Apex Components', 'Tokyo, JP', 'Vancouver, CA', 'Air', 'Delivered', 'Auto parts']] as $i => $row) {
            Shipment::firstOrCreate(['reference' => 'IFC-'.str_pad((string) (2401 + $i), 6, '0', STR_PAD_LEFT)], ['customer_name' => $row[0], 'origin' => $row[1], 'destination' => $row[2], 'mode' => $row[3], 'status' => $row[4], 'cargo' => $row[5], 'weight_kg' => 420 + $i * 175, 'estimated_delivery' => now()->addDays($i + 2), 'freight_cost' => 1850 + $i * 620]);
        }
    }
}
