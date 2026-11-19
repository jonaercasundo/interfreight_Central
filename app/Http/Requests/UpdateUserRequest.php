<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('user');

        return $subject instanceof User && ($this->user()?->can('update', $subject) ?? false);
    }

    public function rules(): array
    {
        $subject = $this->route('user');

        return ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($subject instanceof User ? $subject->id : null)], 'roles' => ['array'], 'roles.*' => ['string', Rule::exists('roles', 'name')], 'is_active' => ['sometimes', 'boolean']];
    }
}
