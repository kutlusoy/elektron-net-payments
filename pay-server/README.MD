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
- **Docker/CI/CD** (section 23): `docker/Dockerfile.pay-api`, `docker/Dockerfile.pay-watcher`, `ci-pay-server.yml` (lint + `phpunit` on pull requests touching `pay-server/**` or `core/**`), `release-pay-server.yml` (tag `pay-v*` builds and pushes both images to GHCR). `release-osclass-escrow.yml` path-scoped to `osclass-escrow/**` and `core/**` so the two release workflows can never trigger each other.
- **Escrow code presence** (section 20): nothing in `core/Escrow`/`core/Psbt` was touched, deleted, or moved; only the new API layer's validation gates it off.

## What is not implemented yet

Everything else in the guideline is still open. In the order section 25's own checklist lists them:

- Escrow-mode order creation on `POST /v1/orders` (buyer/seller pubkey wiring, T1/T2 freezing via `core`'s `TimeoutPolicy`) - currently returns a typed `not_implemented` error even when the escrow flag is on, since the flag alone does not yet have real behavior behind it.
- Every other `/v1/*` endpoint from section 5's table: PSBT relay, confirm-receipt, refund-address/mark-refunded, order messages, payment requests, API-key management, branding, texts, redirects, and merchant settings endpoints.
- Checkout page, order-status page, and admin dashboard (sections 10, 17, 19, 21) - no frontend exists yet; `pay-watcher` and `OrdersController` are backend-only so far.
- Webhook delivery (section 5, section 24) - `order_events` rows are written on every transition as the delivery log the guideline describes, but nothing reads that log and actually delivers a signed HMAC payload yet.
- Server-Sent Events for live status (section 10.C) - polling `GET /v1/orders/{id}` is the only mechanism right now.
- i18n catalog and locale store (section 18) - not seeded from `osclass-escrow/languages/*.po` yet.
- `PriceFeedProviderInterface` (section 12) - not defined in `core/` yet; every order is validated as ELEK-only in the meantime (`OrderValidation::validateCreateOrderPayload()`), which is the guideline's own required behavior for "no price feed configured".
- Underpayment-tolerance evaluation in the watcher - `Watcher::evaluateOrder()` currently treats a confirmed short payment as staying `processing` rather than consulting `merchants.underpayment_tolerance_percent`; the column and the merchant-settings endpoint to manage it both still need wiring.
- `osclass-escrow` migration to call the new API (section 22) - the adapter is untouched beyond the `../shared` -> `../core` path update; it still runs its own local escrow flow.

## Running it locally

```bash
# 1. Point PAY_SERVER_DB_DSN at a Postgres instance and apply the schema:
psql "$DATABASE_URL" -f pay-server/migrations/0001_init.sql

# 2. Install dependencies (from the repo root):
cd core && composer install && cd ../pay-server && composer install

# 3. Copy the env examples and fill in real values:
cp pay-server/docker/pay-api.env.example pay-server/docker/pay-api.env
cp pay-server/docker/pay-watcher.env.example pay-server/docker/pay-watcher.env

# 4. Run pay-api:
php -S 0.0.0.0:8080 -t pay-server/api/public

# 5. Run pay-watcher (separate process):
php pay-server/watcher/bin/watch.php

# Tests:
cd pay-server && vendor/bin/phpunit
```

Docker images build from the repository root (both Dockerfiles `COPY core` and `COPY pay-server`, since `pay-server`'s `composer.json` depends on `core` via a path repository):

```bash
docker build -f pay-server/docker/Dockerfile.pay-api -t elektron-net/pay-api .
docker build -f pay-server/docker/Dockerfile.pay-watcher -t elektron-net/pay-watcher .
```

## Open questions

Unchanged from the guideline's own section 26; nothing here resolves any of them yet.
