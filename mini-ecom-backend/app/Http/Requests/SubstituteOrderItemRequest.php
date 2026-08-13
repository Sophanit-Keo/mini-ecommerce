<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubstituteOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'productId' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'weightKg' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'reason' => ['nullable', 'string', 'max:280'],
            'customerApproved' => ['required', 'boolean'],
        ];
    }
}
