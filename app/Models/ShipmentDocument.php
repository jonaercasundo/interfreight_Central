<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'verified_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ShipmentDelivery::class, 'delivery_id');
    }
}
