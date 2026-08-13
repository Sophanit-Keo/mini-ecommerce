<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:120'],
        ];
    }
}
