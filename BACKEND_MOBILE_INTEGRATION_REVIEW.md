# Backend–Mobile Integration Review and Implementation Guide

**Project:** Mini-Ecommerce / Grocerly

**Author:** Manus AI

**Review status:** Code repairs completed; deployment validation remains blocked by an unavailable production API endpoint and unavailable local PHP tooling.

## Executive Summary

The mobile application could not reliably communicate with the backend because its declared shared API client package was absent, its preview configuration targeted an endpoint that returned `404 Not Found`, and several mobile workflows used contracts that do not exist in the Laravel API. The most consequential mismatch was checkout: the original mobile screen sent local cart and pricing data directly to order creation, whereas the backend correctly requires a server-issued checkout quote, a delivery-slot UUID, and an idempotency key. These defects have been repaired in the working tree. [1] [2] [3]

The revised mobile client now has a shared React Query API package, uses backend UUIDs consistently, converts backend decimal values into display-safe numeric values, retrieves live delivery slots, requests a server-authoritative quote before creating an order, and sends the required order fields. It also uses secure native storage for access and refresh tokens rather than plaintext asynchronous storage. [2] [4] [5]

> **Release blocker:** `https://grocerly-api.vercel.app/up` and `https://grocerly-api.vercel.app/v1/products` both returned `{"error":"Not found"}` during this review. Do not set the mobile production API variable until the backend has been deployed successfully and these smoke tests return the expected responses.

| Priority | Finding | Status | Required release decision |
|---|---|---:|---|
| **P0** | The shared mobile API package was missing from the repository. | **Fixed** | Include the new package and workspace files in the next commit. |
| **P0** | The mobile preview build contained a confirmed-invalid production API hostname. | **Fixed in code; deployment action required** | Configure a verified `EXPO_PUBLIC_API_BASE_URL` in the build environment. |
| **P0** | Checkout used a payload that violated the backend quote and order contract. | **Fixed** | Validate against a deployed API using a real account, cart, address, and delivery slot. |
| **P1** | Mobile IDs were typed as numbers while backend public identifiers are UUID strings. | **Fixed** | Regression-test cart, wishlist, product, and order navigation. |
| **P1** | Mobile tokens were stored in plaintext asynchronous storage. | **Fixed** | Test sign-in, restart, and sign-out on iOS and Android. |
| **P1** | Mobile attempted unsupported device-token requests and order-completion calls. | **Fixed** | Keep notification polling until device-token endpoints are deliberately added. |
| **P1** | Access-token refresh is not yet automated after the short backend token lifetime expires. | **Open** | Implement refresh-and-retry handling before a broad production launch. |
| **P2** | The custom static Expo build script still fails to download Metro bundles. | **Open** | Repair or replace the custom build pipeline; use normal EAS builds in the interim. |

## Completed Repairs

### Shared API client and workspace restoration

A new local `@workspace/api-client-react` package has been added under `mini-ecom-frontend/lib/api-client-react`, together with a frontend workspace descriptor and lockfile. The package supplies the client functions and React Query hooks that the mobile application already imports. It centralizes URL assembly, bearer-token injection, a 20-second network timeout, JSON/problem-error processing, query keys, and response normalization. [4]

The response mapping intentionally adapts the Laravel wire format to the existing mobile display model. For example, backend monetary DECIMAL values are converted at the display boundary, `primaryImageUrl` is mapped to the mobile `image` field, `unitLabel` is mapped to `unit`, and backend order timestamps and status-history entries are transformed into the invoice and timeline fields consumed by the screens. This prevents each screen from implementing its own fragile conversion logic. [4] [6]

### Explicit API configuration and safe failure

The mobile bootstrap no longer relies on `EXPO_PUBLIC_DOMAIN` for API traffic. It now reads **`EXPO_PUBLIC_API_BASE_URL`**, which must be an absolute backend origin without `/v1`; the client adds `/v1` itself. The obsolete API setting was removed from the preview build configuration and a `.env.example` file now documents emulator, LAN-device, and production formats. [5] [7]

