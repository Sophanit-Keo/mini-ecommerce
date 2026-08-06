<?php

namespace App\Http\Requests;

use App\Enums\SubstitutionPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCartItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'substitutionPreference' => ['sometimes', Rule::enum(SubstitutionPreference::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:280'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = [];

        if ($this->has('quantity')) {
            $attributes['quantity'] = (string) $this->input('quantity');
        }

        if ($this->has('substitutionPreference')) {
            $attributes['substitution_preference'] = SubstitutionPreference::from((string) $this->input('substitutionPreference'));
        }

        if ($this->has('note')) {
            $attributes['note'] = $this->input('note');
        }

        return $attributes;
    }
}
