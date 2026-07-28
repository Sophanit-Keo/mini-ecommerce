<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            // Uniqueness is not checked here. uq_users_email_active decides, so the answer
            // cannot change between the check and the insert.
            'password' => ['required', 'string', 'max:128', Password::defaults()],
            'fullName' => ['required', 'string', 'min:1', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
