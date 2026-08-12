<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Checkout\CreateCheckoutQuote;
use App\Enums\CartStatus;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCheckoutQuoteRequest;
use App\Models\DeliverySlot;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(private readonly CreateCheckoutQuote $quotes) {}

    public function quote(CreateCheckoutQuoteRequest $request): JsonResponse
    {
        $user = $request->user();
        $address = $user->addresses()->wherePublicId($request->string('addressId')->toString())->first();

        if ($address === null) {
            throw ProblemException::notFound('No such address.');
        }

        $slot = DeliverySlot::wherePublicId($request->string('deliverySlotId')->toString())->first();

        if ($slot === null) {
            throw ProblemException::slotUnavailable($request->string('deliverySlotId')->toString());
        }

        $cart = $user->carts()
            ->where('status', CartStatus::Active)
            ->with('items.product.inventory')
            ->first();

        if ($cart === null) {
            throw ProblemException::badRequest('The cart is empty.');
        }

        return response()->json($this->quotes->create($user, $cart, $address, $slot, $request->paymentMethod()));
    }
}
