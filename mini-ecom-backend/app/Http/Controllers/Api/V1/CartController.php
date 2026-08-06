<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CartStatus;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartItemResource;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Every lookup is scoped through the authenticated user's own cart — never fetch-then-check
 * — following the same IDOR discipline as AddressController.
 */
class CartController extends Controller
{
    /**
     * Always 200, even when this call is what lazily creates the cart — GET is idempotent and
     * must not surprise a client with a 201 depending on whether it happened to be first.
     */
    public function show(Request $request): JsonResponse
    {
        return CartResource::make($this->activeCart($request))->response()->setStatusCode(Response::HTTP_OK);
    }

    public function storeItem(AddCartItemRequest $request): JsonResponse
    {
        $product = Product::where('is_active', true)->with('inventory')->wherePublicId($request->string('productId')->toString())->first();

        if ($product === null) {
            throw ProblemException::notFound('No such product.');
        }

        $quantity = $request->quantity();

        $cartItem = DB::transaction(function () use ($request, $product, $quantity) {
            $cart = $this->activeCart($request);

            $existing = $cart->items()->where('product_id', $product->id)->lockForUpdate()->first();
            $newQuantity = $existing !== null
                ? Money::add($existing->quantity, $quantity, Money::QUANTITY_SCALE)
                : $quantity;

            $this->assertQuantityAllowed($product, $newQuantity);

            if ($existing !== null) {
                $existing->update([
                    'quantity' => $newQuantity,
                    'unit_price_snapshot' => $product->chargeableUnitPrice(),
                    'substitution_preference' => $request->substitutionPreference() ?? $existing->substitution_preference,
                    'note' => $request->has('note') ? $request->input('note') : $existing->note,
                ]);

                return $existing;
            }

            return $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $newQuantity,
                'unit_price_snapshot' => $product->chargeableUnitPrice(),
                'substitution_preference' => $request->substitutionPreference() ?? 'similar',
                'note' => $request->input('note'),
            ]);
        });

        return CartItemResource::make($cartItem->load('product'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function updateItem(UpdateCartItemRequest $request, string $cartItemId): CartItemResource
    {
        $cartItem = $this->findItemForUser($request, $cartItemId);

        DB::transaction(function () use ($request, $cartItem) {
            $attributes = $request->toAttributes();

            if (isset($attributes['quantity'])) {
                $this->assertQuantityAllowed($cartItem->product->loadMissing('inventory'), $attributes['quantity']);
            }

            $cartItem->update($attributes);
        });

        return CartItemResource::make($cartItem->refresh()->load('product'));
    }

    public function destroyItem(Request $request, string $cartItemId): Response
    {
        $this->findItemForUser($request, $cartItemId)->delete();

        return response()->noContent();
    }

    /**
     * Gets the caller's active cart, lazily creating it. `uq_carts_active_user` guarantees at
     * most one active cart per user in the database — two concurrent "get or create" requests
     * from two tabs race on the insert, and the loser here simply re-reads the winner's row
     * rather than erroring.
     */
    private function activeCart(Request $request): Cart
    {
        $user = $request->user();

        $cart = $user->carts()->where('status', CartStatus::Active)->with('items.product')->first();

        if ($cart !== null) {
            return $cart;
        }

        try {
            $cart = $user->carts()->create(['status' => CartStatus::Active, 'currency' => 'USD']);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'uq_carts_active_user')) {
                throw $e;
            }

            $cart = $user->carts()->where('status', CartStatus::Active)->firstOrFail();
        }

        return $cart->load('items.product');
    }

    private function findItemForUser(Request $request, string $cartItemId): CartItem
    {
        $cart = $this->activeCart($request);

        $item = $cart->items()->wherePublicId($cartItemId)->first();

        if ($item === null) {
            throw ProblemException::notFound('No such cart item.');
        }

        return $item;
    }

    /**
     * A soft check: the customer can add more than is currently on the shelf (it may be
     * restocked, or picking may find extra), but not more than the product's own order
     * bounds, and not so far past available stock that the request is clearly wrong.
     */
    private function assertQuantityAllowed(Product $product, string $quantity): void
    {
        $minimum = $product->min_order_quantity ?? '1';
        $maximum = $product->max_order_quantity;

        if (Money::compare($quantity, $minimum, Money::QUANTITY_SCALE) < 0
            || ($maximum !== null && Money::compare($quantity, $maximum, Money::QUANTITY_SCALE) > 0)) {
            throw ProblemException::quantityOutOfRange(
                $product->public_id,
                $quantity,
                $minimum,
                $maximum,
            );
        }

        $available = $product->inventory?->quantity_available ?? '0';

        if (Money::compare($quantity, $available, Money::QUANTITY_SCALE) > 0) {
            throw ProblemException::insufficientStock($product->public_id, $quantity, $available, $product->name);
        }
    }
}
