<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\WishlistItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved product, pointing live at the catalogue — not a snapshot. Unlike order or cart
 * lines, a wishlist entry carries no price or quantity: it disappears with the product it
 * points to (`fk_wishlist_items_product` cascades) rather than surviving as a dangling
 * reference.
 */
#[Fillable(['user_id', 'product_id'])]
class WishlistItem extends Model
{
    /** @use HasFactory<WishlistItemFactory> */
    use HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
