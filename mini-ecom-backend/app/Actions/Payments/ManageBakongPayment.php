<?php

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\ProblemException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Support\Bakong\BakongGateway;
use App\Support\Bakong\BakongKhqr;
use Illuminate\Support\Facades\DB;

final class ManageBakongPayment
{
    public function __construct(
        private readonly BakongKhqr $khqr,
        private readonly BakongGateway $gateway,
    ) {}

    public function start(User $user, Order $order): PaymentAttempt
    {
        $this->assertBakongOrder($order);
        $this->khqr->assertConfigured();

        return DB::transaction(function () use ($order): PaymentAttempt {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertBakongOrder($lockedOrder);

            $latest = $lockedOrder->paymentAttempts()
                ->where('provider', 'bakong')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Starting a payment is idempotent even after a mobile client retried late. A
            // confirmed transfer must never produce another QR/MD5 attempt for the same order.
            if (($lockedOrder->payment_status === PaymentStatus::Authorized || $lockedOrder->payment_status === PaymentStatus::Captured)
                && $latest !== null) {
                return $latest;
            }

            if ($latest !== null && $latest->status === PaymentAttemptStatus::Pending && $latest->expires_at->isFuture()) {
                return $latest;
            }

            if ($latest !== null && $latest->status === PaymentAttemptStatus::Pending) {
                $latest->update(['status' => PaymentAttemptStatus::Expired]);
            }

            if (strtoupper($lockedOrder->currency) !== strtoupper((string) config('bakong.currency'))) {
                throw ProblemException::paymentUnavailable();
            }

            $ttl = min(max((int) config('bakong.payment_ttl_minutes'), 1), 30);
            $expiresAt = now()->addMinutes($ttl);

            if ($lockedOrder->reservation_expires_at !== null && $expiresAt->greaterThan($lockedOrder->reservation_expires_at)) {
                $expiresAt = $lockedOrder->reservation_expires_at;
            }

            if ($expiresAt->isPast()) {
                throw ProblemException::paymentAttemptExpired();
            }

            $qr = $this->khqr->create(
                (string) $lockedOrder->total_estimated,
                $lockedOrder->currency,
                $this->khqr->uniqueReference($lockedOrder->order_number),
            );

            return $lockedOrder->paymentAttempts()->create([
                'provider' => 'bakong',
                'status' => PaymentAttemptStatus::Pending,
                'provider_reference' => md5($qr['payload']),
                'khqr_payload' => $qr['payload'],
                'amount' => $lockedOrder->total_estimated,
                'currency' => $lockedOrder->currency,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    public function verify(User $user, Order $order): PaymentAttempt
    {
        $this->assertBakongOrder($order);

        $attempt = $order->paymentAttempts()
            ->where('provider', 'bakong')
            ->orderByDesc('id')
            ->first();

        if ($attempt === null) {
            throw ProblemException::notFound('No Bakong payment attempt has been started for this order.');
        }

        if ($attempt->status === PaymentAttemptStatus::Verified) {
            return $attempt;
        }

        if ($attempt->status === PaymentAttemptStatus::Expired || $attempt->expires_at->isPast()) {
            if ($attempt->status === PaymentAttemptStatus::Pending) {
                $attempt->update(['status' => PaymentAttemptStatus::Expired]);
            }

            throw ProblemException::paymentAttemptExpired();
        }

        // Never hold a database lock while calling an external payment service. The result is
        // re-checked under lock below before it can affect an order.
        $response = $this->gateway->verify($attempt);

        if ($response === null) {
            $attempt->increment('verification_count');
            $attempt->update(['last_checked_at' => now()]);

            throw ProblemException::paymentPending();
        }

        $transactionHash = $this->gateway->transactionHash($response);

        return DB::transaction(function () use ($order, $attempt, $response, $transactionHash): PaymentAttempt {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($lockedAttempt->status === PaymentAttemptStatus::Verified) {
                return $lockedAttempt;
            }

            if ($lockedAttempt->status !== PaymentAttemptStatus::Pending || $lockedAttempt->expires_at->isPast()) {
                if ($lockedAttempt->status === PaymentAttemptStatus::Pending) {
                    $lockedAttempt->update(['status' => PaymentAttemptStatus::Expired]);
                }

                throw ProblemException::paymentAttemptExpired();
            }

            $this->assertBakongOrder($lockedOrder);

            if ($transactionHash !== null) {
                $duplicate = PaymentAttempt::query()
                    ->where('provider', 'bakong')
                    ->where('provider_transaction_hash', $transactionHash)
                    ->where('id', '!=', $lockedAttempt->id)
                    ->exists();

                if ($duplicate) {
                    throw ProblemException::paymentVerificationFailed();
                }
            }

            $lockedAttempt->update([
                'status' => PaymentAttemptStatus::Verified,
                'provider_transaction_hash' => $transactionHash,
                'provider_response' => $response,
                'verification_count' => $lockedAttempt->verification_count + 1,
                'last_checked_at' => now(),
                'verified_at' => now(),
            ]);

            // Bakong QR transfers settle immediately, but this application keeps the existing
            // `authorized` workflow state until Release 2 calculates final weighted totals and
            // defines reconciliation/refund policy. The verified provider payload is retained
            // on the attempt, and the order timeout is removed atomically.
            $lockedOrder->update([
                'payment_status' => PaymentStatus::Authorized,
                'authorized_amount' => $lockedOrder->total_estimated,
                'reservation_expires_at' => null,
            ]);

            return $lockedAttempt;
        });
    }

    private function assertBakongOrder(Order $order): void
    {
        if ($order->payment_method !== PaymentMethod::Bakong) {
            throw ProblemException::notFound('No Bakong payment is available for this order.');
        }

        if ($order->status !== OrderStatus::PendingPayment) {
            throw ProblemException::invalidStatusTransition($order->status->value, OrderStatus::PendingPayment->value);
        }

        if ($order->payment_status === PaymentStatus::Authorized || $order->payment_status === PaymentStatus::Captured) {
            return;
        }

        if ($order->payment_status !== PaymentStatus::Pending) {
            throw ProblemException::paymentAttemptExpired();
        }
    }
}