| Environment | Correct API variable example | Notes |
|---|---|---|
| Android emulator | `EXPO_PUBLIC_API_BASE_URL=http://10.0.2.2:8000` | Use only with a locally running backend. |
| Physical development device | `EXPO_PUBLIC_API_BASE_URL=http://192.168.x.x:8000` | Replace with the developer machine’s reachable LAN address. |
| Production | `EXPO_PUBLIC_API_BASE_URL=https://api.example.com` | Require HTTPS and do **not** include `/v1`. |

### Checkout contract correction

The mobile checkout now obtains delivery slots from `GET /v1/delivery-slots`, requires the customer to choose a returned slot, obtains a quote using `POST /v1/checkout/quote`, and creates the order only with the resulting `quoteToken`, the selected address and delivery-slot UUIDs, payment method, customer note, and stable idempotency key. This matches the backend request validation rather than trusting client-calculated totals, client cart rows, or placeholder delivery times. [2] [8]

The payment options were also aligned to values accepted by the backend: `card`, `cash_on_delivery`, and `bakong`. The former `cash` and `apple_pay` values were not part of the backend’s payment-method contract. [8]

### Identifier, notification, and order-operation alignment

The backend exposes public UUID strings in product, cart, address, order, and notification resources. The mobile cart, wishlist, order navigation, and notification deduplication state have been updated to use strings rather than numeric IDs. [6] [9]

The previous mobile notification provider attempted best-effort device-token registration even though no matching backend route exists. It now uses the available authenticated polling endpoint only, eliminating repeated hidden 404 requests. The previous customer-side “Confirm Receipt” action was also removed because the backend does not expose a customer completion endpoint; delivery completion belongs to backend fulfilment operations. [1] [10]

### Credential protection

Access and refresh tokens now use `expo-secure-store`, while non-secret user profile data remains cached separately. The sign-in and registration paths also remove legacy plaintext token keys, and sign-out clears both secure and legacy storage. This substantially reduces credential exposure on native devices. [11]

## Validation Performed

| Verification | Result | Interpretation |
|---|---:|---|
| `pnpm install --frozen-lockfile` from `mini-ecom-frontend` | Passed | The restored workspace resolves reproducibly. |
| `pnpm --filter @workspace/mobile run typecheck` | Passed | The updated mobile client, screens, and contexts are type-consistent. |
| `git diff --check` | Passed | No whitespace errors were present in the final source changes. |
| Production host `/up` request | Failed (`404`) | The configured public host is not serving this Laravel deployment. |
| Production host `/v1/products` request | Failed (`404`) | The mobile client must not target this host until deployment is corrected. |
| Backend PHP/Laravel test suite | Not run | The review environment did not contain PHP, Composer, or installed Laravel dependencies. |
| Custom `pnpm run build` static Expo pipeline | Failed | Metro returned `404` for generated bundle URLs; this is an independent packaging issue, not a TypeScript error. |

The source-level validation is therefore successful, but a real end-to-end test remains mandatory because the reviewed production host is unavailable and the local environment cannot execute Laravel tests.

## Required Deployment and Acceptance Procedure

First, deploy the backend from `mini-ecom-backend` with its production secrets and database settings. Set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, production database credentials, and the explicit CORS origin list. The CORS configuration is intentionally allowlist-based, so add only the approved web origins; native applications do not normally send browser origins. [12] [13]

Second, verify the deployed backend before changing mobile configuration. The following commands should complete successfully, with the catalogue request returning `200` and a valid JSON payload.

```bash
curl -i https://YOUR_API_HOST/up
curl -i https://YOUR_API_HOST/v1/products
```

Third, set the verified origin in the mobile build environment. Do not restore the removed `EXPO_PUBLIC_DOMAIN` API setting.

```bash
EXPO_PUBLIC_API_BASE_URL=https://YOUR_API_HOST
```

Fourth, run a physical-device acceptance test. Register a new customer, sign in, restart the app, add and edit cart items, create an address, select a live delivery slot, request a quote, place an order, open the order and invoice views, sign out, and confirm that the next account cannot see the preceding user’s cached data.

Finally, repair the separate custom static build script before using it for releases. Until that task is completed, use a normal EAS build or another supported Expo packaging route. The custom script expects Metro bundle routes that returned `404` in this workspace configuration. [14]

## Remaining Recommendations

