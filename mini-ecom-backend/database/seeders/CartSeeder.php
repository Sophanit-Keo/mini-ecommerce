<?php

namespace Database\Seeders;

use App\Enums\CartStatus;
use App\Enums\SubstitutionPreference;
use App\Models\Cart;
use App\Models\CartItem;
use Database\Seeders\Concerns\SeedsPublicIds;
use Illuminate\Database\Seeder;

/**
 * Ben's active cart, mixing unit and weight lines and all three substitution preferences.
 */
class CartSeeder extends Seeder
{
    use SeedsPublicIds;

    public function run(): void
    {
        (new Cart)->forceFill([
            'id' => 1,
            'public_id' => $this->publicId(6, 1),
            'user_id' => 2,
            'status' => CartStatus::Active,
            'currency' => 'USD',
        ])->save();

        $items = [
            [13, '1.000', '4.29', SubstitutionPreference::Similar, null],
            [19, '2.000', '5.49', SubstitutionPreference::None, 'Well fired if you have it'],
            [2, '1.500', '3.28', SubstitutionPreference::Similar, null],
            [25, '0.500', '12.10', SubstitutionPreference::ContactMe, null],
        ];

        foreach ($items as [$productId, $quantity, $price, $preference, $note]) {
            CartItem::create([
                'cart_id' => 1,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price_snapshot' => $price,
                'substitution_preference' => $preference,
                'note' => $note,
            ]);
        }
    }
}
