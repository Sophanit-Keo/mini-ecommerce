<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Notification;
use App\Models\Order;

/**
 * Records an in-app notification for the owning customer whenever an order's status changes.
 *
 * This is deliberately the *only* place a status-changed notification is produced. Any code
 * path that moves an order between statuses — the customer-facing cancel endpoint, an admin
 * action, a future Telegram bot, a console command — writes to `orders.status` and this
 * observer reacts, so nothing has to remember to notify the customer itself.
 */
class OrderObserver
{
    public function updated(Order $order): void
    {
        if (! $order->isDirty('status')) {
            return;
        }

        /** @var OrderStatus $from */
        $from = $order->getOriginal('status');
        $to = $order->status;

        Notification::create([
            'user_id' => $order->user_id,
            'type' => 'order_status_changed',
            'data' => [
                'orderId' => $order->public_id,
                'orderNumber' => $order->order_number,
                'fromStatus' => $from->value,
                'toStatus' => $to->value,
                'message' => $this->messageFor($order->order_number, $to),
            ],
        ]);
    }

    private function messageFor(string $orderNumber, OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::PendingPayment => "Your order {$orderNumber} is awaiting payment.",
            OrderStatus::Confirmed => "Your order {$orderNumber} is confirmed.",
            OrderStatus::Picking => "Your order {$orderNumber} is being picked.",
            OrderStatus::Packed => "Your order {$orderNumber} has been packed.",
            OrderStatus::OutForDelivery => "Your order {$orderNumber} is out for delivery.",
            OrderStatus::Delivered => "Your order {$orderNumber} has been delivered.",
            OrderStatus::Cancelled => "Your order {$orderNumber} has been cancelled.",
            OrderStatus::Refunded => "Your order {$orderNumber} has been refunded.",
        };
    }
}
