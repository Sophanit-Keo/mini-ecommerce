# Grocerly API Reference

Base URL: `/v1`. All request/response bodies are JSON. Field names are `camelCase`.

## Authentication

Bearer token auth via Sanctum access tokens, backed by a rotating refresh token.

- Access token lifetime: 900s (15 min) — `API_ACCESS_TOKEN_TTL`
- Refresh token lifetime: 2,592,000s (30 days) — `API_REFRESH_TOKEN_TTL`, single-use
- Send `Authorization: Bearer {accessToken}` on authenticated routes
- Reusing an already-rotated refresh token revokes the entire token chain (breach detection) — treat this as "log the user out everywhere and force re-login"

### POST /v1/auth/register

Rate limit: `auth` (5/min).

Request:
```json
{
  "email": "alice@example.com",
  "password": "correct-horse-battery-staple",
  "fullName": "Alice Nguyen",
  "phone": "+14155550142"
}
```
| Field | Rules |
|---|---|
| `email` | required, valid email (RFC), max 255 |
| `password` | required, string, max 128, must meet default password policy |
| `fullName` | required, string, 1-120 |
| `phone` | nullable, max 32 |

Response `201`:
```json
{
  "accessToken": "...",
  "refreshToken": "...",
  "expiresIn": 900,
  "tokenType": "Bearer",
  "user": { "id": "...", "email": "alice@example.com", "fullName": "Alice Nguyen", "phone": "+14155550142", "role": "customer" }
}
```

### POST /v1/auth/login

Rate limit: `auth` (5/min).

Request: `{ "email": "alice@example.com", "password": "..." }`

