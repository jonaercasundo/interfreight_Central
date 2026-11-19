<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'recipient_acknowledged' => 'boolean'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
