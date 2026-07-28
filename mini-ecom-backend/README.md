# Mini-Ecommerce Backend (Grocerly API)

A Laravel REST API for a grocery-delivery style e-commerce platform. Built with token authentication, cursor-paginated product search, and a rigorously constrained database schema (CHECK constraints, generated columns, audit trails).

## Tech Stack

- **PHP** ^8.3, **Laravel Framework** ^13.8
- **Auth**: Laravel Sanctum ^4.0 + a custom refresh-token rotation layer
- **Database**: MySQL, Eloquent ORM
- **Testing**: Pest ^4.7 (with pest-plugin-laravel)
- **Dev tooling**: Laravel Boost, Pint (formatter), Pail (log viewer)
- **Frontend build**: Vite + Tailwind CSS 4 (minimal — only backs a placeholder welcome page, not part of the API)
- Queue driver: `database` · Cache driver: `database`

## Project Structure

```
app/
  Http/
    Controllers/Api/V1/   # Versioned API controllers (Auth, Category, Product, Address)
    Requests/             # Form request validation classes
    Resources/            # API resource transformers (JSON shaping)
  Actions/Auth/           # Single-purpose auth actions (Register, Authenticate, IssueTokenPair,
                          # RotateRefreshToken, RevokeRefreshToken)
  Models/                 # Eloquent models
  Enums/                  # Backed enums (OrderStatus, PaymentStatus, PaymentMethod, UserRole, ...)
  Exceptions/
    ProblemException.php  # RFC 9457 "problem details" error responses
  Support/                # Value objects (Money, CursorPage, SortKey, TokenPair, ...)
database/
  migrations/             # Schema with DB-level CHECK constraints & generated columns
  factories/              # Present for nearly every model
  seeders/
routes/
  api.php                 # All routes, versioned under /v1
tests/
  Feature/, Unit/, Search/ # Pest tests
```

## Setup

```bash
composer setup   # installs deps, copies .env, generates app key, migrates, npm install/build
```

Or manually:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

### Running

```bash
composer run dev   # runs php artisan serve + queue:listen + npm run dev concurrently
```

### Testing

```bash
composer test
# or
php artisan test --compact
php artisan test --filter=SomeTest
```

## Environment Variables

