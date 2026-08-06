<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\ProblemException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Steps an order through the admin fulfilment flow — shared by the authenticated JSON API
 * (`AdminOrderController`) and the Telegram webhook, so the transition table and audit trail
 * only exist in one place.
 *
 * The Telegram-facing flow is a deliberately simplified four-step progression — confirm,
 * prepare, on delivery, complete — that skips `packed`. `Packed` remains a valid
 * `OrderStatus` for whatever eventually drives picking/packing in more detail; it is just not
 * a step this action exposes.
 */
class AdvanceOrderStatus
{
    public const DEFAULT_REJECTION_NOTE = 'Rejected by admin via Telegram.';

    /**
     * @var array<string, array{from: array<int, OrderStatus>, to: OrderStatus}>
     */
    private const TRANSITIONS = [
        'confirm' => ['from' => [OrderStatus::PendingPayment], 'to' => OrderStatus::Confirmed],
        'prepare' => ['from' => [OrderStatus::Confirmed], 'to' => OrderStatus::Picking],
        'deliver' => ['from' => [OrderStatus::Picking], 'to' => OrderStatus::OutForDelivery],
        'complete' => ['from' => [OrderStatus::OutForDelivery], 'to' => OrderStatus::Delivered],
        'reject' => [
            'from' => [OrderStatus::PendingPayment, OrderStatus::Confirmed, OrderStatus::Picking],
            'to' => OrderStatus::Cancelled,
        ],
    ];

    /**
     * `cancel` is an alias for `reject` — Telegram and the JSON API both expose it, since an
     * admin abandoning an order before it is out for delivery is the same event either way.
     */
    private const ALIASES = [
        'cancel' => 'reject',
    ];

    public function handle(Order $order, string $action, User $admin, ?string $reason = null): Order
    {
        $verb = self::ALIASES[$action] ?? $action;

        if (! array_key_exists($verb, self::TRANSITIONS)) {
            throw ProblemException::badRequest("Unknown order action \"{$action}\".");
        }

        $transition = self::TRANSITIONS[$verb];
        $fromStatus = $order->status;

        if (! in_array($fromStatus, $transition['from'], true)) {
            throw ProblemException::invalidStatusTransition($fromStatus->value, $transition['to']->value);
        }

        $toStatus = $transition['to'];

        DB::transaction(function () use ($order, $fromStatus, $toStatus, $verb, $admin, $reason) {
            $attributes = ['status' => $toStatus];

            if ($toStatus === OrderStatus::Confirmed) {
                $attributes['confirmed_at'] = now();
            } elseif ($toStatus === OrderStatus::Delivered) {
                $attributes['delivered_at'] = now();
            } elseif ($toStatus === OrderStatus::Cancelled) {
                $attributes['cancelled_at'] = now();
                $attributes['cancellation_reason'] = $reason ?? self::DEFAULT_REJECTION_NOTE;
            }

            $order->update($attributes);

            $order->statusHistory()->create([
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $admin->id,
                'note' => $verb === 'reject' ? ($reason ?? self::DEFAULT_REJECTION_NOTE) : $reason,
            ]);
        });

        return $order->refresh();
    }
}
