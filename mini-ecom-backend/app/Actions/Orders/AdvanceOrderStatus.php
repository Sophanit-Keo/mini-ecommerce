<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
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

    public function __construct(private readonly ManageOrderReservation $reservations) {}

    /**
     * @var array<string, array{from: array<int, OrderStatus>, to: OrderStatus}>
     */
    private const TRANSITIONS = [
        'confirm' => ['from' => [OrderStatus::PendingPayment], 'to' => OrderStatus::Confirmed],
        'prepare' => ['from' => [OrderStatus::Confirmed], 'to' => OrderStatus::Picking],
        'dispatch' => ['from' => [OrderStatus::Packed], 'to' => OrderStatus::OutForDelivery],
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
        // Preserve the existing Telegram/API verb while routing it through the stricter
        // packed-only dispatch transition introduced by line-level fulfilment.
        'deliver' => 'dispatch',
    ];

    public function handle(Order $order, string $action, User $admin, ?string $reason = null): Order
    {
        $verb = self::ALIASES[$action] ?? $action;

        if (! array_key_exists($verb, self::TRANSITIONS)) {
            throw ProblemException::badRequest("Unknown order action \"{$action}\".");
        }

        $transition = self::TRANSITIONS[$verb];

        return DB::transaction(function () use ($order, $transition, $verb, $admin, $reason): Order {
            // The controller or webhook necessarily read an order before entering this action.
            // Read it again under an exclusive lock before deciding whether the transition is
            // legal. Otherwise two admins can both see `pending_payment`, both write
            // `confirmed`, and both create an audit row for the same state change.
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $fromStatus = $lockedOrder->status;

            if (! in_array($fromStatus, $transition['from'], true)) {
                throw ProblemException::invalidStatusTransition($fromStatus->value, $transition['to']->value);
            }

            $toStatus = $transition['to'];

            // Cash-on-delivery follows an explicit operational policy: staff may confirm it
            // without a processor authorization. Card and wallet orders must first be updated
            // by a verified provider event to `authorized` or `captured`; no client/admin route
            // can skip that financial boundary.
            if ($verb === 'confirm'
                && $lockedOrder->payment_method !== PaymentMethod::CashOnDelivery
                && ! in_array($lockedOrder->payment_status, [PaymentStatus::Authorized, PaymentStatus::Captured], true)) {
                throw ProblemException::paymentNotAuthorized($lockedOrder->payment_status->value);
            }

            if ($verb === 'dispatch') {
                if (! $lockedOrder->hasFinalTotals() || ! $lockedOrder->hasFulfilledInventory()) {
                    throw ProblemException::invalidStatusTransition($lockedOrder->status->value, OrderStatus::Packed->value);
                }

                if ($lockedOrder->reconciliation_status->blocksDispatch()) {
                    throw ProblemException::reconciliationRequired($lockedOrder->reconciliation_status->value);
                }

                if ($lockedOrder->payment_method !== PaymentMethod::CashOnDelivery
                    && $lockedOrder->payment_status !== PaymentStatus::Captured) {
                    throw ProblemException::paymentNotAuthorized($lockedOrder->payment_status->value);
                }
            }

            $attributes = ['status' => $toStatus];

            if ($toStatus === OrderStatus::Confirmed) {
                $attributes['confirmed_at'] = now();
            } elseif ($toStatus === OrderStatus::Delivered) {
                $attributes['delivered_at'] = now();
            } elseif ($toStatus === OrderStatus::Cancelled) {
                $attributes['cancelled_at'] = now();
                $attributes['cancellation_reason'] = $reason ?? self::DEFAULT_REJECTION_NOTE;
            }

            if ($toStatus === OrderStatus::Cancelled) {
                // Rejection is terminal for a pre-dispatch order: return its slot and every
                // reserved quantity in the same transaction as its status-history entry.
                $this->reservations->release($lockedOrder, $admin->id);
            }

            $lockedOrder->update($attributes);

            $lockedOrder->statusHistory()->create([
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $admin->id,
                'note' => $verb === 'reject' ? ($reason ?? self::DEFAULT_REJECTION_NOTE) : $reason,
            ]);

            return $lockedOrder;
        });
    }
}
