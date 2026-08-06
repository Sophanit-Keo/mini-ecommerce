<?php

namespace App\Http\Requests;

use App\Enums\SubstitutionPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddCartItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'productId' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'substitutionPreference' => ['sometimes', Rule::enum(SubstitutionPreference::class)],
            'note' => ['nullable', 'string', 'max:280'],
        ];
    }

    public function quantity(): string
    {
        return (string) $this->input('quantity');
    }

    public function substitutionPreference(): ?SubstitutionPreference
    {
        return $this->has('substitutionPreference')
            ? SubstitutionPreference::from((string) $this->input('substitutionPreference'))
            : null;
    }
}
