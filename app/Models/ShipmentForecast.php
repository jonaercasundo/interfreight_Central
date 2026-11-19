<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentForecast extends Model
{
    protected $fillable = ['inquiry_id', 'sales_owner_id', 'forecast_month', 'expected_shipment_count', 'confidence', 'status', 'revision'];

    protected function casts(): array
    {
        return ['forecast_month' => 'date'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(ClientInquiry::class, 'inquiry_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_owner_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ForecastRevision::class, 'forecast_id');
    }
}
