<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantDocument extends Model
{
    protected $fillable = ['user_id', 'session_key', 'original_name', 'path', 'mime_type', 'file_size', 'scan_status', 'processing_status', 'error_message'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }
}
