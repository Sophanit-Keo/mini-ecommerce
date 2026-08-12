<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\ManageOrderFulfillment;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MarkOrderItemUnavailableRequest;
use App\Http\Requests\PickOrderItemRequest;
use App\Http\Requests\ReconcileOrderRequest;
use App\Http\Requests\SubstituteOrderItemRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;

class AdminFulfillmentController extends Controller
{
    public function __construct(private readonly ManageOrderFulfillment $fulfillment) {}

    public function pick(PickOrderItemRequest $request, string $orderId, string $itemId): OrderResource
    {
        return $this->resource($this->fulfillment->pick(
            $this->find($orderId),
            $itemId,
            $request->user(),
            $request->string('quantity')->toString(),
            $request->input('weightKg'),
            $request->input('note'),
        ));
    }

    public function substitute(SubstituteOrderItemRequest $request, string $orderId, string $itemId): OrderResource
    {
        return $this->resource($this->fulfillment->substitute(
            $this->find($orderId),
            $itemId,
            $request->user(),
            $request->string('productId')->toString(),
            $request->string('quantity')->toString(),
            $request->input('weightKg'),
            $request->input('reason'),
            $request->boolean('customerApproved'),
        ));
    }

    public function unavailable(MarkOrderItemUnavailableRequest $request, string $orderId, string $itemId): OrderResource
    {
        return $this->resource($this->fulfillment->unavailable(
            $this->find($orderId),
            $itemId,
            $request->user(),
            $request->string('reason')->toString(),
        ));
    }

    public function finalize(Request $request, string $orderId): OrderResource
    {
        return $this->resource($this->fulfillment->finalize($this->find($orderId), $request->user()));
    }

    public function reconcile(ReconcileOrderRequest $request, string $orderId): OrderResource
    {
        return $this->resource($this->fulfillment->reconcile(
            $this->find($orderId),
            $request->user(),
            $request->string('reference')->toString(),
        ));
    }

    private function find(string $orderId): Order
    {
        $order = Order::wherePublicId($orderId)->first();

        if ($order === null) {
            throw ProblemException::notFound('No such order.');
        }

        return $order;
    }

    private function resource(Order $order): OrderResource
    {
        return OrderResource::make($order->load([
            'deliverySlot',
            'latestPaymentAttempt',
            'items.product',
            'items.substitutions.substituteProduct',
            'statusHistory',
            'fulfillmentEvents',
        ]));
    }
}
