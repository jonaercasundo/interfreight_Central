<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentCustomsProcess extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['required_permits' => 'array', 'submitted_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
