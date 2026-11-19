<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'context_module' => ['nullable', Rule::in(['sales'])],
            'context_page' => ['nullable', 'string', 'max:120'],
            'inquiry_id' => ['nullable', 'integer', 'min:1'],
            'conversation_id' => ['nullable', 'uuid'],
        ];
    }
}
