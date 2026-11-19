<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelPrice extends Model
{
    protected $fillable = ['provider', 'model', 'input_usd_per_million', 'cached_input_usd_per_million', 'output_usd_per_million', 'source_url', 'verified_at', 'effective_at', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['input_usd_per_million' => 'decimal:10', 'cached_input_usd_per_million' => 'decimal:10', 'output_usd_per_million' => 'decimal:10', 'verified_at' => 'datetime', 'effective_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
