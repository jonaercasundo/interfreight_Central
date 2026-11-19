<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastRevision extends Model
{
    protected $fillable = ['forecast_id', 'actor_id', 'revision', 'action', 'before_values', 'after_values'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array'];
    }

    public function forecast(): BelongsTo
    {
        return $this->belongsTo(ShipmentForecast::class, 'forecast_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
