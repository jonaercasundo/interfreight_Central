<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentContainer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gate_in_at' => 'datetime', 'loaded_at' => 'datetime', 'departed_at' => 'datetime', 'arrived_at' => 'datetime', 'discharged_at' => 'datetime', 'gate_out_at' => 'datetime', 'empty_returned_at' => 'datetime'];
    }
}
