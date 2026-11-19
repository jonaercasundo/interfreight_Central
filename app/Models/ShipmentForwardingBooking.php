<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentForwardingBooking extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['etd' => 'datetime', 'eta' => 'datetime', 'actual_departure' => 'datetime', 'actual_arrival' => 'datetime'];
    }
}
