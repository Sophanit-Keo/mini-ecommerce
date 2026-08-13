# Release 3: Administration Self-Service Implementation

**Project:** Grocerly / mini-ecommerce API  
**Release:** 3 — catalogue, inventory, delivery-slot, media, and audit administration  
**Status:** Implemented and verified  
**Author:** Manus AI

## Delivery summary

Release 3 replaces seed/database-only operations with secure administration APIs. It adds the operational controls needed to create and maintain catalogue data, adjust inventory through an attributable ledger, manage delivery capacity safely, and inspect an immutable record of administrative changes.

| Domain | Delivered capability | Safeguard |
|---|---|---|
| Products | Paginated admin listing and product creation with an inventory row created atomically. | Validates pricing shape and retains public active-only catalogue behavior. |
| Categories | Paginated listing, creation, and update. | Rejects invalid parents and parent/descendant cycles. |
| Product media | Create, set primary, and delete image commands. | Row locks and database uniqueness retain a single primary image and unique positions. |
| Inventory | Paginated inventory/ledger views and typed adjustments. | No direct stock write; locked adjustment cannot reduce on-hand below reserved stock. |
| Delivery slots | Paginated slot listing plus create/update lifecycle controls. | Capacity cannot drop below active bookings; booked windows cannot be rescheduled. |
| Auditability | Immutable paginated administration audit feed. | Stores only sanitized before/after snapshots; omits secrets and provider payloads. |

## Administration API

All new operations require the existing `auth:sanctum`, active-account, administrator, and authenticated-throttle middleware chain. Each listing validates `perPage` between 1 and 100.

| Endpoint | Operational behavior |
|---|---|
| `GET/POST /v1/admin/products` | Lists all products or creates a validated product and inventory row in one transaction. |
| `GET/POST/PATCH /v1/admin/categories` | Lists, creates, and updates categories; patch is addressed by category UUID. |
| `POST /v1/admin/products/{productId}/images` | Creates an image; the first image is automatically primary. |
| `POST /v1/admin/products/{productId}/images/{imageId}/primary` | Makes exactly one image primary. |
| `DELETE /v1/admin/products/{productId}/images/{imageId}` | Removes an image and promotes another image when necessary. |
| `GET /v1/admin/inventory` | Lists inventory with on-hand, reserved, available, and low-stock state. |
| `POST/GET /v1/admin/inventory/{productId}/adjustments` | Posts a ledger-backed adjustment or lists the product adjustment history. |
| `GET/POST/PATCH /v1/admin/delivery-slots` | Lists, creates, and updates delivery slots. |
| `GET /v1/admin/audit-events` | Lists sanitized administration audit records with action, actor, and entity filters. |

The detailed API reference is maintained in [`API.md`](API.md). Existing public endpoints remain unchanged and continue to hide inactive catalogue records and unavailable delivery slots.

## Safety and accounting model

Inventory is managed through the existing `inventory_adjustments` ledger. Administrative adjustment requests accept only `restock`, `shrinkage`, `correction`, and `return`; order reservation, release, and fulfilment reasons remain internal workflow events. The service locks the inventory row, calculates the proposed on-hand value to three decimal places, rejects a value below `quantity_reserved`, then writes both the state change and ledger record in one transaction.

> **Invariant:** `quantity_on_hand` may never become less than `quantity_reserved`. This prevents an operational adjustment from making previously accepted checkout reservations impossible to fulfil.

Product media operations lock the parent product and its image rows before changing primary state. The database remains the final backstop through the unique primary-image and per-product image-position constraints. Delivery-slot updates similarly lock the slot; an administrator can deactivate a slot, but cannot reduce capacity below its current booked count or move a window with live bookings.

Every Release 3 mutation writes `admin_audit_events`. Records identify the actor, action, entity, optional request correlation ID, timestamp, and a deliberately limited before/after snapshot. The audit logger never receives or stores tokens, credentials, passwords, raw Bakong data, or unrelated customer data.

## Deployment procedure

Deploy code and the audit-event migration together:

```bash
php artisan migrate --force
php artisan optimize
```

Verify the administrative surface after deployment:

```bash
php artisan route:list --path=admin
```

Operations should use inventory adjustments instead of editing database rows. Before lowering stock, staff must account for active reservations. Before changing a delivery slot, staff must inspect `bookedCount`; capacity decreases below that value and rescheduling booked slots are intentionally rejected.

## Verification record

| Validation | Result |
|---|---|
| Release 3 administration workflow coverage | 5 end-to-end tests covering product/inventory creation, media primary rules, ledger accounting, slot invariants, audit filtering, and admin authorization. |
| Public catalogue compatibility | Public category/product cache serialization, image UUIDs, response shapes, and fixed-query behavior verified. |
| Full automated suite | **327 tests, 1,234 assertions passing**. |
| Formatting | Project formatter passed for all 230 PHP files. |
| Deployment readiness | Configuration, route, and view cache compilation succeeded. |
| Migration | `2026_08_13_000006_create_admin_audit_events_table` applied successfully. |

## Remaining scope

Release 4 remains responsible for customer-facing operations: session/device management, logout-all, password change, verified Telegram ownership linking, repeat-order/cart restoration, profile/privacy controls, notification preferences, and real-time delivery tracking. Release 3 deliberately does not expand customer permissions or expose the administrative audit feed to non-administrators.
