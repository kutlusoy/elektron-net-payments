# Elektron Net Payments, `pay-server` Implementation Status

- **Version:** 0.1 (draft)
- **Date:** September 09, 2026
- **Audience:** Developers continuing the standalone payment server build-out
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net) - treat as ground truth for chain/consensus parameters
- **Consumer of:** [`../core`](../core), the platform-agnostic escrow/payments core
- **See also:** [`../doc-elektron/guideline-standalone-payment-server.md`](../doc-elektron/guideline-standalone-payment-server.md), the design this component implements; every section reference below points there

---

`pay-server` is the standalone, self-hostable payment server described in the guideline: `pay-api` (REST API, checkout pages, admin UI), `pay-watcher` (background chain-polling daemon), and `core/` underneath both, carried over unchanged from the former `shared/` package.

This document tracks what of the guideline is actually implemented versus still open, so a follow-up session does not have to re-derive it from the diff. It is a status document, not a second copy of the design; anything not restated here still means "as specified in the guideline".

## What is implemented

- **Repository layout** (guideline section 3): `core/` (renamed from `shared/`), `pay-server/{api,watcher,checkout,admin,migrations,docker}/`, `scripts/release-pay-server.sh`, `.github/workflows/{ci-pay-server.yml,release-pay-server.yml}`. `osclass-escrow`'s and `core`'s own `composer.json`/docs updated to the new path.
- **Data model** (section 4): `migrations/0001_init.sql`, all eight tables, plus the hard constraints the guideline's own checklists ask for (`orders.address` and `orders.order_nonce_hex` UNIQUE; a partial unique index on `(merchant_id, external_reference)` for open orders, closing the double-submit race described in the guideline's notes and in `core/README.md`'s "Open items"; T1/T2 ratio and bounds as CHECK constraints).
- **`POST /v1/orders` and `GET /v1/orders/{id}`, direct mode** (section 5, prioritized first by section 25): `api/src/Http/Controllers/OrdersController.php`. Includes:
  - Bearer-token auth against `merchant_api_keys` with SHA-256-hashed keys and per-scope enforcement through one shared `ApiKeyAuthenticator` (section 14 checklist).
  - `mode: "escrow"` rejected with the typed `escrow_disabled` error while `PAY_SERVER_ESCROW_ENABLED` is off (section 20 checklist); escrow order creation itself is not implemented yet (see "What is not implemented" below).
  - Idempotency via `external_reference`: a repeated `POST` for the same open order returns the existing order instead of creating a duplicate (section 5 checklist item, implemented as the compare-and-set pattern the section 4 notes and `core/README.md`'s "Open items" describe).
  - Address allocation: a fresh child address per order via `core`'s `XpubChildKeyDeriver`, with a strictly monotonic `merchants.next_receiving_index` counter claimed under a row lock (section 9 checklist).
- **`pay-watcher` skeleton** (section 6): `watcher/src/Watcher.php` polls non-terminal orders against `core`'s `FallbackChainDataProvider`/`EsploraChainDataProvider` and drives the direct-mode lifecycle (`new` -> `processing` -> `settled`, or -> `expired`), writing one `order_events` row per transition. Poll interval and batch size are configurable via environment variables (section 6 checklist).
- **Checkout page** (section 10, section 19): `GET /order/{id}`, served from the same `pay-api` process (`checkout/templates/order.php` + `checkout/assets/`). Payment QR code (BIP21-style `elek:` URI, `api/src/Bip21.php`, rendered client-side with a vendored MIT-licensed encoder - see `checkout/assets/vendor/NOTICE.md`) on desktop, a tap-to-pay button on mobile (device-detected via `checkout.js`); live status via Server-Sent Events (`GET /v1/orders/{id}/events`, `api/src/Http/SseResponse.php`) with polling (`GET /v1/orders/{id}/public`) as the automatic fallback, matching section 10.C. Merchant branding (display name, logo, theme color) is read from `merchants` and applied to the page.
- **Admin dashboard** (section 21): `/admin/*`, same `pay-api` process, session-cookie authenticated (`api/src/Admin/AdminSession.php`) and distinct from both the checkout page's capability-token model and the Bearer-token API. Login against `merchant_users` (bcrypt via `password_verify()`), order list + order detail (full fields + event log), API key management (create with the raw key shown exactly once, list masked to a 4-character suffix, revoke - section 14), and a "new order" form (`GET`/`POST /admin/orders`) so a merchant can type an amount and get a checkout link/QR without needing an API key at all - shares its validation/idempotency/persistence logic with `POST /v1/orders` via `api/src/OrderCreationService.php` rather than duplicating it.
- **`PriceFeedProviderInterface`** (section 12): `core/src/PriceFeed/PriceFeedProviderInterface.php` and `FallbackPriceFeedProvider.php`, mirroring `ChainData\ChainDataProviderInterface`'s own pattern exactly, as the section asks for. `CheckoutController`'s constructor takes an optional provider so wiring one is a config change, not a redesign. When a merchant has `merchants.default_display_currency` set and a provider is configured, the checkout page shows a small "≈ 337.50 USD (approx.)" line under the ELEK amount, exactly as section 12 specifies (never authoritative, never affecting `amount_lep`).
- **A real, configurable `PriceFeedProviderInterface` implementation**: `core/src/PriceFeed/HttpSimplePriceFeedProvider.php`, shaped after CoinGecko's "Simple Price" endpoint (https://docs.coingecko.com/demo/reference/simple-price) - `GET {base_url}{price_path}?ids={coin_id}&vs_currencies={currency}` returning `{"<coin_id>": {"<currency>": <number>}}` - but nothing about it is CoinGecko-exclusive: base URL, coin id, query parameter names, and an optional API-key header are all configuration, not hardcoded, so any platform exposing the same shape (a self-hosted mirror, a private index) works too. `pay-server/api/src/PriceFeedProviderFactory.php` builds one `HttpSimplePriceFeedProvider` per entry in `PAY_SERVER_PRICE_FEED_ENDPOINTS` (a JSON array, mirroring the existing `PAY_SERVER_CHAIN_ENDPOINTS` pattern - see `docker/pay-api.env.example`) and aggregates them through `FallbackPriceFeedProvider`, so several platforms can be listed and are tried in order. The list is empty by default - ELEK is not listed on any platform today - so an unconfigured deployment behaves exactly as before (`$priceFeedConfigured = false`, no fiat readout, `default_display_currency` stays hidden in the admin UI). Verified against a local stub HTTP server returning the documented shape: the checkout page's "≈" line matched `order amount × stub rate` exactly, proving a genuine HTTP round trip through the real, unmodified provider class.
- **Fiat display currency picker** (section 12/17): `/admin/settings`' `default_display_currency` field is now a `<select>` of common ISO currency codes plus an "Other..." option revealing a free-text field for any 3-letter code, instead of a bare text input - still validated the same way server-side (`SettingsValidation`). Only shown once a price feed is configured server-wide, same as before. There is no per-currency price-feed-source selection: the feed is configured server-wide (`PAY_SERVER_PRICE_FEED_ENDPOINTS`), matching section 12's "driven by the same feed" wording and the existing `chain_endpoints` precedent of a server-wide default with no per-field source override.
- **Docker/CI/CD** (section 23): `docker/Dockerfile.pay-api`, `docker/Dockerfile.pay-watcher`, `ci-pay-server.yml` (lint + `phpunit` on pull requests touching `pay-server/**` or `core/**`), `release-pay-server.yml` (tag `pay-v*` builds and pushes both images to GHCR). `release-osclass-escrow.yml` path-scoped to `osclass-escrow/**` and `core/**` so the two release workflows can never trigger each other.
- **Escrow code presence** (section 20): nothing in `core/Escrow`/`core/Psbt` was touched, deleted, or moved; only the new API layer's validation gates it off. The checkout page has no PSBT-signing step, session-handoff QR, or T1/T2 countdown UI - unreachable by construction, since no escrow order can currently be created.
- **Wallet connect** (section 8): `/admin/wallet` - the merchant onboarding step that was entirely missing before (`merchants.receiving_xpub` had no way to be set except raw SQL, meaning a merchant could not actually use the product at all). `api/src/XpubValidation.php` mirrors `osclass-escrow/includes/wallet.php`'s already-verified logic: accepts a plain `xpub` or the SLIP-132 `ypub`/`zpub` variants, rejects a pasted private key outright, never asks which SLIP-44 coin type derived it (section 8's own requirement), and shows a preview address so a merchant can confirm the key before trusting it for real orders. Includes verified `electrum getmpk` command examples for merchants who need to find their own xpub (confirmed against `elektron-net-electrum`'s actual CLI, not assumed).
- **Branding and settings** (sections 12/13/17): `/admin/branding` and `/admin/settings`, plus the matching `PUT /v1/merchants/{id}/branding` and `GET`/`PUT /v1/merchants/{id}/settings` REST endpoints (section 5's table) sharing the same `BrandingValidation`/`SettingsValidation` classes and `MerchantRepository` methods as the admin UI. Theme color gets a real WCAG 1.4.11 contrast check against white (reject anything below 3:1) rather than trusting whatever hex a merchant enters; `default_required_confirmations` below 1 shows a visible warning; `success_url`/`cancel_url` are https-only and the checkout page now actually redirects the buyer there a few seconds after an order settles/expires, when set.
- **Underpayment tolerance and per-merchant chain endpoints**, wired into `Watcher::evaluateOrder()` for real: a confirmed short payment within a merchant's own `underpayment_tolerance_percent` now settles instead of staying `processing` forever, and each order is evaluated against its own merchant's `chain_endpoints` override (falling back to the server-wide default) via `watcher/src/ChainDataProviderFactory.php`, joined into the watcher's single polling query rather than an N+1 lookup.

## What is not implemented yet

Everything else in the guideline is still open. In the order section 25's own checklist lists them:

- Escrow-mode order creation on `POST /v1/orders` (buyer/seller pubkey wiring, T1/T2 freezing via `core`'s `TimeoutPolicy`) - currently returns a typed `not_implemented` error even when the escrow flag is on, since the flag alone does not yet have real behavior behind it.
- Every other `/v1/*` endpoint from section 5's table not covered above: PSBT relay, confirm-receipt, refund-address/mark-refunded, order messages, payment requests (the admin UI's own API-key/branding/settings management already covers those endpoints' functionality; payment requests and messages have neither a REST endpoint nor a UI yet).
- Admin UI: payment-requests management, order-messages view, webhook configuration, and `merchant_users` self-service (inviting additional admin users) - a single login per merchant only, for now.
- Webhook delivery (section 5, section 24) - `order_events` rows are written on every transition as the delivery log the guideline describes, but nothing reads that log and actually delivers a signed HMAC payload yet.
- i18n catalog and locale store (section 18) - not seeded from `osclass-escrow/languages/*.po` yet; the checkout and admin pages are English-only for now.
- Fiat-priced orders (`merchants.base_currency` set to a fiat code, section 12's other direction): every order is still validated as ELEK-only (`OrderValidation::validateCreateOrderPayload()`) regardless of whether a price feed is configured - a real `PriceFeedProviderInterface` implementation now exists (`HttpSimplePriceFeedProvider`), but freezing `amount_lep`/`fiat_currency`/`fiat_amount`/`exchange_rate_used` at order-creation time is separate work, not yet built. Only the optional, non-authoritative checkout-page fiat readout uses the feed so far.
- `osclass-escrow` migration to call the new API (section 22) - the adapter is untouched beyond the `../shared` -> `../core` path update; it still runs its own local escrow flow.

See [`doc-elektron/guideline-standalone-payment-server-full-implementation-plan.md`](../doc-elektron/guideline-standalone-payment-server-full-implementation-plan.md) for the phased plan closing these, worked through and pushed phase by phase.

## Running it locally

```bash
# 1. Point PAY_SERVER_DB_DSN at a Postgres instance and apply the schema:
psql "$DATABASE_URL" -f pay-server/migrations/0001_init.sql

# 2. Install dependencies. Only run composer install inside pay-server/,
#    never standalone inside core/: the bitwasp/bitcoin -> lastguest/murmurhash
#    PHP-8 alias (see core/README.md, "Using it from a new adapter") only
#    takes effect from pay-server's own composer.json, which is where it
#    is declared; core's path repository is resolved as part of this
#    install, so core never needs (or should get) its own separate install.
cd pay-server && composer install

# 3. Copy the env examples and fill in real values:
cp pay-server/docker/pay-api.env.example pay-server/docker/pay-api.env
cp pay-server/docker/pay-watcher.env.example pay-server/docker/pay-watcher.env

# 4. Run pay-api (checkout pages and the admin UI are served from the same
#    process - see "How pay-api serves three surfaces" below):
PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8080 -t pay-server/api/public

# 5. Run pay-watcher (separate process):
php pay-server/watcher/bin/watch.php

# Tests:
cd pay-server && vendor/bin/phpunit
```

- REST API: `http://localhost:8080/v1/...`
- Checkout page for an order: `http://localhost:8080/order/{id}`
- Admin dashboard: `http://localhost:8080/admin` (redirects to `/admin/login`)

### How `pay-api` serves three surfaces

Matching section 2's architecture diagram ("pay-api: REST API, checkout pages, admin UI"), `pay-server/api/public/index.php` is the single front controller for all three; `checkout/` and `admin/` hold their own templates and static assets (CSS/JS), referenced by fixed, hardcoded absolute paths (`Http\FileResponse`, `Http\Controllers\CheckoutController`/`Admin\*`) rather than duplicated into `api/public/`. A real deployment would typically have its reverse proxy serve the `/assets/checkout/*` and `/assets/admin/*` routes directly and never reach PHP for them.

`PHP_CLI_SERVER_WORKERS=4` above is not optional, locally or in the Docker image (`docker/Dockerfile.pay-api` sets it too): PHP's built-in server handles one request at a time per worker, and the checkout page's SSE connection (`GET /v1/orders/{id}/events`) stays open for up to ~5 minutes - without worker processes, that single open connection blocks every other request (including the admin UI and the REST API) until it ends. A production deployment fronting `pay-api` with PHP-FPM behind nginx/Apache instead would not need this workaround at all; the Dockerfile uses the built-in server for its own simplicity, so it needs it.

Docker images build from the repository root (both Dockerfiles `COPY core` and `COPY pay-server`, since `pay-server`'s `composer.json` depends on `core` via a path repository):

```bash
docker build -f pay-server/docker/Dockerfile.pay-api -t elektron-net/pay-api .
docker build -f pay-server/docker/Dockerfile.pay-watcher -t elektron-net/pay-watcher .
```

## Open questions

Unchanged from the guideline's own section 26; nothing here resolves any of them yet.
