<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostEstimateLine extends Model
{
    protected $guarded = ['id'];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(CostEstimate::class, 'cost_estimate_id');
    }
}
