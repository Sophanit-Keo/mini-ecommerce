<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\AdvanceOrderStatus;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdvanceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;

/**
 * The JSON counterpart of the Telegram bot's admin buttons — `AdvanceOrderStatus` is the
 * single source of truth for what transitions are legal, so this controller and the
 * Telegram webhook can never disagree about it.
 *
 * Unlike `OrderController`, lookups here are not scoped to a particular owner: any admin may
 * act on any order, by design.
 */
class AdminOrderController extends Controller
{
    public function __construct(private readonly AdvanceOrderStatus $advanceOrderStatus) {}

    public function advance(AdvanceOrderRequest $request, string $orderId): OrderResource
    {
        $order = Order::wherePublicId($orderId)->first();

        if ($order === null) {
            throw ProblemException::notFound('No such order.');
        }

        $order = $this->advanceOrderStatus->handle(
            order: $order,
            action: $request->string('action')->toString(),
            admin: $request->user(),
            reason: $request->input('reason'),
        );

        return OrderResource::make($order->load(['items', 'statusHistory']));
    }
}
