# Elektron Net - `pay-server` Frontend/Backend UI Build-out Plan

- **Version:** 0.1 (draft)
- **Date:** September 09, 2026
- **Audience:** Developer continuing the standalone payment server build-out
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net) - treat as ground truth for chain/consensus parameters
- **Consumer:** this repository (`elektron-net-payments`), specifically `pay-server/checkout` and `pay-server/admin`
- **See also:** [`guideline-standalone-payment-server.md`](guideline-standalone-payment-server.md), the design this plan implements (section references below point there); [`../pay-server/README.MD`](../pay-server/README.MD) for the running implementation-status summary

---

## 1. Goal

Backend-only `pay-server` (REST API + watcher) already exists. This plan adds the two remaining UI surfaces the guideline calls for:

- **Checkout (buyer-facing):** section 10 (QR flow, live status), section 19 (single-page checkout view).
- **Admin (merchant-facing):** section 21 (login, order list/detail, API key management).

Worked through step by step in the order below; each item is checked off here as it lands, so a follow-up session can see exactly what is done without re-reading the whole diff.

## 2. Checklist

### Backend support for the UI

- [x] `Bip21` URI builder (`elek:` scheme, mirrors `osclass-escrow/includes/formatting.php`'s already-verified `elektron_escrow_payment_uri()`)
- [x] Vendor a QR encoder for the checkout page (client-side rendering, no CDN dependency - see `pay-server/checkout/assets/vendor/NOTICE.md`)
- [x] Extend `Db\Merchant` with `display_name`/`logo_url`/`theme_color` (section 17 branding fields already in the schema, not yet read into the DTO)
- [x] `Order::toPublicArray()` (buyer-safe fields only) and `Order::toAdminArray()`
- [x] `Http\Responder` interface so a route handler can return either a buffered `JsonResponse` or a streamed `SseResponse`
- [x] `GET /v1/orders/{id}/public` - capability-token (order id only) buyer-facing status JSON
- [x] `GET /v1/orders/{id}/events` - Server-Sent Events for live status (section 10.C), same capability-token auth
- [x] `Db\OrderRepository::findByMerchant()` for the admin order list
- [x] `Db\MerchantUserRepository` (email + password lookup for admin login)
- [x] Admin session helper (login/logout, password verification, CSRF token)

### Checkout frontend (`pay-server/checkout/`)

- [x] `checkout/public/index.php` front controller: `GET /order/{id}` (served from `pay-server/api/public/index.php`, matching section 2's architecture diagram - "pay-api: REST API, checkout pages, admin UI" is one process, not three)
- [x] Order template: amount, address, countdown to `expires_at`, status badge, merchant branding (display name/logo/theme color, section 17)
- [x] QR code (desktop) vs. tap-to-pay button (mobile) via device detection (section 10.A)
- [x] Live status: `EventSource` against `/v1/orders/{id}/events`, falling back to polling `GET /v1/orders/{id}/public` on error (section 10.C)
- [x] Escrow-specific UI (PSBT step, T1/T2 countdown) stays unreachable while `ESCROW_ENABLED=false` (section 20) - direct-mode-only page for now

### Admin backend UI (`pay-server/admin/`)

- [x] Admin routes served from `pay-server/api/public/index.php` under `/admin/*` (same one-process architecture as checkout; section 3's `admin/` still holds its own templates/assets)
- [x] Login page (`merchant_users`, section 21)
- [x] Order list + order detail page (full fields + event log)
- [x] API key management: list (masked), create (raw key shown once, section 14), revoke
- [x] Logout

### Verification

- [x] `php -l` on every new file
- [x] Local end-to-end run against a real Postgres instance: created a merchant/order, opened the checkout page, confirmed the QR actually decodes (`zbarimg`) to the expected `elek:` URI (`elek:DEMO-PLACEHOLDER-ADDRESS-...?amount=3.75&label=Elektron%20Demo%20Shop`), simulated a `pay-watcher`-style status transition and watched the checkout page update live via SSE with no reload, exercised the admin login (including a wrong-password rejection), order list/detail, and API-key create+revoke flows (a revoked key was confirmed to get a real 401 on its next API call)
- [x] Screenshots of both surfaces
- [x] Update `pay-server/README.MD` and root `README.MD` to describe the new surfaces
- [x] Commit (author: `kutlusoy` / GitHub no-reply email) and push to `paymentserver`

## 3. What stays out of scope for this pass

Documented so nobody mistakes silence for "done": branding editor UI, merchant settings editor (T1/T2, currency, chain endpoints), payment-requests UI, order-messages UI, webhook configuration UI, `merchant_users` self-service (inviting additional admin users), and everything escrow-mode-specific in either surface. These remain listed as open in `pay-server/README.MD`'s "What is not implemented yet".

## 4. Backlog: ideas worth borrowing from BTCPay Server

Not scoped or started; captured here so a future pass has a concrete starting list instead of re-deriving it. `pay-server` already shares BTCPay's basic vocabulary (section 13's order states are explicitly modeled on BTCPay's own invoice states), so leaning on more of its conventions where they fit is a reasonable default rather than inventing new ones. None of this changes anything already implemented; each item would extend a section the main guideline already defines a home for:

- **Store users with roles** (BTCPay: owner/manager/guest per store) - extends the already-flagged `merchant_users` self-service gap (section 21) beyond a single login per merchant.
- **Webhook delivery log with manual redelivery** in the admin UI - `order_events` already doubles as that log (section 4's note); BTCPay's "redeliver" button on a failed delivery is a small, concrete UI to add once webhook delivery itself (still open) exists.
- **Pull payments / payout processor** - BTCPay's model for merchant-initiated outbound payments with an approval step; relevant to section 15's refund flow, which today is purely self-reported with no in-app payout tracking at all.
- **A simple point-of-sale / payment-button app** - a merchant-configurable, embeddable "pay X amount" widget; overlaps heavily with section 16's reusable payment requests, which already has a data model (`payment_requests`) but no UI yet.
- **Per-store custom checkout CSS/embed** - BTCPay lets a store override checkout appearance beyond logo/color; would extend section 17's branding once a branding editor UI exists (currently only the data model + checkout-page rendering do).
- **2FA on the admin login** - BTCPay supports TOTP for dashboard logins; section 21's open question 8 already asks this exact question ("whether any form of two-factor is required").
- **Receipt / "thank you" page state** distinct from the live order-status page - BTCPay shows a dedicated receipt view once an invoice settles; today a settled order just shows the same page with the QR/pay-area hidden (see `checkout.js`'s `applyStatus()`), which works but is minimal.
- **Rate rule expressions for the price feed** - BTCPay's rate rule syntax (e.g. preferring one source, falling back to another with a spread) is a reasonable model to adopt once `PriceFeedProviderInterface` (section 12) gets a real implementation, rather than inventing a narrower config format later.

Not every BTCPay concept fits here and pulling in anything BTCPay-specific to Lightning, multiple on-chain assets, or its plugin/app marketplace should be treated with more scrutiny than the list above -- this project is single-asset (ELEK) and self-hosted-first, so only borrow the parts that solve a problem this guideline already has, not BTCPay's whole surface area.

## 5. Follow-up items closed after the initial pass

The checklist above covered the first build-out pass end to end. Two gaps surfaced afterward and were closed in the same style (real code, verified locally, not a mockup):

- [x] **Merchant "type an amount, click through" workflow** - before this, the only way to create an order at all was `POST /v1/orders` with a Bearer token; the admin dashboard could list orders but not create one. Added `GET`/`POST /admin/orders` (`admin/templates/order-new.php`) and a "Show this to the buyer" QR panel on the order-detail page. Extracted the validation/idempotency/persistence logic `OrdersController::create()` already had into `api/src/OrderCreationService.php` so the admin form and the REST API share one implementation instead of two copies drifting apart.
- [x] **`PriceFeedProviderInterface`** (section 12, previously an open item) - defined for real in `core/src/PriceFeed/`, mirroring `ChainDataProviderInterface`'s pattern as the guideline asks. `CheckoutController` takes one optionally; with none configured (still the case for every real deployment -- no implementation ships), the checkout page's optional fiat readout simply never renders, matching section 12 exactly. Verified locally with a demo fixed-rate provider (135 USD/ELEK): the checkout page shows "≈ 337.50 USD (approx.)" under a 2.5 ELEK order, clearly secondary to the ELEK amount and never affecting `amount_lep`.

Verified locally: real form submission through the new admin route (including a rejected zero-amount attempt, showing `OrderValidation`'s real error message), the resulting order's QR panel on its detail page, and the fiat-readout checkout page with a demo price feed. Screenshots taken; no code paths here were only described, not run.
