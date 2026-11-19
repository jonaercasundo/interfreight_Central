<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConfiguration extends Model
{
    protected $fillable = [
        'provider', 'model', 'enabled', 'tutorials_enabled', 'api_key_ciphertext',
        'timeout_seconds', 'max_output_tokens', 'last_test_status', 'last_tested_at',
        'last_successful_test_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'tutorials_enabled' => 'boolean',
            'timeout_seconds' => 'integer',
            'max_output_tokens' => 'integer',
            'api_key_ciphertext' => 'encrypted',
            'last_tested_at' => 'datetime',
            'last_successful_test_at' => 'datetime',
        ];
    }
}