Response `200`: same shape as register. Wrong credentials → `401` (see [Errors](#errors)).

### POST /v1/auth/refresh

No auth header required — the refresh token itself is the credential.

Request: `{ "refreshToken": "..." }`

Response `200`: new `{accessToken, refreshToken, expiresIn, tokenType, user}` — the returned `refreshToken` is a **new** value; the old one is now invalid.

### POST /v1/auth/logout

Requires `Authorization: Bearer {accessToken}`.

Request: `{ "refreshToken": "..." }`

Response: `204 No Content`.

### GET /v1/auth/me

Requires `Authorization: Bearer {accessToken}`.

Response `200`: `{ "id": "...", "email": "...", "fullName": "...", "phone": "...", "role": "customer" }`

### POST /v1/auth/password/forgot

Rate limit: `auth` (5/min).

Request: `{ "email": "alice@example.com" }`

Response `202` **always**, whether or not the address has an account — this is deliberate: a
forgot-password endpoint answering differently for a known vs. unknown email is an
account-enumeration oracle, and this is directly unauthenticated so there's no other signal to
weigh it against.
```json
{ "message": "If an account exists for that email address, a reset link has been sent." }
```

The app has no web pages of its own; the reset link a real account receives points at
`API_FRONTEND_URL` (a SPA/mobile client) with `token` and `email` query parameters for that
client to read and post to the endpoint below. `MAIL_MAILER=log` by default, so in local
development the link can be read out of `storage/logs/laravel.log`.

### POST /v1/auth/password/reset

Rate limit: `auth` (5/min).

Request:
```json
{ "email": "alice@example.com", "token": "...", "password": "new-correct-horse-battery" }
```

Response: `204 No Content`. Also revokes every refresh token and access token already issued
to the account — a password reset forces every other session to re-authenticate.

An invalid or expired `token`/`email` pair answers `422 validation-failed` without indicating
which part was wrong.

### POST /v1/auth/email/verification-notification

Requires `Authorization: Bearer {accessToken}`. Rate limit: `auth` (5/min).

No request body. Sends a fresh 6-digit verification code by email to the current user,
replacing any previously issued code (a resend invalidates the old code). The code expires
after 10 minutes.

Response: `204 No Content` if the account is already verified (nothing is sent), `202
Accepted` if a new code was sent.

### POST /v1/auth/email/verify

Requires `Authorization: Bearer {accessToken}`. Rate limit: `auth` (5/min).

The code is checked against the row for the authenticated caller only — never looked up by
an identifier in the request body — since a 6-digit code is guessable in a bounded number of
attempts and must be tied to a specific, already-authenticated account.

Request: `{ "code": "123456" }` — the 6-digit code emailed by
`POST /v1/auth/email/verification-notification`.

Response `200`: the updated `UserResource`. A wrong, expired, or already-used code answers
`422 validation-failed` without indicating which of those it was.

A new account's registration response already implies a verification email is on its way
(sent as a fire-and-forget side effect of `POST /v1/auth/register` — it never delays that
response).

---

## Catalog

Public, no auth required. Rate limit: `catalog` (60/min).

### GET /v1/categories

Query params: `tree` (bool, optional) — return nested children instead of a flat list.

Response `200`:
```json
{
  "data": [
    {
      "id": "...", "name": "Produce", "slug": "produce", "parentId": null,
      "description": "...", "imageUrl": "...", "position": 1,
      "productCount": 42, "children": []
    }
  ]
}
```

### GET /v1/categories/{categoryId}

Response `200`: single `CategoryResource` object (same shape as above, not wrapped in `data`).

### GET /v1/products

Query params:

| Param | Rules |
|---|---|
| `q` | nullable, 1-100 chars — full-text search |
| `categoryId` | nullable, uuid |
| `inStock` | nullable, boolean |
| `soldBy` | nullable, enum (`SoldBy`) |
| `sort` | nullable, one of `relevance \| price_asc \| price_desc \| newest`, default `relevance` |
| `cursor` | nullable, opaque string from a previous response's `page.nextCursor` |
| `limit` | nullable, int 1-100, default 24 |

Example: `GET /v1/products?q=organic+apples&sort=price_asc&limit=2`

Response `200`:
```json
{
  "data": [
    {
      "id": "...", "sku": "APL-ORG-01", "name": "Organic Apples", "slug": "organic-apples",
      "brand": "Farmstand", "soldBy": "weight", "unitLabel": "kg",
      "effectivePrice": "1.79", "price": null, "pricePerKg": "1.79",
      "compareAtPrice": null, "averageWeightKg": "0.180",
      "primaryImageUrl": "...", "inStock": true, "categoryId": "..."
    }
  ],
  "page": { "hasMore": true, "nextCursor": "eyJ..." }
}
```
Pass `nextCursor` as the `cursor` param to fetch the next page; an invalid/expired cursor returns `400`. Money and weight fields are returned as **strings**, not numbers, to avoid float precision loss.

### GET /v1/products/{productId}

Response `200`: a `ProductResource` — everything in the summary shape above, plus:
`description, weightTolerancePct, minOrderQuantity, maxOrderQuantity, images (ProductImageResource[]), availableQuantity, category`.

### GET /v1/products/{productId}/substitutes

Query params: `limit` (int, 1-20, default 5).

Response `200`: `{ "data": [...ProductSummaryResource] }` — ranked, same-category, in-stock, closest in price. No `page` block (not paginated).

### PATCH /v1/products/{productId}

Admin-only. Requires `Authorization: Bearer {accessToken}` for a user with `role: admin` — any other authenticated caller gets `403 forbidden`. Rate limit: `authenticated` (120/min).

Partial update — send only the fields that changed.

Request:
```json
{ "name": "Organic Gala Apples", "price": "1.99", "isActive": true }
```

| Field | Rules |
|---|---|
| `categoryId` | optional, uuid, must reference an existing category |
| `sku` | optional, max 64 |
| `name` | optional, max 200 |
| `slug` | optional, max 220 |
| `brand` | nullable, max 120 |
| `description` | nullable, string |
| `soldBy` | optional, enum (`SoldBy`) |
| `unitLabel` | optional, max 16 |
| `price` | nullable, numeric, >= 0 |
| `pricePerKg` | nullable, numeric, >= 0 |
| `compareAtPrice` | nullable, numeric, >= 0 |
| `averageWeightKg` | nullable, numeric, > 0 |
| `weightTolerancePct` | optional, numeric, 0-100 |
| `minOrderQuantity` | optional, numeric, > 0 |
| `maxOrderQuantity` | nullable, numeric, >= `minOrderQuantity` |
| `isActive` | optional, boolean |

Mirrors `ck_products_pricing_shape` at the application layer: a `unit`-sold product must end up with `price` set and `pricePerKg`/`averageWeightKg` unset; a `weight`-sold product must end up with `pricePerKg` and `averageWeightKg` set and `price` unset. This is checked against the row's *effective* shape after the patch — so switching `soldBy` requires sending the matching pricing fields in the same request — and rejected as `422 validation-failed` before it can hit the database constraint. `effectivePrice`, `skuActive`, and `slugActive` are database-generated columns and can never be written directly.

Response `200`: updated `ProductResource`. `404` if the product doesn't exist (soft-deleted products are also not found).

### DELETE /v1/products/{productId}

Admin-only. Requires `Authorization: Bearer {accessToken}` for a user with `role: admin` — any other authenticated caller gets `403 forbidden`. Rate limit: `authenticated` (120/min).

Soft delete — `Product` uses `SoftDeletes`, so the row is retained (order/cart lines that already reference it keep their data) but the product drops out of every default query scope, including the public catalogue and search. Existing cart lines referencing the product are left as-is; checkout still re-prices from the live catalogue at that point, so a soft-deleted product simply can no longer be checked out.

Response `204`: no content. `404` if the product doesn't exist or is already soft-deleted.

---

## Addresses

Requires `Authorization: Bearer {accessToken}`. Rate limit: `authenticated` (120/min). All operations are scoped to the authenticated user — you can never see or modify another user's address; a mismatched/missing `addressId` returns `404`, not `403`.

### GET /v1/addresses

Response `200`: `{ "data": [...AddressResource] }`, ordered default-first then newest-first.

### POST /v1/addresses

Request:
```json
{
  "label": "Home",
  "recipientName": "Alice Nguyen",
  "phone": "+14155550142",
  "line1": "1420 Sutter St",
  "line2": "Apt 3B",
  "city": "San Francisco",
  "region": "CA",
  "postalCode": "94109",
  "countryCode": "US",
  "latitude": 37.787123,
  "longitude": -122.421234,
  "deliveryNotes": "Ring buzzer 3B",
  "isDefault": true
}
```

| Field | Rules |
|---|---|
| `label` | nullable, max 40 |
| `recipientName` | required, max 120 |
| `phone` | required, max 32 |
| `line1` | required, max 180 |
| `line2` | nullable, max 180 |
| `city` | required, max 80 |
| `region` | nullable, max 80 |
| `postalCode` | nullable, max 20 |
| `countryCode` | required, exactly 2 chars |
| `latitude` / `longitude` | nullable, numeric, must both be present or both absent |
| `deliveryNotes` | nullable, max 500 |
| `isDefault` | optional, boolean |

Setting `isDefault: true` atomically un-defaults any previous default address.

Response `201`:
```json
{
  "id": "...", "label": "Home", "recipientName": "Alice Nguyen", "phone": "+14155550142",
  "line1": "1420 Sutter St", "line2": "Apt 3B", "city": "San Francisco", "region": "CA",
  "postalCode": "94109", "countryCode": "US",
  "latitude": "37.7871230", "longitude": "-122.4212340",
  "deliveryNotes": "Ring buzzer 3B", "isDefault": true, "createdAt": "2026-07-28T12:00:00.000000Z"
}
```

### GET /v1/addresses/{addressId}

Response `200`: single `AddressResource`. `404` if it doesn't exist or belongs to another user.

### PATCH /v1/addresses/{addressId}

Same fields as POST, all optional (partial update). Response `200`: updated `AddressResource`.

### DELETE /v1/addresses/{addressId}

Soft-deletes the address. Response: `204 No Content`.

---

## Cart

Requires `Authorization: Bearer {accessToken}`. Rate limit: `authenticated` (120/min). Every operation is scoped to the caller's own active cart; a mismatched/missing `cartItemId` returns `404`, not `403`.

### GET /v1/cart

Returns the caller's active cart, lazily creating one if none exists. Always `200`, even on the request that creates it — a GET must not surprise a client with a `201` depending on whether it happened to be first.

Response `200`:
```json
{
  "id": "...",
  "status": "active",
  "currency": "USD",
  "items": [
    {
      "id": "...",
      "productId": "...",
      "product": { "...": "ProductSummaryResource" },
      "quantity": "2.000",
      "unitPriceSnapshot": "4.49",
      "lineTotal": "8.98",
      "substitutionPreference": "similar",
      "note": null
    }
  ]
}
```

### POST /v1/cart/items

Request:
```json
{ "productId": "...", "quantity": 2, "substitutionPreference": "similar", "note": "Ripe please" }
```

| Field | Rules |
|---|---|
| `productId` | required, uuid |
| `quantity` | required, numeric, > 0 |
| `substitutionPreference` | nullable, enum (`SubstitutionPreference`), default `similar` |
| `note` | nullable, max 280 |

If the product is already in the cart, its quantity is increased rather than duplicating the line (`uq_cart_items_cart_product`). `unitPriceSnapshot` is taken from the live catalogue price at add time. Quantity is checked against the product's own order bounds (`422 quantity-out-of-range`) and, softly, against available stock (`422 insufficient-stock`) — the latter does not reserve anything; it just stops an obviously-wrong add.

Response `201`: a `CartItem`.

### PATCH /v1/cart/items/{cartItemId}

Partial update — any of `quantity`, `substitutionPreference`, `note`. Response `200`: updated `CartItem`.

### DELETE /v1/cart/items/{cartItemId}

Response: `204 No Content`.

---

## Wishlist

Requires `Authorization: Bearer {accessToken}`. Rate limit: `authenticated` (120/min). A saved-products list per user — no quantities, no checkout integration. Every operation is scoped to the caller's own wishlist; a mismatched/missing `wishlistItemId` returns `404`, not `403`.

### GET /v1/wishlist

Response `200`: `{ "data": [...WishlistItem] }`, newest-first.

```json
{
  "data": [
    {
      "id": "...",
      "productId": "...",
      "product": { "...": "ProductSummaryResource" },
      "createdAt": "2026-07-28T12:00:00.000000Z"
    }
  ]
}
```

### POST /v1/wishlist/items

Request:
```json
{ "productId": "..." }
```

| Field | Rules |
|---|---|
| `productId` | required, uuid |

`404 not-found` if the product doesn't exist. Saving a product that is already on the wishlist is idempotent (`uq_wishlist_items_user_product`): the existing item is returned with `200`, not a `409` — a wishlist "save" button has nothing meaningful to conflict with, so a second save is a no-op rather than an error.

Response `201` (new item) or `200` (already saved): a `WishlistItem`.

### DELETE /v1/wishlist/items/{wishlistItemId}

Response: `204 No Content`. Deleting the underlying product also removes the wishlist item (`fk_wishlist_items_product` cascades) — a wishlist points live at the catalogue rather than snapshotting it.

---

## Delivery slots

### GET /v1/delivery-slots

Public, rate-limited catalogue discovery for checkout delivery windows. It returns only slots that are active, begin in the future, fall in the requested date range, and still have remaining capacity. This is a discovery response, not a reservation: checkout locks and validates the slot again.

| Query parameter | Rules |
|---|---|
| `from` | optional `YYYY-MM-DD`; defaults to today |
| `to` | optional `YYYY-MM-DD`; defaults to 14 days after `from`; must be on/after `from` and no more than 31 days later |

Response `200`: `{ "data": [DeliverySlot, ...] }`, ordered by `startsAt`. A `DeliverySlot` contains `id`, `startsAt`, `endsAt`, `timezone`, `fee`, `capacity`, `bookedCount`, and `remainingCapacity`.

---

## Checkout and orders

Requires `Authorization: Bearer {accessToken}`. Rate limit: `authenticated` (120/min). All operations are scoped to the caller's own orders; a mismatched/missing `orderId` returns `404`, not `403`.

### POST /v1/orders

Places an order from the caller's active cart.

Request:
```json
{
  "addressId": "...",
  "deliverySlotId": "...",
  "paymentMethod": "card",
  "customerNote": "Leave at the door",
  "idempotencyKey": "a client-generated opaque string, unique per checkout attempt"
}
```

| Field | Rules |
|---|---|
| `addressId` | required, uuid, must belong to the caller |
| `deliverySlotId` | required, uuid, must refer to a future active slot with capacity; rechecked under lock at checkout |
| `paymentMethod` | required, enum (`PaymentMethod`) |
| `customerNote` | nullable, max 500 |
| `idempotencyKey` | required, max 64 |

Replaying the same `idempotencyKey` for the **same caller** returns the order already created (`201`, same body) rather than erroring or double-booking. The database constraint `uq_orders_user_idempotency_key` closes the race on the pair `(user_id, idempotency_key)`, so an unrelated customer using the same opaque key cannot block another customer's checkout.

The delivery address is copied into `deliveryAddressSnapshot` at placement, so a later edit to the address never rewrites order history. Each cart line is copied into an order item with product name/SKU/brand/pricing snapshotted at checkout. `deliveryFee` comes from the chosen slot; `taxEstimated` and `discountTotal` are `0.00` in the current implementation. Checkout locks the selected slot and every line's live inventory in one transaction, atomically increments `bookedCount`, increments `quantityReserved`, and writes an `order_reserved` inventory ledger entry for each line. If any requested quantity is no longer available, the complete transaction rolls back and the cart remains active. The cart is marked `converted` only after all resources are reserved.

Errors: `400` empty cart, `404` address not owned by caller, `409 slot-unavailable` (full, inactive, past, or nonexistent), `422 insufficient-stock` or validation failure.

Response `201`: an `Order`, including `items` and an initial `statusHistory` entry (`pending_payment`).

### GET /v1/orders

Response `200`: `{ "data": [...Order (summary shape, no items/statusHistory)], "page": { currentPage, lastPage, total } }`, newest first (`placedAt` descending). Every Order shape always includes `deliverySlotId` (or `null` for legacy/manual rows); list, detail, create, cancellation, and admin responses load it consistently.

### GET /v1/orders/{orderId}

Response `200`: full `Order`, including `items` and `statusHistory` (eager-loaded).

### POST /v1/orders/{orderId}/cancel

Customer-initiated cancellation. Only allowed while the order is `pending_payment` or `confirmed` — once picking has started, the store has to intervene instead.

Request: `{ "cancellationReason": "Changed my mind" }`

Sets `status = cancelled`, `cancelledAt` to now, and appends a `statusHistory` entry. For a reserved checkout it atomically returns the delivery capacity and all reserved stock, writing `order_released` ledger entries exactly once. Returns `409 invalid-status-transition` if the order is already in a non-cancellable state.

Response `200`: updated `Order`.

---

## Admin order fulfilment

Admin-only. Requires `Authorization: Bearer {accessToken}` for a user with `role: admin`. Rate limit: `authenticated` (120/min). Fulfilment can also be driven from Telegram (see below) — both paths call the same underlying action, so they can never disagree about which transitions are legal.

### POST /v1/admin/telegram/link

Registers the calling admin's Telegram chat id, so the bot knows where to send order notifications and which chat is allowed to act on them. The admin gets their own chat id from Telegram (e.g. `@userinfobot`, or by messaging the bot first) — this endpoint does not verify the chat exists.

Request: `{ "chatId": "123456789" }`

Response `200`: `{ "telegramChatId": "123456789" }`.

### POST /v1/admin/orders/{orderId}/advance

Steps an order through a simplified four-stage admin flow: `confirm` (`pending_payment` → `confirmed`), `prepare` (`confirmed` → `picking`), `deliver` (`picking` → `out_for_delivery`), `complete` (`out_for_delivery` → `delivered`). Also accepts `reject`/`cancel` (`pending_payment`, `confirmed`, or `picking` → `cancelled`). This flow deliberately skips the `packed` status — `packed` remains a valid order status but is not exposed as an admin action here. A `card` or `wallet` order may only be confirmed after a server-side payment integration has set `paymentStatus` to `authorized` or `captured`; cash-on-delivery confirmation follows the explicit operational exception.

Request:
```json
{ "action": "confirm", "reason": "Optional; used as the cancellation reason for reject/cancel" }
```

| Field | Rules |
|---|---|
| `action` | required, one of `confirm \| prepare \| deliver \| complete \| reject \| cancel` |
| `reason` | nullable, max 280 |

`404` if no such order exists. An out-of-sequence action (e.g. `deliver` on a `pending_payment` order) is `409 invalid-status-transition`. Confirming a pending card/wallet payment is `409 payment-not-authorized`. Rejecting a reserved order releases delivery capacity and inventory in the same database transaction.

Response `200`: updated `Order`, including a new `statusHistory` entry attributed to the acting admin.

### Telegram bot

`POST /v1/telegram/webhook` receives `callback_query` updates from Telegram's inline order-action buttons. It is not a client-facing endpoint: unauthenticated (Telegram cannot send a Bearer token), verified instead via the `X-Telegram-Bot-Api-Secret-Token` header against `TELEGRAM_WEBHOOK_SECRET`. Register it with Telegram once per environment via `php artisan telegram:set-webhook {url}`.

On order placement, every admin with a linked Telegram chat receives a message with the order summary and "Confirm"/"Reject" buttons. Tapping a button calls the same transition logic as `POST /v1/admin/orders/{orderId}/advance` above.

---

## Notifications

Requires `Authorization: Bearer {accessToken}`. Rate limit: `authenticated` (120/min). An in-app inbox, scoped to the caller's own notifications; a mismatched/missing `notificationId` returns `404`, not `403`.

A `Notification` is recorded automatically whenever an order the caller owns changes status — no matter which code path made the change (customer-initiated cancellation, an admin/ops action, or anything else). This is implemented as an Eloquent observer on `Order` (`updated`, when `status` is dirty), so any future status-changing code path produces notifications with no extra wiring.

### GET /v1/notifications

| Query param | Rules |
|---|---|
| `unreadOnly` | boolean, default `false` — filter to notifications with `readAt = null` |
| `perPage` | integer, 1–100, default 20 |

Response `200`: `{ "data": [...Notification], "page": { currentPage, lastPage, total } }`, newest first (`createdAt` descending).

### POST /v1/notifications/{notificationId}/read

Marks a single notification as read (`readAt = now()`). Idempotent — marking an already-read notification succeeds without error.

Response `200`: updated `Notification`.

### POST /v1/notifications/read-all

Marks every unread notification belonging to the caller as read, in one query.

Response: `204 No Content`.

---

## Errors

All errors are returned as [RFC 9457 problem details](https://www.rfc-editor.org/rfc/rfc9457), `Content-Type: application/problem+json`:

```json
{
  "type": "https://api.grocerly.example/problems/{problem-type}",
  "title": "Human-readable title",
  "status": 422,
  "detail": "Optional longer explanation",
  "instance": "optional request-specific identifier"
}
```

`type` base URI is configurable via `API_PROBLEM_BASE_URI`.

| Status | `type` suffix | Notes |
|---|---|---|
| 400 | `bad-request` | e.g. malformed pagination `cursor` |
| 401 | `invalid-credentials` | wrong email/password on login |
| 401 | `unauthorized` | missing/expired access token |
| 403 | `forbidden` | authenticated but not permitted |
| 404 | `not-found` | resource missing or not owned by caller — same response either way |
| 422 | `validation-failed` | adds an `errors: [{ field, message }]` array |
| 429 | `rate-limited` | adds `retryAfter` (seconds); response also carries a `Retry-After` header |

Successful responses on rate-limited routes include `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers.

**Validation error example:**
```json
{
  "type": "https://api.grocerly.example/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "errors": [
    { "field": "email", "message": "The email field is required." }
  ]
}
```

**401 example:**
```json
{
  "type": "https://api.grocerly.example/problems/invalid-credentials",
  "title": "Invalid credentials",
  "status": 401,
  "detail": "The email address or password is incorrect."
}
```

---

## Not yet available

The following are modeled in the database but have **no API endpoints yet**: browsing/listing delivery slots, detailed picking/substitution workflows (line-level `order_items`/`order_item_substitutions` status), category/inventory write operations (admin), and admin product creation (`PATCH`/`DELETE /v1/products/{productId}` cover update and soft delete only — there is no `POST /v1/products` yet). Coarse-grained admin order fulfilment (confirm/prepare/deliver/complete/reject) is covered above, via the JSON API and the Telegram bot. See the main [README](../README.md#whats-not-done-yet) for the full list.
