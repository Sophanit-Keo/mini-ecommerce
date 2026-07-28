<?php

namespace App\Http\Requests;

use App\Enums\SoldBy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    /**
     * `?inStock=true` arrives as the string "true", which Laravel's `boolean` rule rejects —
     * it accepts only 1/0/"1"/"0"/true/false. Normalise before validating so the obvious
     * query string works.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('inStock')) {
            $this->merge(['inStock' => $this->boolean('inStock')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'min:1', 'max:100'],
            'categoryId' => ['nullable', 'uuid'],
            'inStock' => ['nullable', 'boolean'],
            'soldBy' => ['nullable', Rule::enum(SoldBy::class)],
            'sort' => ['nullable', Rule::in(['relevance', 'price_asc', 'price_desc', 'newest'])],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function searchTerm(): ?string
    {
        $term = trim((string) $this->query('q'));

        return $term === '' ? null : $term;
    }

    public function sort(): string
    {
        return (string) ($this->query('sort') ?? 'relevance');
    }

    public function limit(): int
    {
        return (int) ($this->query('limit') ?? 24);
    }
}
