<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentMilestone extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['planned_at' => 'datetime', 'actual_at' => 'datetime'];
    }
}
