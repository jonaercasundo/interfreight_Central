<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCapacityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['availability_id', 'actor_id', 'event_type', 'before_values', 'after_values', 'created_at'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'created_at' => 'datetime'];
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(SalesCapacityAvailability::class, 'availability_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