| Priority | Recommendation | Why it matters | Specific next action |
|---|---|---|---|
| **P1** | Add refresh-token handling with one controlled retry on `401` responses. | The backend intentionally uses short-lived access tokens; users will otherwise lose authenticated functionality after expiry. | Add a serialized refresh workflow using `POST /v1/auth/refresh`, rotate secure tokens atomically, retry the original request once, and force logout on refresh failure. [3] |
| **P1** | Add backend integration tests for the mobile critical path. | Type checking cannot verify authorization, quote expiry, inventory locking, idempotency, or response envelopes. | Add Pest feature tests covering register, login, cart mutation, quote, order creation, duplicate idempotency key, and cross-user resource denial. |
| **P1** | Deploy and monitor a real health endpoint. | The configured production hostname is currently nonfunctional from the mobile client. | Verify Vercel project/domain routing, inspect deployment logs, and make `/up` part of post-deploy monitoring. [13] |
| **P2** | Decide whether remote push is a product requirement. | The mobile code now correctly avoids unavailable routes, but polling is less immediate and less efficient. | If push is required, design authenticated device-token registration, encrypted token storage, token revocation, and queue-based delivery before re-enabling client registration. |
| **P2** | Replace client-generated UUID idempotency keys with cryptographically secure values. | The current implementation is stable for retries but uses `Math.random()`, which is not a cryptographic generator. | Use `expo-crypto` or a platform secure-random UUID implementation for checkout idempotency keys. |
| **P2** | Repair the custom static Expo build pipeline. | It cannot currently package the app even though the application type checks. | Add a supported monorepo Metro configuration or retire the custom bundle downloader in favor of EAS export/build tooling. [14] |

## Changed Files

| Area | Main files changed |
|---|---|
| Shared API boundary | `mini-ecom-frontend/lib/api-client-react/src/index.ts`, `mini-ecom-frontend/lib/api-client-react/package.json` |
| Workspace reproducibility | `mini-ecom-frontend/pnpm-workspace.yaml`, `mini-ecom-frontend/pnpm-lock.yaml` |
| API configuration | `mobile/mobile/app/_layout.tsx`, `mobile/mobile/.env.example`, `mobile/mobile/eas.json` |
| Authentication security | `mobile/mobile/context/AuthContext.tsx`, `mobile/mobile/package.json` |
| Checkout and order lifecycle | `mobile/mobile/app/checkout/index.tsx`, `mobile/mobile/app/order/[id].tsx` |
| UUID and notification correctness | `mobile/mobile/context/CartContext.tsx`, `WishlistContext.tsx`, `NotificationsContext.tsx`, `app/(tabs)/search.tsx` |
| Packaging investigation | `mobile/mobile/scripts/build.js` |

## References

[1]: mini-ecom-backend/routes/api.php "Laravel API route definitions"
[2]: mini-ecom-backend/app/Http/Requests/PlaceOrderRequest.php "Order creation request validation"
[3]: mini-ecom-backend/config/api.php "API token lifetime configuration"
[4]: mini-ecom-frontend/lib/api-client-react/src/index.ts "Restored shared mobile API client"
[5]: mini-ecom-frontend/mobile/mobile/app/_layout.tsx "Mobile API bootstrap configuration"
[6]: mini-ecom-backend/app/Http/Resources "Laravel API resource serializers"
[7]: mini-ecom-frontend/mobile/mobile/.env.example "Mobile API environment template"
[8]: mini-ecom-backend/app/Http/Requests/CreateCheckoutQuoteRequest.php "Checkout quote request validation"
[9]: mini-ecom-frontend/mobile/mobile/context/CartContext.tsx "Mobile cart UUID handling"
[10]: mini-ecom-frontend/mobile/mobile/context/NotificationsContext.tsx "Mobile notification polling behavior"
[11]: mini-ecom-frontend/mobile/mobile/context/AuthContext.tsx "Secure mobile credential persistence"
[12]: mini-ecom-backend/config/cors.php "Backend CORS allowlist configuration"
[13]: mini-ecom-backend/vercel.json "Backend deployment routing configuration"
[14]: mini-ecom-frontend/mobile/mobile/scripts/build.js "Custom static Expo build script"
