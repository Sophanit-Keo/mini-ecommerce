<?php

namespace App\Http\Requests;

use App\Enums\SoldBy;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'categoryId' => ['required', 'uuid'],
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:220'],
            'brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'soldBy' => ['required', Rule::enum(SoldBy::class)],
            'unitLabel' => ['required', 'string', 'max:16'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'pricePerKg' => ['nullable', 'numeric', 'min:0'],
            'compareAtPrice' => ['nullable', 'numeric', 'min:0'],
            'averageWeightKg' => ['nullable', 'numeric', 'gt:0'],
            'weightTolerancePct' => ['nullable', 'numeric', 'between:0,100'],
            'minOrderQuantity' => ['required', 'numeric', 'gt:0'],
            'maxOrderQuantity' => ['nullable', 'numeric', 'gte:minOrderQuantity'],
            'isActive' => ['sometimes', 'boolean'],
            'initialStock' => ['sometimes', 'numeric', 'min:0'],
            'lowStockThreshold' => ['sometimes', 'numeric', 'min:0'],
            'restockExpectedAt' => ['nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $soldBy = $this->input('soldBy');
            $hasPrice = $this->input('price') !== null;
            $hasPricePerKg = $this->input('pricePerKg') !== null;
            $hasAverageWeight = $this->input('averageWeightKg') !== null;

            if ($soldBy === SoldBy::Unit->value && (! $hasPrice || $hasPricePerKg || $hasAverageWeight)) {
                $validator->errors()->add('soldBy', 'A unit-sold product requires price only, not pricePerKg or averageWeightKg.');
            }

            if ($soldBy === SoldBy::Weight->value && ($hasPrice || ! $hasPricePerKg || ! $hasAverageWeight)) {
                $validator->errors()->add('soldBy', 'A weight-sold product requires pricePerKg and averageWeightKg, not price.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function productAttributes(): array
    {
        $category = Category::wherePublicId($this->string('categoryId')->toString())->first();
        if ($category === null) {
            throw ValidationException::withMessages(['categoryId' => 'No such category.']);
        }

        return [
            'category_id' => $category->id,
            'sku' => $this->string('sku')->toString(),
            'name' => $this->string('name')->toString(),
            'slug' => $this->string('slug')->toString(),
            'brand' => $this->input('brand'),
            'description' => $this->input('description'),
            'sold_by' => SoldBy::from($this->string('soldBy')->toString()),
            'unit_label' => $this->string('unitLabel')->toString(),
            'price' => $this->input('price'),
            'price_per_kg' => $this->input('pricePerKg'),
            'compare_at_price' => $this->input('compareAtPrice'),
            'average_weight_kg' => $this->input('averageWeightKg'),
            'weight_tolerance_pct' => $this->input('weightTolerancePct') ?? '10.00',
            'min_order_quantity' => $this->input('minOrderQuantity'),
            'max_order_quantity' => $this->input('maxOrderQuantity'),
            'is_active' => $this->boolean('isActive', true),
        ];
    }
}
