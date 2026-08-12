# Endpoint Gap Implementation — Release 0 and Delivery Discovery

**Project:** Grocerly Mini-Ecommerce Backend  
**Source assessment:** *Grocerly API Endpoint Gap Analysis*, supplied 13 August 2026  
**Implemented focus:** Release 0 safety controls and the smallest Release 1 delivery-discovery capability  
**Status:** Implemented and validated locally

## Implementation Outcome

The supplied gap analysis correctly identified that checkout could consume delivery capacity without reserving stock, that a pending card/wallet order could be confirmed by staff, and that customers had no API route for finding a valid delivery slot. This change set closes those immediate workflow gaps without pretending that a payment provider has been integrated.

> **Boundary:** the API now refuses to operationally confirm an unpaid card/wallet order, but it does **not** create payment intents, verify provider webhooks, capture funds, or issue refunds. Those functions require a chosen payment provider, credentials, webhook contract, and capture/refund policy.

| Assessment gap | Status | Implemented result |
|---|---|---|
| GAP-02: unpaid order confirmation | **Closed** | Card/wallet confirmation now requires `authorized` or `captured` payment status. Cash-on-delivery remains an explicit allowed policy. |
| GAP-03: no inventory reservation/release | **Closed for checkout reservation lifecycle** | Checkout locks and reserves each live inventory row, creates `order_reserved` ledger records, and rolls back fully if stock is unavailable. |
| GAP-04: no delivery-slot discovery | **Closed** | Public `GET /v1/delivery-slots` returns only active, future slots with capacity. |
| GAP-05: delivery capacity is not released | **Closed for cancellation, admin rejection, and payment-expiry sweep** | All three paths use an exact-once reservation-release action that returns stock and capacity in the same transaction. |
| GAP-12: inconsistent delivery-slot response field | **Closed** | `deliverySlotId` is now always represented in order payloads and is eagerly loaded in API order paths. |
| GAP-01: full payment lifecycle | **Deferred by design** | Safe confirmation gating is implemented; provider initiation, signed webhooks, capture, refund, reconciliation, and retry remain a separate payment-integration release. |

## New and Changed API Behavior

| API surface | Behavior |
|---|---|
| `GET /v1/delivery-slots?from=YYYY-MM-DD&to=YYYY-MM-DD` | Public endpoint returning bookable future slots ordered by start time. The range defaults to 14 days and is capped at 31 days. Each response contains ID, start/end time, timezone, fee, capacity, booked count, and remaining capacity. |
| `POST /v1/orders` | Locks the selected delivery slot and all requested inventory rows in a deterministic order. It increments slot capacity and inventory reservations only if all rows are currently available; otherwise the whole transaction rolls back with `422 insufficient-stock`. |
| `POST /v1/orders/{orderId}/cancel` | Cancels eligible orders and releases associated stock/capacity exactly once in the same database transaction. |
| `POST /v1/admin/orders/{orderId}/advance` | Returns `409 payment-not-authorized` when `confirm` is requested for a `card` or `wallet` order whose payment status is neither `authorized` nor `captured`. Reject/cancel releases reserved resources transactionally. |
| `orders:release-expired-reservations` | Cancels expired `pending_payment` reservations, marks payment failed, releases resources, and writes order history. The command is registered to run every minute in the Laravel scheduler. |
| Order resources | `deliverySlotId` is present consistently for list, detail, placement/replay, cancellation, and admin-transition responses. |

## Reservation Accounting Design

Checkout creates immutable order-item snapshots first, then reserves the authoritative live resources in the same transaction. The shared `ManageOrderReservation` action locks the delivery slot, locks inventory rows ordered by `product_id`, checks current capacity/availability, increments `booked_count` and `quantity_reserved`, writes an inventory ledger record for every line, and finally records `resources_reserved_at` on the order.

The `resources_reserved_at` marker distinguishes newly accounted reservations from pre-migration legacy orders. This is important for safety: a legacy row may have increased delivery-slot capacity without ever increasing `quantity_reserved`; a cancellation of that row may release a slot but must never subtract stock that it did not reserve. For new rows, clearing `reservation_expires_at` is the durable exact-once release marker. A cancellation, admin rejection, or overlapping expiry sweep cannot release the same inventory twice because each operation first locks and rechecks the order.

## Deployment Steps

Apply the migration before enabling this release. The scheduler registration alone does not cause periodic work on a production host; the host must run Laravel’s scheduler.

| Step | Command or configuration | Acceptance criterion |
|---|---|---|
| 1 | `php artisan migrate --force` | Migration `2026_08_13_000003_add_resources_reserved_at_to_orders` is shown as **Ran**. |
| 2 | Run `php artisan schedule:run` every minute through the host scheduler, or keep `php artisan schedule:work` supervised in a persistent application process. | `php artisan schedule:list` shows `orders:release-expired-reservations --limit=100` with a one-minute cadence. |
| 3 | Deploy with `php artisan optimize` after production environment variables are configured. | Configuration, route, event, and view caches compile successfully. |
| 4 | Reconcile historical pending orders created before this migration. | Operations identifies old reservations and chooses whether to cancel/release or manually fulfil them; the code safely avoids subtracting unreserved legacy stock. |

## Validation Record

The full automated suite passed with **310 tests and 1,088 assertions**. Targeted coverage now confirms stock reservation at checkout, rollback on stale stock, idempotent release after customer cancellation, release after admin rejection, expiry-command release without duplicate adjustment rows, public slot filtering/range validation, payment authorization gating through both JSON admin and Telegram paths, and the cash-on-delivery exception.

Style verification passed with `vendor/bin/pint --test`. The new migration applied successfully in the local MySQL validation environment. The production optimization cache build and the configured schedule list also completed successfully.

## Deliberately Deferred Follow-Up Releases

The remaining payment lifecycle remains the highest functional follow-up. Select a payment provider before implementation, then add provider-scoped payment-intent creation, signed raw-body webhook verification, deduplicated event persistence, reconciliation, capture/refund actions, and customer retry/recovery behavior. The next operational release should add per-line pick/substitute/unavailable/finalize commands so actual-weight pricing and stock consumption can be completed before dispatch.

Administrative slot, product, category, image, and inventory-adjustment APIs, verified Telegram linking, session management, profile controls, quote/preflight, and reporting remain separate additions. Keeping them out of this release preserves a small, reviewable security boundary around the immediate unsafe checkout behaviors.

## Reference

[1] *Grocerly API Endpoint Gap Analysis*, supplied as `Grocerly_API_Endpoint_Gap_Analysis.pdf`, 13 August 2026.
