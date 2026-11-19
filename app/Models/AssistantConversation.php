<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantConversation extends Model
{
    protected $fillable = ['user_id', 'conversation_uuid', 'title', 'module', 'current_path', 'messages', 'last_activity_at', 'expires_at'];

    protected function casts(): array
    {
        return ['messages' => 'array', 'last_activity_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
