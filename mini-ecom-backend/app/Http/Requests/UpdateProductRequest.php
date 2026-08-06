<?php

namespace App\Http\Requests;

use App\Enums\SoldBy;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Admin-only product edits. Every field is `sometimes` — this is a PATCH, and the spec sends
 * only what changed — but `sold_by` and its dependent columns are cross-checked in {@see
 * after()} against the row as it will read *after* the patch is applied, because
 * `ck_products_pricing_shape` evaluates the whole row, not just the columns in the request.
 */
class UpdateProductRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categoryId' => ['sometimes', 'uuid'],
            'sku' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:200'],
            'slug' => ['sometimes', 'string', 'max:220'],
            'brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'soldBy' => ['sometimes', Rule::enum(SoldBy::class)],
            'unitLabel' => ['sometimes', 'string', 'max:16'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'pricePerKg' => ['nullable', 'numeric', 'min:0'],
            'compareAtPrice' => ['nullable', 'numeric', 'min:0'],
            'averageWeightKg' => ['nullable', 'numeric', 'gt:0'],
            'weightTolerancePct' => ['sometimes', 'numeric', 'between:0,100'],
            'minOrderQuantity' => ['sometimes', 'numeric', 'gt:0'],
            'maxOrderQuantity' => ['nullable', 'numeric', 'gte:minOrderQuantity'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * `ck_products_pricing_shape` requires a unit product to carry `price` and neither weight
     * column, and a weight product to carry `price_per_kg` + `average_weight_kg` and no
     * `price` — never a mix, never neither. Checked here against the *effective* `sold_by`
     * (the patched value if one was sent, else the existing row's) so a partial update that
     * would leave the row in the wrong shape is rejected before it reaches the database.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $product = $this->routeProduct();

                if ($product === null) {
                    // No such product — the controller answers 404. Nothing left to check.
                    return;
                }

                $soldBy = $this->has('soldBy')
                    ? SoldBy::from((string) $this->input('soldBy'))
                    : $product->sold_by;

                $hasPrice = $this->has('price') ? $this->input('price') !== null : $product->price !== null;
                $hasPricePerKg = $this->has('pricePerKg') ? $this->input('pricePerKg') !== null : $product->price_per_kg !== null;
                $hasAverageWeight = $this->has('averageWeightKg') ? $this->input('averageWeightKg') !== null : $product->average_weight_kg !== null;

                if ($soldBy === SoldBy::Unit) {
                    if (! $hasPrice) {
                        $validator->errors()->add('price', 'A unit-sold product requires a price.');
                    }

                    if ($hasPricePerKg || $hasAverageWeight) {
                        $validator->errors()->add('soldBy', 'A unit-sold product cannot have pricePerKg or averageWeightKg set.');
                    }
                } else {
                    if (! $hasPricePerKg || ! $hasAverageWeight) {
                        $validator->errors()->add('soldBy', 'A weight-sold product requires pricePerKg and averageWeightKg.');
                    }

                    if ($hasPrice) {
                        $validator->errors()->add('price', 'A weight-sold product cannot have a price set.');
                    }
                }
            },
        ];
    }

    /**
     * Only ever writes to columns in `Product::$fillable`. `effective_price`, `sku_active`
     * and `slug_active` are DB-generated and never appear on either side of this map.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $map = [
            'sku' => 'sku',
            'name' => 'name',
            'slug' => 'slug',
            'brand' => 'brand',
            'description' => 'description',
            'soldBy' => 'sold_by',
            'unitLabel' => 'unit_label',
            'price' => 'price',
            'pricePerKg' => 'price_per_kg',
            'compareAtPrice' => 'compare_at_price',
            'averageWeightKg' => 'average_weight_kg',
            'weightTolerancePct' => 'weight_tolerance_pct',
            'minOrderQuantity' => 'min_order_quantity',
            'maxOrderQuantity' => 'max_order_quantity',
            'isActive' => 'is_active',
        ];

        $attributes = [];

        foreach ($map as $input => $column) {
            if ($this->has($input)) {
                $attributes[$column] = $this->input($input);
            }
        }

        if (isset($attributes['sold_by'])) {
            $attributes['sold_by'] = SoldBy::from((string) $attributes['sold_by']);
        }

        if ($this->has('categoryId')) {
            $attributes['category_id'] = $this->resolvedCategoryId();
        }

        return $attributes;
    }

    /**
     * Resolved eagerly (rather than left as a raw UUID for the controller to look up) so a
     * bad category id fails validation with a field-level 422 instead of surfacing as a
     * foreign key violation.
     */
    private function resolvedCategoryId(): int
    {
        $category = Category::wherePublicId((string) $this->input('categoryId'))->first();

        if ($category === null) {
            throw ValidationException::withMessages([
                'categoryId' => 'No such category.',
            ]);
        }

        return $category->id;
    }

    /**
     * The `{productId}` route segment resolved to a model, or null if it does not exist.
     * Looked up (rather than reused from the controller) because the FormRequest validates
     * before the controller method runs.
     */
    private function routeProduct(): ?Product
    {
        $productId = $this->route('productId');

        if (! is_string($productId)) {
            return null;
        }

        return Product::query()->wherePublicId($productId)->first();
    }
}
