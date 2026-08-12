<?php

namespace App\Console\Commands;

use App\Actions\Orders\ManageOrderReservation;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Releases capacity and stock for card/wallet orders whose payment reservation expired.
 *
 * Run this command at least once per minute from the production scheduler. It is intentionally
 * deterministic and local to the application database; no in-process daemon or agent schedule
 * is required. Every candidate is rechecked under a row lock, making overlapping command runs
 * and a simultaneous customer/admin cancellation safe.
 */
class ReleaseExpiredOrderReservations extends Command
{
    protected $signature = 'orders:release-expired-reservations {--limit=100 : Maximum orders to process in one run}';

    protected $description = 'Cancel unpaid orders whose stock and delivery-slot reservations have expired';

    public function handle(ManageOrderReservation $reservations): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 1000);
        $candidateIds = Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $released = 0;

        foreach ($candidateIds as $id) {
            $didRelease = DB::transaction(function () use ($id, $reservations): bool {
                $order = Order::query()->lockForUpdate()->find($id);

                if ($order === null
                    || $order->status !== OrderStatus::PendingPayment
                    || $order->reservation_expires_at === null
                    || $order->reservation_expires_at->isFuture()) {
                    return false;
                }

                $reservations->release($order);
                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'payment_status' => PaymentStatus::Failed,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Payment authorization expired.',
                ]);
                $order->statusHistory()->create([
                    'from_status' => OrderStatus::PendingPayment,
                    'to_status' => OrderStatus::Cancelled,
                    'changed_by' => null,
                    'note' => 'Payment authorization expired; stock and delivery capacity released.',
                ]);

                return true;
            });

            if ($didRelease) {
                $released++;
            }
        }

        $this->info("Released {$released} expired reservation(s).");

        return self::SUCCESS;
    }
}
