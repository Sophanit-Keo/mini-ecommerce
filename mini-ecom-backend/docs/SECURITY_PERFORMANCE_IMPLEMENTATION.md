# Grocery API Security and Performance Implementation Report

**Project:** Grocerly Mini-Ecommerce Backend  
**Prepared by:** Manus AI  
**Implementation branch:** [`hardening/security-and-performance`](https://github.com/Sophanit-Keo/mini-ecommerce/tree/hardening/security-and-performance)  
**Implementation commit:** [`8ba336b`](https://github.com/Sophanit-Keo/mini-ecommerce/commit/8ba336b)  
**Status:** **Implemented, tested, and pushed for review**

## Executive Summary

This review found that the API already had a strong foundation: scoped ownership lookups, hashed refresh tokens, refresh-token rotation, database constraints, eager loading, and cursor pagination were already in place. The implemented work closes the most significant remaining gaps: exposed configuration credentials, unrestricted cross-origin behavior, session persistence after suspension, abuse paths on refresh and verification endpoints, globally scoped checkout idempotency, oversized request bodies, and unbounded page sizes.

The implementation also makes the public catalogue substantially more efficient. Product and category reads now use short-lived, versioned response caching with `ETag` support, while product edits and deletions invalidate the catalogue namespace immediately. Cart writes no longer load every cart item merely to update or remove one row. Price-sorted catalogue queries now have supporting composite indexes, and the application can compile its production route/configuration/view caches successfully.

> **Verification result:** the final suite completed with **302 passing tests and 1,030 assertions**. The production optimization command also completed successfully, confirming that route, configuration, event, and view caches can compile.

| Priority | Result | Main outcome |
|---|---|---|
| **P0 — critical** | Implemented | Removed the committed mail password from the active template, eliminated the cross-tenant idempotency denial-of-service flaw, and prevented suspended accounts from retaining usable sessions. |
| **P1 — high** | Implemented | Added explicit CORS controls, API security headers, credential no-store policies, request-size limits, layered abuse controls, verification-attempt exhaustion, and webhook throttling. |
| **P1 — performance** | Implemented | Added public catalogue caching and ETags, targeted cache invalidation, browse indexes, cart-query reductions, bounded pagination, and deployment cache compatibility. |
| **P2 — operational follow-up** | Required | Rotate the historically exposed mail credential, set production environment variables, add Redis and a queue worker, and implement inventory reservation before real payment capture. |

## Implemented Security Controls

The following controls are now in the codebase. They use Laravel’s route limiter and cache abstractions, which are designed to apply limits through the configured cache store; database, Redis, and Memcached stores provide atomic counter operations for concurrent requests.[1] The implementation therefore uses independently keyed limits where the protected identity differs: IP address for broad abuse, normalized email for password-spraying against one account, and authenticated user ID for verification attempts.

| Priority | Finding | Implemented correction | Why it reduces risk |
|---|---|---|---|
| **P0** | A real-looking Gmail app password was committed in `.env.example` and appeared in repository history. | Removed the value from `.env.example`, left only an empty placeholder, and added comments that credentials belong only in the deployment environment. | Prevents new clones and `composer setup` from propagating the exposed credential. Laravel explicitly warns that `.env` content must not enter source control because it can expose credentials.[3] |
| **P0** | Suspended users could continue calling authenticated routes with existing access tokens and could repeatedly refresh their long-lived refresh tokens. | Added `EnsureAccountIsActive` after Sanctum authentication, refreshed account state from the database on every protected request, revoked all active sessions on suspension, and re-checked status in refresh-token rotation. | Suspension now takes effect immediately instead of being advisory. The implementation distinguishes a proven password holder whose account is suspended from an anonymous invalid-credential attempt. |
| **P0** | `idempotency_key` was globally unique, letting one customer occupy a key and force another customer’s checkout to fail. | Added a migration replacing `uq_orders_idempotency_key` with `uq_orders_user_idempotency_key` on `(user_id, idempotency_key)`. Updated retry handling, tests, and API documentation. | Idempotency is correctly scoped to the caller, so unrelated tenants cannot interfere with another checkout. |
| **P1** | CORS had no explicit configuration, leaving framework defaults to decide browser access. | Added `config/cors.php` with an environment-driven `API_CORS_ALLOWED_ORIGINS` allowlist, no wildcard default, constrained methods/headers, and no credentialed cross-origin requests. | Only nominated browser origins can read API responses. `Vary: Origin` is appended so shared caches do not reuse one origin’s CORS policy for another. |
| **P1** | API responses had no central security-header policy and token responses could be cached. | Added `SecurityHeaders` middleware plus exception-response handling for `nosniff`, anti-framing policy, referrer policy, API CSP, permissions policy, HTTPS-only HSTS, and credential `no-store` headers. | Tokens are not retained by browser/proxy caches; reset-link query values are not sent as referrers; responses cannot be framed or MIME-sniffed. Headers also cover framework-generated failures such as 404, 413, and 429. |
| **P1** | Authentication abuse controls were uneven: refresh had no limiter, login was IP-only, webhook traffic was unlimited, and code guessing could be distributed across IPs. | Added separate `login`, `refresh`, `verification`, and `webhook` limiters. Login has both IP and hashed normalized-email keys. Verification has IP and user keys plus a durable five-attempt database budget. | A botnet cannot simply rotate IPs to target one account or exhaust the six-digit code space. The verification row is locked so concurrent failures cannot bypass the remaining-attempt count. |
| **P1** | JSON write endpoints accepted arbitrarily large request bodies. | Added `LimitRequestSize` middleware with a 64 KB API limit and RFC 9457 `413 payload-too-large` response. | Rejects oversized payloads before validation/hydration consumes excessive application memory. |
| **P1** | Client IP could be incorrectly recorded or throttled behind a load balancer. | Added opt-in `API_TRUSTED_PROXIES` parsing. No forwarded header is trusted unless the deployer explicitly lists the proxy or deliberately sets `*` for a managed edge. | Avoids both false rate-limit attribution behind trusted proxies and attacker-controlled `X-Forwarded-For` spoofing on direct deployments. |
| **P1** | Two admins could act on the same order status concurrently and create duplicate history transitions. | Re-read and lock the order row in `AdvanceOrderStatus` before evaluating the transition. | The database serializes concurrent order transitions, allowing only one state change from a given source status. |

### Validation and Testing Added

The codebase now has focused tests for account suspension after login, direct refresh after suspension, invalid-password non-enumeration, durable verification-code exhaustion, authentication cache headers, security headers on framework error responses, CORS allowlisting, request-size rejection, scoped idempotency, and concurrent-safe order transition behavior. These tests sit alongside the existing suite rather than replacing it.

## Implemented Performance and Efficiency Improvements

Public catalogue data is a natural cache target because it is shared by all visitors and changes less frequently than it is read. Laravel’s cache abstraction is explicitly intended for retaining expensive retrieval/processing output for faster subsequent requests; the framework also supports atomic cache additions and increments used by the versioned invalidation scheme.[2] The implementation avoids cache tags because Laravel documents that tags are not supported by the database cache driver used by this project by default.[2]

| Area | Previous behavior | Implemented improvement | Operational impact |
|---|---|---|---|
| Public catalogue | Every product/category read queried MySQL and serialized models again. | Added `CatalogCache`, a 60-second configurable response cache, canonical cache keys, `ETag`, `Cache-Control`, `304 Not Modified`, and a versioned invalidation namespace. | Repeated catalogue reads can avoid database work and, with `If-None-Match`, avoid response-body transfer. |
| Catalogue freshness | Product updates/deletes could leave any future cache stale. | Product update and delete increment the catalogue namespace version immediately. | Cached product lists, details, substitutes, and category counts are invalidated without flushing unrelated cache entries. |
| Product browse sorting | Category-specific price index could not efficiently support all-catalogue price ordering. | Added indexes for `(is_active, effective_price)` and `(is_active, sold_by, effective_price)`. | Allows active public catalogue price sorting with and without `soldBy` filtering to use an aligned index rather than broadly sorting rows. |
| Cart write paths | The active-cart helper eagerly loaded all items and products even for single-item add/update/delete operations. | Request-memoized active-cart lookup now loads `items.product` only for `GET /cart`; item mutations retrieve just the required cart item and its product/inventory. | Reduces row hydration and query work as cart size increases. |
| Orders and notifications | `perPage` accepted arbitrary values, allowing memory-heavy offset pages and expensive counts. | Clamped both list endpoints to `1..100`, retaining the documented default of `20`. | Prevents a client request such as `perPage=1000000` from inducing a massive response. |
| Application bootstrap | The root page was a closure route, preventing a route-cache build. | Replaced it with `Route::view('/', 'welcome')`; confirmed `php artisan optimize` succeeds. | Allows production deployment to compile configuration, events, routes, and views. Laravel recommends configuration caching as a production deployment optimization.[3] |

## Correctness Fixes That Also Improve Security or Performance

The most significant correctness correction is scoped checkout idempotency. A checkout retry is a property of the caller and their request, not of all API tenants. The database now enforces this directly, preserving safe same-customer retries and closing the cross-user failure case.

Email verification attempts are now committed before the generic validation exception is returned. The implementation intentionally avoids throwing inside the transaction because that would roll back the increment and silently make the attempt budget ineffective. The code deletes an exhausted verification record after five failed guesses; a new resend creates a fresh code with a new budget.

Order advancement now locks the current database row before deciding the state transition. This means two administrators or a Telegram callback plus an API request cannot both observe the same old status and insert duplicate audit history. The API still returns the same transition problem details when a later request finds that the first transition already completed.

## Deployment Runbook

The following steps must be completed for the code changes to protect a deployed environment. They are ordered by urgency. The deployment must provide production values through the platform’s secret/environment-variable interface, not by committing a populated `.env` file.[3]

| Order | Required action | Exact implementation step | Acceptance check |
|---|---|---|---|
| **1** | **Rotate the exposed Gmail credential immediately.** | Revoke the old Gmail app password in Google Account security settings; create a replacement only if SMTP remains required; set the replacement in the deployment secret manager. | The historical password can no longer authenticate to Gmail. |
| **2** | Remove the historical secret from repository history. | Use an approved history-rewrite process such as `git filter-repo`, force-push only after team coordination, and invalidate all derived clones/caches. Enable GitHub secret scanning where available. | `git log -S '<old secret>' --all` returns no results after the coordinated rewrite. |
| **3** | Configure production environment variables. | Set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, a real `API_CORS_ALLOWED_ORIGINS` list, `API_TRUSTED_PROXIES`, `TELEGRAM_WEBHOOK_SECRET`, mail credentials, and a unique `APP_KEY`. | Production error bodies contain no stack trace; browser CORS only works from approved origin(s); HTTPS cookies are secure. |
| **4** | Apply the new schema safely. | Run `composer install --no-dev --optimize-autoloader` followed by `php artisan migrate --force`. | All three migrations report `Ran`: verification attempts, scoped idempotency, and browse indexes. |
| **5** | Compile production caches. | Run `php artisan optimize` after environment variables are present and migrations succeed. | The command reports successful config, event, route, and view cache builds. Laravel documents `config:cache` as a production deployment step.[3] |
| **6** | Use a shared fast cache in multi-instance production. | Prefer Redis for `CACHE_STORE` and set `CACHE_LIMITER=redis`/the applicable limiter configuration where supported by the deployment. Keep `API_CATALOG_CACHE_TTL=60` initially. | Cache hits are shared across instances; limiter counters are consistent across instances. Laravel supports Redis and database cache stores, but a dedicated cache store is generally preferable for high-volume shared reads.[1] [2] |
| **7** | Run background workers if queued work is enabled. | With `QUEUE_CONNECTION=database`, run a supervised `php artisan queue:work` process on a persistent host. Do not assume a serverless request runtime can process queued jobs. | A test queued job completes without a manual worker invocation. |

> **Important:** `php artisan optimize` was executed successfully during verification, then cleared locally so development continues to load current source/configuration. It should be re-run as the last stage of each production deployment after secrets are set.

## Remaining Risks and Recommended Next Actions

These items were identified during review but intentionally were not implemented because they require product policy, payment-provider behavior, or deployment architecture rather than an isolated API code change. They remain important.

| Priority | Remaining issue | Recommendation | Benefit |
|---|---|---|---|
| **P1** | Cart checks stock, but checkout does not reserve inventory. Two customers can still place orders for the last unit before picking. | Add an explicit inventory reservation table or atomic reservation update during checkout; release reservations on payment expiry/cancellation; make payment authorization use the reservation. | Prevents overselling and payment/refund friction. |
| **P1** | The Telegram chat-link endpoint accepts a chat ID supplied by an administrator without proving the administrator controls that chat. | Use a one-time signed link code initiated from the Telegram bot, then bind the chat only after the bot receives that code from the claimed chat. | Stops accidental or malicious association of another person’s Telegram chat. |
| **P1** | Telegram notification delivery still occurs synchronously after order placement and can add external HTTP latency in proportion to linked administrators. | Move notification delivery to a durable queue job after deploying a supervised worker. For serverless hosting, use a managed queue/worker service rather than relying on in-process post-response execution. | Keeps checkout latency independent of Telegram network latency and enables retries/backoff. |
| **P2** | Public catalogue caching is invalidated by product edits/deletes, but a future inventory-management workflow must also invalidate it. | Centralize catalogue invalidation in product/inventory observers or domain events whenever sellable availability changes. | Keeps `inStock` and substitutes fresh immediately instead of waiting for the 60-second TTL. |
| **P2** | `paginate()` still performs an offset query and `COUNT(*)` for order/notification listing, although page size is now bounded. | Introduce a versioned cursor-pagination contract for these feeds when client compatibility permits; make total count optional. | Better tail latency for customers with long order/notification histories. |
| **P2** | Session and refresh-token rows need lifecycle cleanup as volume grows. | Schedule retention cleanup for expired/revoked refresh tokens and expired verification rows; set retention based on audit requirements. | Reduces table growth, backup size, and index pressure. |
| **P2** | Production security needs observability. | Add structured alerting for 429 spikes, repeated account suspension, refresh replay, webhook signature failures, and slow catalogue endpoints. | Detects active abuse and validates the expected performance gain in production. |

## Verification Record

| Check | Result |
|---|---|
| Baseline before changes | 288 tests passed with 946 assertions. |
| Final automated suite | **302 tests passed with 1,030 assertions.** |
| New regression coverage | Security headers, no-store token responses, CORS behavior, payload size limit, account suspension, refresh denial, verification exhaustion, scoped idempotency, cache/ETag/304 behavior, cache invalidation, page-size bounds, and existing order-transition coverage exercised against the new row-lock implementation. |
| Style validation | `vendor/bin/pint --test` passed. |
| Syntax validation | Updated bootstrap and PHP source validated without syntax errors. |
| Migration validation | All new migrations applied successfully to the local MySQL environment. |
| Production-cache validation | `php artisan optimize` completed successfully. |
| Version-control status | Committed as `8ba336b` and pushed to `hardening/security-and-performance`. |

## References

[1]: https://laravel.com/docs/13.x/rate-limiting "Laravel 13.x Rate Limiting"
[2]: https://laravel.com/docs/13.x/cache "Laravel 13.x Cache"
[3]: https://laravel.com/docs/13.x/configuration "Laravel 13.x Configuration"
[4]: https://laravel.com/docs/13.x/routing "Laravel 13.x Routing"
