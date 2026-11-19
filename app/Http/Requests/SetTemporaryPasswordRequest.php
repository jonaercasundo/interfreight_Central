<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetTemporaryPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.password.reset') ?? false;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ];
    }
}