Standard Laravel vars in `.env.example` (`APP_*`, `DB_*` for MySQL, `SESSION_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `MAIL_*`, `REDIS_*`).

Custom API config (`config/api.php`, env-driven — not yet listed in `.env.example`):

| Variable | Default | Purpose |
|---|---|---|
| `API_PROBLEM_BASE_URI` | `https://api.grocerly.example/problems` | Base URI for RFC 9457 problem-detail error types |
| `API_ACCESS_TOKEN_TTL` | 900 (15 min) | Access token lifetime, seconds |
| `API_REFRESH_TOKEN_TTL` | 2,592,000 (30 days) | Refresh token lifetime, seconds |
| `API_RATE_LIMIT_AUTH` | 5 | Requests/min for auth endpoints |
| `API_RATE_LIMIT_AUTHENTICATED` | 120 | Requests/min for authenticated endpoints |
| `API_RATE_LIMIT_CATALOG` | 60 | Requests/min for public catalog endpoints |

## API Reference

All routes are prefixed with `/v1`.

### Auth (`/v1/auth`)
| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/auth/register` | — | throttled (`auth`) |
| POST | `/auth/login` | — | throttled (`auth`) |
| POST | `/auth/refresh` | — | rotates refresh token |
| POST | `/auth/logout` | Sanctum | |
| GET | `/auth/me` | Sanctum | |

### Catalog (public, throttled `catalog`)
| Method | Path | Notes |
|---|---|---|
| GET | `/categories` | flat or nested |
| GET | `/categories/{categoryId}` | |
| GET | `/products` | search, filters, cursor pagination, sort |
| GET | `/products/{productId}` | |
| GET | `/products/{productId}/substitutes` | same-category, in-stock, price-proximity ranked |

### Addresses (Sanctum, throttled `authenticated`)
| Method | Path | Notes |
|---|---|---|
| GET | `/addresses` | |
| POST | `/addresses` | |
| GET | `/addresses/{addressId}` | |
| PATCH | `/addresses/{addressId}` | |
| DELETE | `/addresses/{addressId}` | |

## What's Done

- **Auth**: register/login/refresh/logout/me with real refresh-token rotation — reusing an already-rotated refresh token revokes the entire token chain (breach detection). Tokens hashed with SHA-256 at rest. Rate-limited via named throttles.
- **Catalog browsing**: category tree, cursor (keyset) pagination for products, MySQL full-text search with a stable relevance-score ordering, filters (category, in-stock, sold-by), sorting (price, newest, relevance), and a substitutes endpoint.
- **Addresses**: full CRUD, IDOR-safe (scoped to the owning user, returns 404 rather than 403 to avoid leaking resource existence), single-default-address enforced transactionally.
- **Error handling**: global RFC 9457 problem-details responses for validation, auth, authorization, not-found, and rate-limit (429) errors.
- **Database schema**: CHECK constraints, generated columns enforcing invariants (one default address, one active cart, unique active SKU/slug under soft deletes), and audit trail tables (`order_status_history`, `inventory_adjustments`, `order_item_substitutions`).
- Meaningful Pest test coverage: DB constraints, generated columns, product search, order reconciliation, Money value object.

## What's Not Done Yet

- **Cart API** — `Cart`/`CartItem` models, migrations, factories, and a seeder exist, but there's no `CartController` or cart routes. Not exposed via the API at all.
- **Order / checkout API** — Order-related models (`Order`, `OrderItem`, `OrderItemSubstitution`, `OrderStatusHistory`) are fully modeled at the DB level (idempotency keys, payment status, estimated vs. final amounts) but there's no controller, no checkout endpoint, and no payment integration.
- **Delivery slots API** — model/migration/factory/seeder exist; no controller or routes.
- **Admin / catalog management** — products, categories, and inventory are read-only via the API. No create/update/delete endpoints for catalog data, and no inventory-adjustment endpoints.
- **`docs/openapi.yaml`** is referenced in code comments as the source of truth for route shapes, but the file doesn't exist in the repo yet.
- **Account management** — no profile update, password reset, or email verification endpoints (the `password_reset_tokens` table exists but is unused).
- **Role-based authorization** — a `UserRole` enum (`customer`, `admin`) exists on the `users` table, but no route or controller currently gates on role. All current authorization is ownership-based only.
- CORS/middleware customization in `bootstrap/app.php` is still the default empty stack.

## Database Schema Overview

- `users` — soft-delete-aware unique active email, role/status enums
- `refresh_tokens` — hashed tokens, rotation chain (`replaced_by_id`)
- `addresses` — geocoordinates, single default per user (generated column + unique index)
- `categories` — self-referencing parent/child, soft-delete-safe unique slug
- `products` — dual pricing (`price` XOR `price_per_kg` + `average_weight_kg`, enforced via CHECK), full-text index on name/brand/description
- `product_images` — one primary image enforced via generated column
- `inventory` — `quantity_available` is a stored generated column (`on_hand - reserved`), CHECK prevents oversell
- `inventory_adjustments` — audit log of stock changes
- `carts` / `cart_items` — guest-or-user ownership CHECK, one active cart per user/guest
- `orders` — estimated vs. final amounts (supports weight-based repricing after picking), idempotency key, full status lifecycle, JSON address snapshot
- `order_items` — product snapshot at order time, picked vs. ordered quantities, substitution status
- `order_item_substitutions` — audit of substitutions
- `order_status_history` — append-only status transition log
- `delivery_slots` — modeled, not yet wired to any endpoint

Money and weight fields consistently use `DECIMAL`, never floats.

## Authentication Details

Token auth via Laravel Sanctum, layered with a custom refresh-token system (`app/Actions/Auth/*`, `refresh_tokens` table):

- Access tokens: short-lived (15 min default)
- Refresh tokens: long-lived (30 days default), single-use — reuse of a rotated token revokes the whole chain
- Refresh tokens stored only as SHA-256 hashes
