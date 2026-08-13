<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An RFC 9457 problem detail, thrown.
 *
 * Every failure this API can express has a named constructor here, so the registry in
 * `docs/api-design.md` §3 lives in one place rather than being spelled out at each throw
 * site. The `type` suffix is part of the contract and must not be renamed; `title` and
 * `detail` are human-readable and may change freely.
 *
 * Status code discipline:
 *   400 — malformed. Nothing was understood.
 *   401 — no valid identity.
 *   403 — valid identity, insufficient role. Never used for ownership failures.
 *   404 — no such resource, *or it belongs to someone else*.
 *   409 — valid but conflicts with current state. Retryable once the client reacts.
 *   422 — well-formed and understood, but semantically invalid. Retrying unchanged will not help.
 */
class ProblemException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extensions  additional members merged into the document
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly int $status,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
    ) {
        parent::__construct($detail ?? $title, $status);
    }

    public function render(Request $request): JsonResponse
    {
        return self::response($this->type, $this->title, $this->status, $this->detail, $this->extensions, $request);
    }

    /**
     * Build the response document. Shared with the handlers that translate framework
     * exceptions, so every error leaves this application in the same shape.
     *
     * @param  array<string, mixed>  $extensions
     */
    public static function response(
        string $type,
        string $title,
        int $status,
        ?string $detail = null,
        array $extensions = [],
        ?Request $request = null,
    ): JsonResponse {
        $document = [
            'type' => rtrim((string) config('api.problem_base_uri'), '/').'/'.$type,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null) {
            $document['detail'] = $detail;
        }

        if ($request !== null) {
            $document['instance'] = $request->getRequestUri();
        }

        return response()
            ->json([...$document, ...$extensions], $status)
            ->header('Content-Type', 'application/problem+json');
    }

    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------

    /**
     * Never distinguishes "no such user" from "wrong password" — the difference is an
     * account-enumeration oracle.
     */
    public static function invalidCredentials(): self
    {
        return new self(
            'invalid-credentials',
            'Invalid credentials',
            401,
            'The email address or password is incorrect.',
        );
    }

    /**
     * A refresh token was replayed after rotation, or presented after revocation.
     *
     * A benign race and a stolen token are indistinguishable, so this is always treated as
     * theft: the entire chain for that user is revoked and every device must sign in again.
     */
    public static function tokenRevoked(): self
    {
        return new self(
            'token-revoked',
            'Session ended',
            401,
            'This refresh token is no longer valid. All sessions have been ended; please sign in again.',
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            'unauthorized',
            'Authentication required',
            401,
            'A valid access token is required for this operation.',
        );
    }

    public static function forbidden(): self
    {
        return new self(
            'forbidden',
            'Insufficient permissions',
            403,
            'Your account does not have the role required for this operation.',
        );
    }

    /**
     * The credential is genuine but the account behind it is no longer permitted to act.
     *
     * 403 rather than 401: retrying with a fresh token will not help, and a client that sees
     * 401 will correctly try to refresh, which would loop. The distinct `type` is what lets a
     * client tell "sign in again" apart from "contact support".
     */
    public static function accountSuspended(): self
    {
        return new self(
            'account-suspended',
            'Account suspended',
            403,
            'This account is suspended and cannot be used. Please contact support.',
        );
    }

    // -----------------------------------------------------------------------
    // Requests
    // -----------------------------------------------------------------------

    public static function badRequest(?string $detail = null): self
    {
        return new self('bad-request', 'Malformed request', 400, $detail);
    }

    /**
     * Also returned when a resource exists but belongs to another customer. A 403 there
     * would confirm existence, letting an attacker enumerate valid ids by watching which
     * ones answer differently.
     */
    public static function notFound(?string $detail = null): self
    {
        return new self('not-found', 'Not found', 404, $detail ?? 'No such resource.');
    }

    /**
     * @param  array<int, array{field: string, message: string}>  $errors
     */
    public static function validationFailed(array $errors): self
    {
        $count = count($errors);

        return new self(
            'validation-failed',
            'Validation failed',
            422,
            $count === 1 ? '1 field is invalid.' : "{$count} fields are invalid.",
            ['errors' => $errors],
        );
    }

    public static function duplicateResource(string $detail): self
    {
        return new self('duplicate-resource', 'Already exists', 409, $detail);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            'rate-limited',
            'Too many requests',
            429,
            "Too many requests. Retry in {$retryAfterSeconds} seconds.",
            ['retryAfter' => $retryAfterSeconds],
        );
    }

    // -----------------------------------------------------------------------
    // Catalogue and cart
    // -----------------------------------------------------------------------

    public static function insufficientStock(string $productId, string $requested, string $available, string $productName): self
    {
        return new self(
            'insufficient-stock',
            'Not enough stock',
            422,
            "Only {$available} of {$productName} remain.",
            ['productId' => $productId, 'requested' => $requested, 'available' => $available],
        );
    }

    public static function quantityOutOfRange(string $productId, string $requested, string $minimum, ?string $maximum): self
    {
        return new self(
            'quantity-out-of-range',
            'Quantity out of range',
            422,
            $maximum === null
                ? "Quantity must be at least {$minimum}."
                : "Quantity must be between {$minimum} and {$maximum}.",
            array_filter([
                'productId' => $productId,
                'requested' => $requested,
                'minimum' => $minimum,
                'maximum' => $maximum,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * The cart was read at one price and checked out at another. The customer must see the
     * new figure before the charge is authorised.
     *
     * @param  array<int, array<string, string>>  $changes
     */
    public static function priceChanged(array $changes): self
    {
        return new self(
            'price-changed',
            'Prices have changed',
            409,
            'Some items are no longer at the price shown. Review your cart and try again.',
            ['changes' => $changes],
        );
    }

    /**
     * The `If-Match` value did not match the current cart. Grocery shopping happens across
     * two tabs and a phone; without this a PATCH silently clobbers an addition made 200 ms
     * earlier elsewhere and the customer never learns.
     */
    public static function staleCart(string $currentEtag): self
    {
        return new self(
            'stale-cart',
            'Cart has changed',
            409,
            'The cart changed since you last read it. Fetch it again and retry.',
            ['currentEtag' => $currentEtag],
        );
    }

    public static function staleCheckoutQuote(): self
    {
        return new self(
            'stale-checkout-quote',
            'Checkout quote has changed',
            409,
            'The quote expired or no longer matches the current cart, address, delivery slot, or payment method. Request a new quote and try again.',
        );
    }

    public static function outsideDeliveryArea(): self
    {
        return new self(
            'outside-delivery-area',
            'Outside the delivery area',
            422,
            'These coordinates fall outside the serviceable delivery region.',
        );
    }

    // -----------------------------------------------------------------------
    // Orders and fulfilment
    // -----------------------------------------------------------------------

    public static function slotUnavailable(?string $slotId = null): self
    {
        return new self(
            'slot-unavailable',
            'Delivery window unavailable',
            409,
            'That delivery window is full or is no longer offered.',
            array_filter(['slotId' => $slotId]),
        );
    }

    public static function invalidStatusTransition(string $from, string $to): self
    {
        return new self(
            'invalid-status-transition',
            'Invalid status transition',
            409,
            "An order cannot move from {$from} to {$to}.",
            ['from' => $from, 'to' => $to],
        );
    }

    /**
     * Card and wallet orders must receive an authoritative provider event before a staff member
     * can make them operational. This is deliberately a separate type from an order-state error:
     * a retry should happen after payment authorization, not after an arbitrary status change.
     */
    public static function paymentNotAuthorized(string $paymentStatus): self
    {
        return new self(
            'payment-not-authorized',
            'Payment has not been authorized',
            409,
            'This card or wallet order cannot be confirmed until payment is authorized or captured.',
            ['paymentStatus' => $paymentStatus],
        );
    }

    public static function paymentUnavailable(): self
    {
        return new self(
            'payment-unavailable',
            'Payment method unavailable',
            503,
            'Bakong payments are not configured. Please select another method or contact support.',
        );
    }

    public static function paymentAttemptExpired(): self
    {
        return new self(
            'payment-attempt-expired',
            'Payment attempt expired',
            409,
            'This payment QR has expired. Start a new Bakong payment attempt.',
        );
    }

    public static function paymentPending(): self
    {
        return new self(
            'payment-pending',
            'Payment has not completed',
            409,
            'Bakong has not confirmed this payment yet. Please complete payment and try again.',
        );
    }

    public static function paymentVerificationFailed(): self
    {
        return new self(
            'payment-verification-failed',
            'Payment verification failed',
            502,
            'The payment provider could not be verified safely. No order state was changed; try again later.',
        );
    }

    public static function itemsUnresolved(int $pendingCount): self
    {
        return new self(
            'items-unresolved',
            'Order has unpicked lines',
            409,
            "{$pendingCount} line(s) are still pending. Every line must be picked, substituted or marked unavailable first.",
            ['pendingCount' => $pendingCount],
        );
    }

    public static function substitutionRefused(string $itemId): self
    {
        return new self(
            'substitution-refused',
            'Substitution refused',
            409,
            'The customer asked for no substitution on this line. Mark it unavailable instead.',
            ['itemId' => $itemId],
        );
    }

    public static function substitutionApprovalRequired(): self
    {
        return new self(
            'substitution-approval-required',
            'Customer approval required',
            409,
            'This customer requested approval before a substitute is selected.',
        );
    }

    public static function invalidSubstituteProduct(): self
    {
        return new self(
            'invalid-substitute-product',
            'Invalid substitute product',
            422,
            'The substitute must be an active product with the same selling shape as the original line.',
        );
    }

    public static function fulfillmentQuantityOutOfRange(): self
    {
        return new self(
            'fulfillment-quantity-out-of-range',
            'Fulfilment quantity out of range',
            422,
            'The selected quantity must be greater than zero and cannot exceed the ordered quantity.',
        );
    }

    public static function invalidFulfillmentWeight(): self
    {
        return new self(
            'invalid-fulfillment-weight',
            'Invalid fulfilment weight',
            422,
            'Weighted lines require a positive picked weight; unit lines must not carry one.',
        );
    }

    public static function orderItemAlreadyResolved(): self
    {
        return new self(
            'order-item-already-resolved',
            'Order item already resolved',
            409,
            'A resolved order item cannot be picked, substituted, or marked unavailable again.',
        );
    }

    public static function reconciliationNotRequired(): self
    {
        return new self(
            'reconciliation-not-required',
            'Reconciliation is not required',
            409,
            'This order has no outstanding payment difference to reconcile.',
        );
    }

    public static function reconciliationRequired(?string $status = null): self
    {
        return new self(
            'reconciliation-required',
            'Payment reconciliation required',
            409,
            'The final basket differs from the authorized payment. Record the external collection or refund before dispatch.',
            array_filter(['reconciliationStatus' => $status]),
        );
    }

    public static function inventoryAdjustmentWouldOversell(string $reserved): self
    {
        return new self(
            'inventory-adjustment-would-oversell',
            'Inventory adjustment would violate reservations',
            409,
            'The resulting on-hand quantity cannot be less than inventory already reserved for active orders.',
            ['reservedQuantity' => $reserved],
        );
    }

    public static function slotCapacityBelowBookings(int $bookedCount): self
    {
        return new self(
            'slot-capacity-below-bookings',
            'Delivery slot capacity is below active bookings',
            409,
            'Capacity cannot be reduced below the number of active bookings.',
            ['bookedCount' => $bookedCount],
        );
    }

    public static function slotWindowLocked(): self
    {
        return new self(
            'slot-window-locked',
            'Delivery slot window is locked',
            409,
            'A delivery window with active bookings cannot be rescheduled.',
        );
    }
}
