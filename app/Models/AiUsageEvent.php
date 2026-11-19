<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'provider', 'model', 'status', 'error_category', 'input_tokens', 'output_tokens', 'latency_ms', 'created_at', 'request_id', 'correlation_id', 'request_type', 'module', 'department', 'cached_input_tokens', 'total_tokens', 'estimated_cost_usd', 'estimated_cost_php', 'usd_php_rate', 'cost_status'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cached_input_tokens' => 'integer', 'total_tokens' => 'integer', 'latency_ms' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
