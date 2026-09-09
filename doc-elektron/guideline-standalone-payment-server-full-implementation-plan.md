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

- [ ] `PUT /v1/merchants/{id}/branding` and `PUT /v1/merchants/{id}/settings` (section 5's table) - real REST endpoints, not just admin-UI-only actions, scoped to `branding:write`/`settings:write` (section 14's scope list already names these)
- [ ] Admin UI: **Branding** settings page - display name, logo URL, theme color (with the contrast-ratio guardrail section 17 requires), checkout subdomain (format-validated only; routing itself is out of scope here, see section 7 below)
- [ ] Admin UI: **Settings** page - `order_expiry_minutes`, `default_required_confirmations` (with the plain-language warning section 13 requires below 1), `underpayment_tolerance_percent`, `default_display_currency` (hidden/disabled unless a price feed is configured, per section 12's own requirement), `success_url`/`cancel_url` (restricted to `https://`, section 17 checklist)
- [ ] Redirect the buyer to `success_url`/`cancel_url` from the checkout page once an order reaches a terminal state, when set
- [ ] Wire `merchants.underpayment_tolerance_percent` into `Watcher::evaluateOrder()` for real (currently ignored - a confirmed short payment always stays `processing`)
- [ ] Wire per-merchant `chain_endpoints` override into the watcher (falls back to the server-wide default when null, exactly as section 7 specifies)

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
