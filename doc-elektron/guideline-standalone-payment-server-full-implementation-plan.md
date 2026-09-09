# Elektron Net - `pay-server` Full Implementation Plan

- **Version:** 0.1 (draft)
- **Date:** September 09, 2026
- **Audience:** Developer continuing the standalone payment server build-out
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net) - treat as ground truth for chain/consensus parameters
- **Consumer:** this repository (`elektron-net-payments`), `pay-server/`
- **See also:** [`guideline-standalone-payment-server.md`](guideline-standalone-payment-server.md) (section references below point there), [`guideline-standalone-payment-server-ui-buildout.md`](guideline-standalone-payment-server-ui-buildout.md) (the first build-out pass this plan continues), [`../pay-server/README.MD`](../pay-server/README.MD) (running implementation-status summary, updated as each phase lands)

---

## 1. Where this starts from

The first build-out pass (see the UI-buildout doc above) delivered a complete vertical slice for **direct payments**: data model, `POST`/`GET /v1/orders`, `pay-watcher`, checkout page (QR, live status, optional fiat readout), and a minimal admin (login, order list/detail, API keys, new-order form). Escrow stays real code in `core/`, gated off.

A schema audit (every `merchants`/`orders` column and every table grep'd against `pay-server/api`, `pay-server/watcher`, `pay-server/admin` for actual reads/writes) found these completely unused despite existing in `migrations/0001_init.sql`:

- `merchants.webhook_url`, `webhook_secret_hash` - no delivery, no config UI
- `merchants.checkout_subdomain`, `custom_domain` - no routing, no UI
- `merchants.text_overrides` - no i18n override logic
- `merchants.success_url`, `cancel_url` - no post-checkout redirect
- `merchants.chain_endpoints` (per-merchant override) - only the server-wide default is ever read
- `merchants.default_t1_days`, `default_t2_days` - unread (escrow UI doesn't exist yet)
- `merchants.underpayment_tolerance_percent` - loaded into the DTO, never actually consulted by the watcher
- `payment_requests` table - zero application code
- `order_messages` table - zero application code
- `orders.refund_address` / the mark-refunded flow - zero application code
- `orders.buyer_pubkey`/`seller_pubkey`/`t1_seconds`/`t2_seconds`/PSBT fields - escrow mode itself, entirely unbuilt beyond the gate

This plan closes those gaps, in priority order. Each phase is real code, verified locally (lint + an actual run against Postgres, screenshots where there's a UI), committed and pushed incrementally rather than as one giant diff - so progress is visible and reviewable phase by phase rather than only at the very end.

## 2. Phase A: Merchant self-service (branding + settings)

The most concretely scoped gap, and the one directly flagged: fields a merchant should be able to set themselves currently require raw SQL.

- [x] `PUT /v1/merchants/{id}/branding` and `GET`/`PUT /v1/merchants/{id}/settings` (section 5's table) - real REST endpoints (`api/src/Http/Controllers/MerchantSettingsController.php`), scoped `branding:write`/`settings:write`, self-management only (a key may only manage its own merchant)
- [x] Admin UI: **Branding** page (`/admin/branding`) - display name, logo URL, theme color (WCAG 1.4.11 minimum-3:1-contrast guardrail enforced server-side, `BrandingValidation`), checkout subdomain (format-validated and stored only; routing itself stays out of scope, see section 7 below)
- [x] Admin UI: **Settings** page (`/admin/settings`) - `order_expiry_minutes`, `default_required_confirmations` (with a visible warning banner when set below 1), `underpayment_tolerance_percent`, `default_display_currency` (hidden unless a price feed is configured), `success_url`/`cancel_url` (https-only, `SettingsValidation`)
- [x] Redirect the buyer to `success_url`/`cancel_url` from the checkout page a few seconds after an order reaches a terminal state, when set (`checkout.js`'s `applyStatus()`/`redirectAfterDelay()`) - also fires when landing directly on an already-terminal order, not only on a live SSE transition
- [x] Wire `merchants.underpayment_tolerance_percent` into `Watcher::evaluateOrder()` for real - a confirmed short payment within tolerance now settles (`underpaid_within_tolerance` event payload); beyond tolerance it still stays `processing`, unchanged
- [x] Wire per-merchant `chain_endpoints` override into the watcher (`ChainDataProviderFactory`, falls back to the server-wide default when null or unusable, exactly as section 7 specifies) - `Watcher` now joins `merchants` to evaluate each order against its own merchant's endpoints without an N+1 query
- [x] **Not originally scoped, added because it blocked everything else**: `merchants.receiving_xpub` had no way to be set except raw SQL, meaning a merchant could not onboard at all. Added `/admin/wallet` (`XpubValidation`, mirroring `osclass-escrow/includes/wallet.php`'s already-verified SLIP-132 normalization and private-key rejection), including a preview address and an in-page "how do I get my xpub" guide with real, verified `electrum getmpk` command examples (confirmed against `elektron-net-electrum/electrum/commands.py` and its `README.md`).
- [x] **Not originally scoped, added on request**: a theme-color picker (`<input type="color">` plus eight WCAG-3:1-passing presets) alongside the existing text field on `/admin/branding`, and a real, configurable `PriceFeedProviderInterface` implementation (`core/src/PriceFeed/HttpSimplePriceFeedProvider.php`, CoinGecko "Simple Price"-shaped but base URL/coin id/platform fully configurable via `PAY_SERVER_PRICE_FEED_ENDPOINTS`, several entries aggregated through the existing `FallbackPriceFeedProvider`) wired into `pay-api`'s front controller, plus turning `/admin/settings`' `default_display_currency` free-text field into a currency dropdown with an "Other..." custom-code option.

Verified locally against a real Postgres instance: saved branding (display name, logo URL, theme color, subdomain) and confirmed the sidebar/checkout immediately reflect it; triggered the WCAG contrast guardrail with `#fefefe` and got the real rejection; saved settings (expiry, 0 confirmations - the warning banner appeared, tolerance, `default_display_currency`, both redirect URLs) and confirmed every value round-tripped through Postgres unchanged; created a fresh order and watched the checkout page actually navigate to `success_url` a few seconds after the order was set to `settled` (network-level `requestfailed` log confirms the exact attempted URL, since `your-shop.example` does not resolve); connected/replaced a wallet through `/admin/wallet` and got a real rejection for a malformed key; the theme color picker's two-way text/swatch sync and preset selection, verified against the same server-side contrast check. `Watcher`'s tolerance and per-merchant chain-endpoint logic were code-reviewed and lint-checked but not exercised live in this sandbox (same bitwasp/network limitation as the rest of this build-out). The HTTP price-feed provider *was* exercised live: pointed at a local stub server returning CoinGecko's documented `simple/price` shape, the checkout page's "≈" line matched `order amount * stub rate` exactly, and the settings dropdown's preset/"Other" round-trip was confirmed through a real save and page reload.

## 3. Phase B: Commerce features with an existing data model but no code

- [ ] `payment_requests` (section 16): `PaymentRequestRepository`, `POST /v1/payment-requests`, `GET /v1/payment-requests/{id}` (public, capability-token), admin UI (create/archive/view spawned orders), and the public request page that spawns a fresh, one-time `orders` row per payment (never reuses an address, per section 9)
- [ ] `order_messages` (section 11): `POST`/`GET /v1/orders/{id}/messages` (append-only, server-assigned timestamps), a simple thread UI on both the checkout page (buyer) and the admin order-detail page (merchant), rate-limited per section 11's checklist
- [ ] Refund flow (section 15): `POST /v1/orders/{id}/refund-address` on the checkout page (buyer-supplied, format-validated only), `POST /v1/orders/{id}/mark-refunded` in the admin order-detail page, clearly labeled self-reported/not verified, linked from the overpayment flag

## 4. Phase C: Platform completeness

- [ ] `POST`/`DELETE /v1/merchants/{id}/api-keys` as real REST endpoints (the admin UI already does this; section 5's table lists it as an API surface too, for a merchant's own tooling)
- [ ] Webhook delivery (section 5, section 24): HMAC-signed payload, delivery attempted from `order_events` (already the log for this), a config UI for `webhook_url`/regenerating `webhook_secret`, basic retry, and a delivery-status view (BTCPay-style manual redeliver, noted in the UI-buildout doc's backlog)
- [ ] Idempotency-Key header support on `POST /v1/orders` in addition to the existing `external_reference`-based idempotency (section 5 checklist names both patterns)
- [ ] OpenAPI/JSON schema definition for the `/v1/*` surface (section 5 checklist)

## 5. Phase D: Larger, separately-scoped work

Flagged rather than folded into the phases above - each is a substantial chunk in its own right and deserves its own pass rather than being rushed inside this one:

- [ ] **Escrow mode** (sections 8-11, 20 turned on): buyer/seller pubkey wiring on order creation, PSBT relay endpoints, confirm-receipt, T1/T2 countdown UI, session-handoff QR (section 10.B). This is the single largest remaining item - comparable in size to everything already built for direct mode.
- [ ] **i18n catalog** (section 18): `pay-server`-owned locale store seeded from `osclass-escrow/languages/*.po`, the full fallback chain, a language switcher.
- [ ] **Multi-user admin + 2FA** (section 21, open question 8): `merchant_users` self-service (invite/remove users), TOTP.
- [ ] **`custom_domain` + ACME automation** (section 17): explicitly named in the guideline itself as its own later milestone, not bundled with subdomain branding.
- [ ] **`osclass-escrow` migration to call the new API** (section 22).

## 6. Working method

Same discipline as the first pass: real code, `php -l` on everything touched, an actual local run against Postgres (not just described), screenshots for anything with a UI, then commit and push to `paymentserver` per phase rather than batching everything into one commit - so the branch stays reviewable and any single phase can be checked independently. This document's checkboxes are updated as each item lands.
