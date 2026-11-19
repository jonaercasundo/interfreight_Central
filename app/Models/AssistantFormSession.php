<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantFormSession extends Model
{
    protected $fillable = ['user_id', 'session_uuid', 'conversation_uuid', 'form_key', 'field_values', 'field_order', 'current_step', 'status', 'draft_id'];

    protected function casts(): array
    {
        return ['field_values' => 'array', 'field_order' => 'array', 'current_step' => 'integer'];
    }
}
