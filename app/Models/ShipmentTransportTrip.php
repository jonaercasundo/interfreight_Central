<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentTransportTrip extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scheduled_pickup' => 'datetime', 'actual_pickup' => 'datetime', 'scheduled_delivery' => 'datetime', 'actual_delivery' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
