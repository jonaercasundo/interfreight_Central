<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiExchangeRate extends Model
{
    protected $fillable = ['base_currency', 'target_currency', 'rate', 'source', 'source_url', 'override_reason', 'verified_at', 'effective_at', 'expires_at', 'entered_by'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:10', 'verified_at' => 'datetime', 'effective_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
