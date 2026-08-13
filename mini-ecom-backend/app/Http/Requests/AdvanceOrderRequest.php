<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvanceOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['confirm', 'prepare', 'dispatch', 'deliver', 'complete', 'reject', 'cancel'])],
            'reason' => ['nullable', 'string', 'max:280'],
        ];
    }
}
