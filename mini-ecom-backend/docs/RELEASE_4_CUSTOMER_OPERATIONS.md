# Release 4: Customer Operations Implementation

**Project:** Grocerly / mini-ecommerce API  
**Status:** Implemented and verified

Release 4 strengthens the customer account boundary and repeat-order workflow. It provides self-service profile and notification preferences, safe session visibility/revocation, current-password-gated password changes, account closure protection, a live-validated reorder action, and a one-time Telegram ownership-link challenge.

| Capability | Endpoint(s) | Security control |
|---|---|---|
| Profile | `GET/PATCH /v1/account/profile` | Only the authenticated customer may read/update their own full name and phone. |
| Sessions | `GET /v1/account/sessions`, `POST /v1/account/logout-all` | Returns no token material; only expiry/revocation, user-agent, and safely formatted IP metadata. Logout-all revokes refresh sessions and Sanctum tokens. |
| Password | `POST /v1/account/change-password` | Requires current password and confirmation; revokes all token sessions after change. |
| Notification preferences | `GET/PATCH /v1/account/notification-preferences` | Stores only supported customer channel preferences. |
| Account closure | `POST /v1/account/close` | Requires password and rejects closure while an operational order remains active. |
| Repeat order | `POST /v1/orders/{orderId}/reorder` | Owner-scoped; locks active cart/products, revalidates active stock, and does not reserve stock or delivery capacity. |
| Telegram linking | `POST /v1/account/telegram-link-challenge` | Stores only a SHA-256 challenge hash. Telegram webhook consumes the code once, before expiry, and binds the actual sender chat ID. |

## Deployment

```bash
php artisan migrate --force
php artisan optimize
```

The Telegram webhook must remain configured with `TELEGRAM_WEBHOOK_SECRET`. Customers generate a short-lived code from the account endpoint and send `/link CODE` to the official bot; the webhook is the only path that commits a customer chat ID.

## Verification

The complete suite passed with **330 tests and 1,255 assertions**. The Release 4 account suite covers profile/preferences, safe session metadata, password-session revocation, logout-all, active-order account-closure blocking, and successful closure after cancellation.

## Deferred integration

Real-time delivery tracking requires an approved carrier/location data provider and customer consent/retention policy. It is intentionally not simulated or exposed without that operational integration.
