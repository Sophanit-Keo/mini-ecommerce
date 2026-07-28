<?php

namespace App\Enums;

/**
 * How a product is priced, and therefore what `quantity` means for it.
 *
 * `Unit` products carry a flat `price` and a quantity that is a count. `Weight` products
 * carry a `price_per_kg` plus an `average_weight_kg` used only to estimate, and a quantity
 * measured in kilograms. `ck_products_pricing_shape` makes the other shape's columns
 * impossible to populate.
 */
enum SoldBy: string
{
    case Unit = 'unit';
    case Weight = 'weight';

    public function isWeighed(): bool
    {
        return $this === self::Weight;
    }
}
