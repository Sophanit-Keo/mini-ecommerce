<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddWishlistItemRequest;
use App\Http\Resources\WishlistItemResource;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Every lookup is scoped through the authenticated user's own wishlist items — never
 * fetch-then-check — following the same IDOR discipline as AddressController.
 */
class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->wishlistItems()
            ->with('product.primaryImage', 'product.category', 'product.inventory')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => WishlistItemResource::collection($items)]);
    }

    /**
     * Adding a product that is already saved is idempotent: it returns the existing item
     * with 200 rather than a 409. A wishlist has no state that a second "save" could
     * meaningfully conflict with, so treating the duplicate as an error would only make
     * clients special-case a "toggle" button that should just work either way.
     */
    public function storeItem(AddWishlistItemRequest $request): JsonResponse
    {
        $product = Product::where('is_active', true)->wherePublicId($request->string('productId')->toString())->first();

        if ($product === null) {
            throw ProblemException::notFound('No such product.');
        }

        $user = $request->user();

        $existing = $user->wishlistItems()->where('product_id', $product->id)->first();

        if ($existing !== null) {
            return WishlistItemResource::make($existing->load('product.inventory'))->response()->setStatusCode(Response::HTTP_OK);
        }

        try {
            $item = $user->wishlistItems()->create(['product_id' => $product->id]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'uq_wishlist_items_user_product')) {
                throw $e;
            }

            // Lost a race with a concurrent add for the same product: the other request's
            // row now exists, so return it instead of surfacing a spurious error.
            $item = $user->wishlistItems()->where('product_id', $product->id)->firstOrFail();

            return WishlistItemResource::make($item->load('product.inventory'))->response()->setStatusCode(Response::HTTP_OK);
        }

        return WishlistItemResource::make($item->load('product.inventory'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroyItem(Request $request, string $wishlistItemId): Response
    {
        $this->findItemForUser($request, $wishlistItemId)->delete();

        return response()->noContent();
    }

    private function findItemForUser(Request $request, string $wishlistItemId): WishlistItem
    {
        $item = $request->user()->wishlistItems()->wherePublicId($wishlistItemId)->first();

        if ($item === null) {
            throw ProblemException::notFound('No such wishlist item.');
        }

        return $item;
    }
}
