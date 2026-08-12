# Release 1 — Bakong Checkout Implementation

**Author:** Manus AI  
**Status:** Implemented and verified in the `hardening/security-and-performance` branch  
**Scope:** Server-authoritative checkout quotes, Bakong KHQR payment initiation and verification, payment recovery, and the associated operational controls.

## Executive summary

Release 1 makes checkout usable with a **Bakong KHQR payment flow** while preserving the stock, delivery-capacity, and payment-state controls introduced in the prior release. The implementation does not accept a client-supplied payment result, transaction hash, payment amount, or Bakong MD5 reference. Instead, the backend creates the dynamic QR payload, stores the provider reference, and queries Bakong with its environment-sourced merchant token before it changes an order from `pending` to `authorized`.

> **Operational decision:** The integration uses server-side transaction verification rather than an invented outbound webhook. The available Bakong material supports transaction lookups by MD5/hash, while an official merchant callback contract was not available to this implementation. The client therefore asks the API to verify a pending payment, and the API alone decides whether it is authorized. [1] [2]

| Release 1 capability | Implementation result | Customer effect |
|---|---|---|
| Quote-first checkout | `POST /v1/checkout/quote` creates a ten-minute, HMAC-signed quote bound to the caller, live cart, address, delivery slot, and payment method. | Customers see a server-calculated total before an order reserves stock. |
| Stale-checkout protection | `POST /v1/orders` revalidates the signed quote against current product, inventory, address, slot, and payment data. | Changed prices, inventory, slot capacity, or cart lines cannot be charged from a stale client screen. |
| Dynamic Bakong payment | The API emits an amount-bound dynamic KHQR payload and stores an attempt with a unique MD5 reference. | The customer can pay with their Bakong-compatible wallet without the API exposing merchant credentials. |
| Provider confirmation | The API verifies its own MD5 reference with Bakong over HTTPS and only then updates the payment state to `authorized`. | A browser cannot claim that a transfer succeeded. |
| Payment recovery | Failed/expired payment orders can restore current, revalidated lines to the active cart exactly once. | Customers can retry checkout without duplicate cart lines or stale delivery reservations. |

## Delivered API workflow

The following sequence is now the required client integration flow.

| Step | Endpoint | Server responsibility | Expected client action |
|---:|---|---|---|
| 1 | `POST /v1/checkout/quote` | Validates the active cart, owned address, future delivery slot, stock, totals, and payment method; returns `quoteToken`. | Present the quote and retain the token only until checkout. |
| 2 | `POST /v1/orders` | Rechecks the token and live state, then atomically reserves inventory and delivery capacity. | Submit the exact quote token and an idempotency key. |
| 3 | `POST /v1/orders/{orderId}/payments/bakong` | Generates or returns the current dynamic KHQR attempt. | Render or hand the returned `khqrPayload` to the KHQR-capable client application. |
| 4 | `POST /v1/orders/{orderId}/payments/bakong/verify` | Sends the stored MD5 reference to Bakong using the merchant token and records a verified result. | Poll only after the customer has attempted payment; handle `payment-pending` without treating it as success. |
| 5 | `POST /v1/admin/orders/{orderId}/advance` | Allows confirmation only after an authorized/captured non-COD payment. | Operations staff confirm only after the API exposes the authorized state. |

### Payment state behavior

Bakong QR transfers settle outside this application, but the existing order workflow retains `authorized` after a verified transfer. That state is sufficient to unlock operational confirmation while Release 2 defines actual-weight finalization, reconciliation, and any refund/difference policy. The raw provider result remains in the payment-attempt record for audit and is never returned to a customer.

| Event | Attempt state | Order payment state | Reservation result |
|---|---|---|---|
| QR created | `pending` | `pending` | Stock and slot remain reserved until the existing reservation expiry. |
| Bakong reports no transaction | `pending` | `pending` | No order field changes; the client may retry verification. |
| Bakong confirms the stored MD5 | `verified` | `authorized` | Reservation expiry is cleared atomically. |
| Reservation expires before verification | `expired` | `failed` | The scheduled release command cancels the order and releases stock and capacity. |
| Customer restores a failed order | unchanged | `failed` | Historical lines return to the current cart after live product/inventory validation; no slot is rebooked. |

## Security and correctness controls

The implementation applies the following controls to the payment boundary.

