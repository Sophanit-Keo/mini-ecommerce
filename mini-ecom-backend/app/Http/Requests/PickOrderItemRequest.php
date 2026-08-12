<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PickOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'weightKg' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'note' => ['nullable', 'string', 'max:280'],
        ];
    }
}
