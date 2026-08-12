<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkOrderItemUnavailableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:280'],
        ];
    }
}