| Control | Implementation | Benefit |
|---|---|---|
| Secret isolation | `BAKONG_API_TOKEN` and merchant profile data are read only from environment configuration. `.env.example` contains blank placeholders. | Prevents tokens and merchant identifiers from entering source control. |
| Fail closed | `BAKONG_ENABLED=false` by default; Bakong quotes/attempts return `503 payment-unavailable` until every required value exists. | Prevents orders that cannot be paid. |
| Server-generated amount and reference | The QR payload, MD5 reference, expiry, and amount are created on the backend and saved in `payment_attempts`. | Prevents a client from substituting a cheaper QR, an unrelated transaction, or a forged success result. |
| Signed quote | The quote contains an HMAC over user, cart fingerprint, address, slot, payment method, and expiry. | Prevents tampering and detects checkout state changes. |
| Authorization and ownership | Every payment/restore lookup is scoped through the authenticated user’s own order query. | Preserves the API’s existing IDOR posture: another user receives `404`, not payment data. |
| Idempotency and replay safety | A pending or verified Bakong payment attempt is returned on a retry; duplicate verified provider transaction hashes are rejected. | Avoids duplicate QR attempts and duplicate payment processing. |
| External-call safety | The Bakong HTTP client has separate connect/overall timeouts and a dedicated per-user, per-route payment rate limit. | Limits worker exhaustion, token abuse, and verification floods. |
| Exact-once cart recovery | `orders.cart_restored_at` is written in the same transaction as the restored cart lines. | A retried restore cannot duplicate cart items. |

## Environment configuration and deployment

Populate these values in the deployment secret store or runtime environment. Do **not** place production values in `.env.example`, documentation, commits, screenshots, CI logs, or client builds.

| Variable | Required | Purpose |
|---|---:|---|
| `BAKONG_ENABLED=true` | Yes, after validation | Activates the Bakong checkout option. |
| `BAKONG_API_TOKEN` | Yes | Provider-issued server credential for MD5 transaction verification. |
| `BAKONG_ACCOUNT_ID` | Yes | Merchant Bakong account encoded into KHQR. |
| `BAKONG_MERCHANT_NAME` | Yes | Merchant display name encoded into KHQR. |
| `BAKONG_MERCHANT_CITY` | Yes | Merchant city encoded into KHQR. |
| `BAKONG_PROFILE_TYPE` | Yes | `individual` or `merchant`. |
| `BAKONG_MERCHANT_ID` | Merchant only | Merchant identifier issued for the merchant profile. |
| `BAKONG_ACQUIRING_BANK` | Merchant only | Acquiring-bank value issued for the merchant profile. |
| `BAKONG_MOBILE_NUMBER` | Optional | Payment-request mobile label if provided by the merchant profile. |
| `BAKONG_CURRENCY` | Yes | Must match orders paid through Bakong; default `USD`. |
| `BAKONG_PAYMENT_TTL_MINUTES` | Yes | Dynamic QR validity; default 20 minutes and bounded to 30 minutes. |
| `BAKONG_BASE_URL` | Yes | Provider API base URL; default `https://api-bakong.nbc.gov.kh`. |
| `BAKONG_CHECK_TRANSACTION_PATH` | Yes | Provider MD5 verification path; default `/v1/check_transaction_by_md5`. |

### Production runbook

Perform the deployment in this order. First, add the production values through the platform’s secret manager. Rotate the Bakong token immediately if it is ever pasted into a ticket, chat, terminal recording, or repository. Second, place the application in a controlled deployment window and run the migrations. Third, rebuild Laravel caches only after the new environment variables are present; cached configuration will otherwise retain old values.

```bash
php artisan migrate --force
php artisan optimize
php artisan schedule:list
```

The existing reservation-release scheduler must continue to run at least once per minute. It now expires pending Bakong payment attempts alongside the unpaid reservation before it releases inventory and delivery capacity.

Before turning `BAKONG_ENABLED` on for customers, conduct a sandbox or small-value merchant-account acceptance test. Confirm that the recipient wallet scans the generated KHQR, that the amount and merchant identity are correct, that `POST /payments/bakong/verify` transitions the attempt to `verified`, and that an administrator cannot confirm the order until the API reflects `paymentStatus: authorized`. This final provider-account check is essential because merchant profile fields and API entitlements are issued outside this repository.

## Verification record

| Check | Result |
|---|---|
| New Release 1 regression coverage | 6 tests / 45 assertions passed, covering disabled configuration, dynamic QR attempts, provider-confirmed authorization, pending verification, IDOR, and exact-once cart recovery. |
| Full automated suite | 316 tests / 1,133 assertions passed after clearing deployment caches. |
| Style validation | Laravel Pint passed. |
| Production cache compilation | Configuration, route, and view caches compiled successfully. |
| Database migration | `2026_08_13_000004_add_bakong_payment_attempts` applied successfully in the validation environment. |

## Remaining Release 2 dependency

Release 1 deliberately stops before actual-weight finalization. A verified Bakong transfer is represented as `authorized` so the existing order-state gate can operate safely. Release 2 should add per-line pick/substitute/unavailable commands, compute final totals from actual weights, and define the operational refund or adjustment process for differences between the estimated Bakong payment and final basket total.

## References

[1]: https://api-bakong.nbc.gov.kh/document "Bakong Open API documentation portal"
[2]: https://github.com/davysrp/khqr-gateway "KHQR Gateway PHP reference implementation, including MD5 transaction lookup conventions"
