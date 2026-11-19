<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientInquiry extends Model
{
    protected $fillable = ['client_id', 'sales_owner_id', 'service_type', 'transport_mode', 'origin', 'destination', 'origin_country_code', 'origin_country_name', 'origin_location_id', 'origin_location_type', 'origin_location_code_system', 'origin_location_name', 'origin_custom_details', 'destination_country_code', 'destination_country_name', 'destination_location_id', 'destination_location_type', 'destination_location_code_system', 'destination_location_name', 'destination_custom_details', 'cargo_description', 'estimated_weight_kg', 'estimated_volume_cbm', 'estimated_container_count', 'container_type', 'commodity_category', 'special_requirements', 'requested_ship_date', 'status', 'submitted_at', 'submitted_by', 'revision'];

    protected function casts(): array
    {
        return ['estimated_weight_kg' => 'decimal:2', 'requested_ship_date' => 'date', 'submitted_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(ShipmentForecast::class, 'inquiry_id');
    }

    public function costEstimates(): HasMany
    {
        return $this->hasMany(CostEstimate::class, 'inquiry_id');
    }
}
