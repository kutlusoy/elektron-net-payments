# Elektron Net - `elektron-net-payments` Standalone Payment Server Guideline

- **Version:** 0.1 (draft)
- **Date:** September 09, 2026
- **Audience:** Engineering team implementing the standalone payment server
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net) - treat as ground truth for chain/consensus parameters (chainparams.cpp, SLIP-44 coin type 1370, bech32 HRP `be`)
- **Consumer:** this repository (`elektron-net-payments`), specifically the new `pay-server/` component; `osclass-escrow` becomes a client of it going forward
- **See also:** [`elektron-net-mempool`](https://github.com/kutlusoy/elektron-net-mempool) and [`elektron-net-electrs`](https://github.com/kutlusoy/elektron-net-electrs) for chain-data endpoints; `shared/README.md` and the root `README.MD` in this repository for the escrow model and timeout policy this design extends

---

## 1. Goal and scope

Turn the escrow logic currently locked inside the Osclass adapter into a standalone, self-hostable payment server that any storefront can use without installing a platform-specific plugin. The server's data model and API MUST support two payment modes:

- **Direct payments**: single recipient, no counterparty, no timeout logic. A simplified case of the existing address-watching flow.
- **Escrow payments**: the existing 2-of-2 multisig, CLTV-timeout model (`PlainMultisigEscrowScriptBuilder`, `Bip174PsbtBuilder`, `TimeoutPolicy`), unchanged in its trust model.

**Launch scope, however, is direct payments only.** Escrow support ships as real, present code from day one, not stripped out, but stays switched off end to end (API validation, admin UI, checkout UI) behind a single feature flag until it is explicitly turned back on. See section 20 for exactly what "switched off" means at each layer.

The server MUST be usable in two operating modes without any code branching between them:

1. A single merchant runs their own instance for their own store.
2. One instance serves many merchants, each with their own API key, branding, and settings.

This is the same code path either way; a single-merchant deployment is simply a `merchants` table with one row.

## 2. High-level architecture

```
                    +-------------------+
                    |   pay-api         |   REST API, checkout pages, admin UI
                    +---------+---------+
                              |
                              v
                    +-------------------+
                    |   Postgres/MySQL  |   merchants, orders, order_events
                    +---------+---------+
                              ^
                              |
                    +---------+---------+
                    |   pay-watcher     |   background daemon: chain polling,
                    +---------+---------+   webhook delivery, T1/T2 reminders
                              |
                              v
              +---------------------------------+
              |  core/ (existing shared/src)     |
              |  Escrow, Psbt, ChainData,        |
              |  TimeoutPolicy, I18n              |
              +---------------+-------------------+
                              |
                              v
        +---------------------------------------------+
        | FallbackChainDataProvider                    |
        |  1) mempool.elektron-net.org  (esplora API)  |
        |  2) mempool2.elektron-net.org (esplora API)  |
        |  3) mempool3.elektron-net.org (esplora API)  |
        |  [future tier: electrs.*, electrs2, electrs3 |
        |   once an Electrum-protocol provider exists] |
        +---------------------------------------------+
```

`core/` is a straight carry-over of today's `shared/src`; nothing in that namespace needs to know it is now driving a standalone server instead of an Osclass plugin.

## 3. Repository layout changes

```
elektron-net-payments/
    core/                       <- renamed/moved from shared/src, framework-agnostic
    pay-server/
        api/                    <- HTTP layer: routes, controllers, auth, webhooks
        watcher/                <- long-running daemon process
        checkout/               <- hosted checkout pages (generalized from osclass-escrow/views)
        admin/                  <- merchant management UI
        migrations/             <- DB schema migrations
        docker/                 <- Dockerfiles for pay-api and pay-watcher
    osclass-escrow/             <- unchanged in principle, becomes a thin client (see section 22)
    scripts/
        build-osclass-plugin.sh <- unchanged
        release-pay-server.sh   <- new: builds and tags pay-server Docker images
    .github/workflows/
        release-osclass-escrow.yml   <- unchanged, path-scoped (see section 23)
        release-pay-server.yml       <- new
        ci-pay-server.yml            <- new
    doc-elektron/
        guideline-standalone-payment-server.md   <- this document
```

**Checklist for this section:**
- [ ] Move `shared/src` to `core/` (or keep `shared/` as the directory name and treat it as the shared root for both `pay-server` and `osclass-escrow`; either is fine, but pick one and update every `composer.json` path repository entry consistently)
- [ ] Confirm `core/` keeps its zero dependency on any platform (no Osclass/WooCommerce references anywhere in that namespace)

## 4. Data model

```sql
CREATE TABLE merchants (
    id                  UUID PRIMARY KEY,
    name                VARCHAR(255) NOT NULL,   -- internal/account name, not shown to buyers
    display_name        VARCHAR(255),            -- shown on the checkout page header, defaults to `name`
    webhook_url         TEXT,
    webhook_secret_hash VARCHAR(255),
    logo_url            TEXT,
    theme_color         VARCHAR(7),          -- hex, e.g. #1a1a1a
    checkout_subdomain  VARCHAR(63) UNIQUE,  -- e.g. 'acme' for acme.pay.elektron-net.org
    custom_domain       VARCHAR(255) UNIQUE, -- optional, merchant's own domain (see section 17)
    receiving_xpub      VARCHAR(120),        -- merchant's own extended public key for direct-mode address generation, see section 8
    success_url         TEXT,                -- redirect target after a completed order
    cancel_url          TEXT,                -- redirect target after an abandoned order
    text_overrides      JSONB,               -- per-locale key/value overrides, see section 18; shape: {"de": {...}, "en": {...}}
    default_t1_days     INTEGER NOT NULL DEFAULT 30,
    default_t2_days     INTEGER NOT NULL DEFAULT 60,
    chain_endpoints     JSONB,               -- null = use server-wide default list
    base_currency               VARCHAR(10) NOT NULL DEFAULT 'ELEK',  -- 'ELEK' or an ISO fiat code, see section 12
    default_display_currency    VARCHAR(10),                          -- optional informational conversion target when base_currency is 'ELEK', see section 12
    order_expiry_minutes        INTEGER NOT NULL DEFAULT 15,          -- see section 13
    default_required_confirmations INTEGER NOT NULL DEFAULT 1,        -- see section 13
    underpayment_tolerance_percent NUMERIC(5,2) NOT NULL DEFAULT 0,   -- see section 13
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE merchant_api_keys (
    id            UUID PRIMARY KEY,
    merchant_id   UUID NOT NULL REFERENCES merchants(id),
    label         VARCHAR(255) NOT NULL,   -- e.g. 'Osclass plugin', 'Admin scripting'
    key_hash      VARCHAR(255) NOT NULL,
    scopes        JSONB NOT NULL,          -- e.g. ["orders:create", "orders:read"], see section 14
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at  TIMESTAMPTZ,
    revoked_at    TIMESTAMPTZ
);

CREATE TABLE payment_requests (
    id            UUID PRIMARY KEY,
    merchant_id   UUID NOT NULL REFERENCES merchants(id),
    amount_lep    BIGINT,           -- null = buyer enters their own amount, see section 16
    currency      VARCHAR(10) NOT NULL,   -- the currency the configured amount is denominated in, see section 12
    description   TEXT,
    expires_at    TIMESTAMPTZ,      -- null = never expires
    archived_at   TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE merchant_users (
    id              UUID PRIMARY KEY,
    merchant_id     UUID NOT NULL REFERENCES merchants(id),
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255),        -- null if using magic-link-only auth
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE orders (
    id                  UUID PRIMARY KEY,
    merchant_id         UUID NOT NULL REFERENCES merchants(id),
    mode                VARCHAR(10) NOT NULL,   -- 'direct' or 'escrow'
    status              VARCHAR(30) NOT NULL,   -- 'new', 'processing', 'settled', 'expired', or 'invalid', see section 13
    amount_lep          BIGINT NOT NULL,
    address             VARCHAR(100) NOT NULL,
    buyer_pubkey        VARCHAR(66),            -- escrow only
    seller_pubkey       VARCHAR(66),             -- escrow only
    t1_seconds          INTEGER,                 -- frozen at creation, escrow only
    t2_seconds          INTEGER,                 -- frozen at creation, escrow only
    order_nonce_hex     VARCHAR(32) NOT NULL,
    external_reference  VARCHAR(255),            -- merchant's own order/item id
    expires_at              TIMESTAMPTZ NOT NULL,  -- frozen from merchants.order_expiry_minutes at creation, see section 13
    required_confirmations  INTEGER NOT NULL,      -- frozen from merchants.default_required_confirmations at creation, see section 13
    fiat_currency           VARCHAR(10),           -- set only if priced in fiat at creation, see section 12
    fiat_amount             NUMERIC(20,2),         -- set only if priced in fiat at creation, see section 12
    exchange_rate_used      NUMERIC(20,8),         -- rate applied at creation, frozen permanently, see section 12
    refund_address          VARCHAR(100),          -- optional, buyer-supplied, see section 15
    payment_request_id      UUID REFERENCES payment_requests(id),  -- set if this order was spawned from a payment request, see section 16
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    funded_at           TIMESTAMPTZ,
    completed_at        TIMESTAMPTZ
);

CREATE TABLE order_messages (
    id          UUID PRIMARY KEY,
    order_id    UUID NOT NULL REFERENCES orders(id),
    sender      VARCHAR(10) NOT NULL,   -- 'buyer' or 'merchant'
    body        TEXT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE order_events (
    id          UUID PRIMARY KEY,
    order_id    UUID NOT NULL REFERENCES orders(id),
    type        VARCHAR(50) NOT NULL,      -- e.g. 'funded', 'psbt_relayed', 'released', 'webhook_sent'
    payload     JSONB,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Notes:
- `order_events` doubles as the webhook delivery log (for retries) and as an audit trail, matching what `ReminderScheduler` and the T1/T2 reminder logic already assume exists somewhere.
- The double-submit race noted as an open item in `shared/README.md` MUST be closed here with a proper unique constraint plus compare-and-set insert now that this is a real service with its own transaction boundary, rather than deferred as it was under Osclass's DAO layer.

## 5. API design

Base path: `/v1`. Authentication: `Authorization: Bearer <api key>` for merchant-initiated calls; each key is one row in `merchant_api_keys` (section 14) with its own fixed scope list, checked per endpoint. The checkout pages themselves are unauthenticated (order id acts as the capability token, same as any hosted-checkout link).

| Method | Path | Purpose |
|---|---|---|
| POST | `/v1/orders` | Create a direct or escrow order. Accepts an amount in the merchant's `base_currency` or an explicit currency override (section 12). Returns `order_id` and `checkout_url`. Scope: `orders:create`. |
| GET | `/v1/orders/{id}` | Poll order status (section 13's states). Scope: `orders:read`. |
| POST | `/v1/orders/{id}/psbt` | Relay a signed PSBT between buyer and seller (escrow only). Scope: `orders:create`. |
| POST | `/v1/orders/{id}/confirm-receipt` | Buyer-side confirmation trigger (escrow only). Scope: `orders:create`. |
| POST | `/v1/orders/{id}/refund-address` | Buyer submits a refund/return address (section 15). No scope needed; capability-token authenticated like the checkout page itself. |
| POST | `/v1/orders/{id}/mark-refunded` | Merchant self-reports that a refund was sent from their own wallet (section 15). Scope: `orders:create`. |
| POST | `/v1/orders/{id}/messages` | Append one timestamped message to the order's message log (buyer or merchant). Append-only: no update or delete endpoint exists for a posted message. Scope: `orders:messages`. |
| GET | `/v1/orders/{id}/messages` | Read the full, chronologically ordered message log for the order. Scope: `orders:messages`. |
| POST | `/v1/payment-requests` | Create a reusable payment request (section 16). Scope: `payment_requests:manage`. |
| GET | `/v1/payment-requests/{id}` | Read a payment request's public details (amount or open-amount, description). Unauthenticated, capability-token style like an order. |
| POST | `/v1/merchants/{id}/api-keys` | Create a new scoped API key; the raw key is returned exactly once. Scope: `settings:write`. |
| DELETE | `/v1/merchants/{id}/api-keys/{keyId}` | Revoke a key immediately. Scope: `settings:write`. |
| POST | `/v1/merchants/{id}/branding` | Upload/replace logo, theme color, display name, checkout subdomain, and custom domain. Scope: `branding:write`. |
| PUT | `/v1/merchants/{id}/texts` | Replace one or more checkout-page text strings, keyed the same as `I18n\MessageCatalog`; any key not overridden keeps the catalog's fallback text. Scope: `branding:write`. |
| PUT | `/v1/merchants/{id}/redirects` | Set `success_url` and `cancel_url`. Scope: `settings:write`. |
| GET | `/v1/merchants/{id}/settings` | Read T1/T2, currency, expiry, confirmation, and tolerance defaults, plus chain endpoint overrides. Scope: `settings:write`. |
| PUT | `/v1/merchants/{id}/settings` | Update those defaults (subject to the existing guardrails: T2 >= 1.5x T1, both within sane bounds) and chain endpoint overrides. Scope: `settings:write`. |

Webhooks: signed with an HMAC over the raw payload using the merchant's `webhook_secret`, same pattern as the API-key auth above. Every event lands in `order_events` before delivery is attempted, so a failed delivery can be retried from the log rather than only from in-memory state.

**Checklist for this section:**
- [ ] Rate limiting per API key
- [ ] Idempotency key support on `POST /v1/orders` (a merchant's own retry-on-timeout must not create two orders)
- [ ] OpenAPI/JSON schema definition, published alongside the API for merchant integrators
- [ ] Scope enforcement implemented as a single shared middleware check, not reimplemented per endpoint

## 6. Watcher service

A separate long-running process, not a cron job and not triggered by web requests, so status updates and T1/T2 reminders happen regardless of storefront traffic:

- Polls `FallbackChainDataProvider` for every order in a non-terminal status.
- On funding detected: evaluate against section 13's rules (confirmation depth, underpayment tolerance) to decide the exact next status (`processing` vs `settled`), write an `order_events` row, fire the merchant's webhook, and push the update to any open checkout-page connection (section 10's live-status mechanism).
- On `expires_at` passing with no qualifying payment: transition to `expired`, write the event, fire the webhook.
- On T1/T2 threshold approach: fire the existing reminder logic from `Notifications/ReminderScheduler`.
- Poll interval and batch size MUST be configurable; a large multi-merchant deployment needs this tuned differently than a single-merchant one.

## 7. Chain data configuration

Server-wide default, usable immediately with the existing `EsploraChainDataProvider` (mempool.space forks expose the Esplora-compatible REST surface under `/api`):

```php
'chain_endpoints' => [
    ['type' => 'esplora', 'base_url' => 'https://mempool.elektron-net.org/api'],
    ['type' => 'esplora', 'base_url' => 'https://mempool2.elektron-net.org/api'],
    ['type' => 'esplora', 'base_url' => 'https://mempool3.elektron-net.org/api'],
],
```

Future tier (requires a new `ElectrumChainDataProvider`, a TCP/JSON-RPC client for the Electrum protocol v1.4 that `elektron-net-electrs` speaks; it is not Esplora-compatible and cannot be added to the list above as-is):

```php
    ['type' => 'electrum', 'host' => 'electrs.elektron-net.org',  'port' => 50002, 'ssl' => true],
    ['type' => 'electrum', 'host' => 'electrs2.elektron-net.org', 'port' => 50002, 'ssl' => true],
    ['type' => 'electrum', 'host' => 'electrs3.elektron-net.org', 'port' => 50002, 'ssl' => true],
```

A merchant MAY override this list entirely in their own `chain_endpoints` column; if null, the server-wide default above applies.

**Checklist for this section:**
- [ ] Confirm exact base path used on the three mempool forks (assumed standard `/api`, matching upstream mempool.space)
- [ ] `ElectrumChainDataProvider` implementation, as a follow-up milestone, not a blocker for launch

## 8. Merchant receiving wallet and coin-type compatibility

Direct-mode payments need a per-order receiving address, derived the same way escrow addresses already are: from an already-connected extended public key via `Escrow\XpubChildKeyDeriver`, never from a private key the server generates or holds itself. This means every merchant onboarding flow needs a "connect your receiving wallet" step, storing the resulting xpub in `merchants.receiving_xpub` (section 4), before that merchant can accept a direct payment at all.

**Coin-type duality MUST be handled correctly here, not assumed away.** Elektron Net registered SLIP-44 coin type 1370, added after mainnet had already been live under the legacy coin type `0'`; wallets created before that point (including the Elektron Electrum Wallet, which intentionally stayed on `0'` for compatibility with standard Electrum derivation) keep deriving under `0'`, while newer wallets may use `1370'`. Both coexist on mainnet indefinitely; neither is deprecated or invalid.

The good news, already established in `core/`'s own design (see `shared/README.md`, "SLIP-44 coin type (1370) is irrelevant to this package, by design"): the escrow script builder and address derivation never look at which path produced a pubkey, they only ever receive an already-derived compressed public key or xpub as opaque bytes. `pay-server`'s wallet-connect step MUST follow the same rule:

- Accept any well-formed xpub/ypub/zpub (or the raw pubkey format already validated in `osclass-escrow/includes/wallet.php`, `^(02|03)[0-9a-fA-F]{64}$`) regardless of which coin type derived it. Do not add a coin-type check anywhere in this validation; there is nothing correct such a check could reject.
- The merchant onboarding UI (section 21's admin dashboard) MUST NOT ask the merchant which coin type they used, since the server has no reason to know or care, and asking invites a wrong answer from a merchant who does not know their own wallet's derivation path.
- If merchant-facing documentation or the wallet-connect screen names example wallets, it MUST NOT imply one coin type is the "correct" or "new" one; both are equally valid indefinitely, exactly as `doc-elektron/guideline-wallet-integration.md` already specifies for wallet vendors.

This applies identically once escrow mode (section 20) is turned back on: buyer and seller pubkeys/xpubs on wallet connect follow the exact same rule, unchanged from how `osclass-escrow` already handles it today.

**Checklist for this section:**
- [ ] `merchants.receiving_xpub` collected during merchant onboarding, validated for format only, never for coin type
- [ ] Wallet-connect validation logic shared between the merchant onboarding flow and the (currently disabled) escrow buyer/seller connect flow, so the rule is enforced once, not reimplemented per flow
- [ ] No coin-type field or selector anywhere in the admin UI or API for connecting a wallet

## 9. Address reuse and quantum-hygiene precautions

A precautionary requirement, applied consistently across both payment modes: **no address is ever reused across more than one order**, for direct payments exactly as much as for escrow.

**Escrow already gets most of this by construction.** `shared/README.md`, "Escrow address correctness" already establishes one address per order (via the order nonce plus per-order child pubkeys from `XpubChildKeyDeriver`), and the address itself is a P2WSH witness program (`be1q...`, confirmed against `EscrowAddress`): the underlying script, and the pubkeys inside it, stay hidden behind a hash until the escrow is actually spent. This MUST be treated as a requirement to preserve, not an implementation detail to casually change later (e.g. switching to an address format that exposes the pubkey directly in the output, such as a taproot/`be1p...` address, would remove exactly this property; see the note below).

**Direct-mode payments MUST get the identical guarantee, which is not automatic.** A merchant's `receiving_xpub` (section 8) MUST NOT be turned into the same address for every order; `pay-server` MUST derive a fresh child address per order from it via the same `XpubChildKeyDeriver` used for escrow, and MUST enforce this with a hard uniqueness constraint on `orders.address` (not just a convention), the same way `orders.order_nonce_hex` already guarantees escrow-side uniqueness.

**Why this matters for quantum exposure, stated precisely so the reasoning does not get lost later.** A hashed output (P2WSH here, P2WPKH for a plain single-key address) only reveals the actual public key at the moment it is spent, not while funds merely sit there; before that point, an observer only sees a hash, and deriving a private key from a hash rather than from the public key itself is a different problem that a quantum computer does not similarly break (Grover's algorithm gives only a quadratic speedup against a hash, not the exponential break Shor's algorithm gives against the elliptic-curve discrepancy an exposed public key represents). Never reusing an address means:
- No public key sits exposed on-chain across multiple, separate incoming payments; each order's key is only ever revealed once, at the single moment its own funds move.
- A pattern of repeated payments to the same address (which would otherwise link multiple orders' pubkeys together the moment any one of them is spent) never forms.

**What this precaution does not achieve, so nobody mistakes it for more than it is.** Once an order's funds actually move (direct payout, or escrow release/refund), the pubkey used to sign is permanently exposed on that transaction regardless of how carefully reuse was avoided beforehand; ECDSA and Schnorr signatures themselves remain fully breakable by a sufficiently capable quantum computer once the corresponding public key is public. This is a hygiene measure that shrinks the exposure window and prevents needless correlation, not a substitute for genuine post-quantum signatures. A protocol-level post-quantum signature scheme would be a consensus-layer change to `elektron-net` itself, entirely outside what `pay-server` can do; this section MUST NOT be read or documented as making the payment server quantum-safe, only as reasonable, currently-achievable precaution.

**Checklist for this section:**
- [ ] Hard uniqueness constraint on `orders.address`, not merely an application-level convention
- [ ] Direct-mode order creation always calls `XpubChildKeyDeriver` for a fresh child address, mirroring the existing escrow-mode behavior exactly
- [ ] Strictly monotonic child-derivation index per xpub, never reused even for a cancelled or expired order
- [ ] No change to the P2WSH/P2WPKH address format that would expose a pubkey before spend (e.g. no move to a taproot-style address) without re-evaluating this section first
- [ ] This section's "what it does not achieve" paragraph kept intact in any future revision, so the precaution is never mistaken for full quantum resistance

## 10. Checkout UX: QR flow, desktop and mobile

Two distinct QR interactions exist and MUST both be covered:

**A. Payment QR (both modes).** Standard BIP21-style URI in the QR code (address plus amount plus order reference), shown on the checkout page regardless of device. Works identically on desktop (buyer scans with phone wallet) and mobile (buyer taps to open their wallet app directly, no scanning needed). This part needs no special handling beyond detecting device type to decide "show QR" versus "show tap-to-pay button" on the same page.

**B. Escrow signing round trip (desktop/mobile handoff).** This is the harder case: a buyer or seller may start checkout on desktop but hold their wallet on a phone, and a signed PSBT has to travel from the phone back to the order.

Recommended default: **session handoff**, not a manual QR-scan-back of the raw PSBT.
- The desktop checkout page shows a QR code encoding a one-time session link to the same order.
- Scanning it opens the checkout page on the phone, already tied to the order.
- The wallet app on the phone signs and submits the PSBT directly to `POST /v1/orders/{id}/psbt` (either via a wallet that supports posting to a URL, or via the checkout page acting as the go-between after the wallet hands the signed PSBT back to the browser).
- The desktop tab polls `GET /v1/orders/{id}` and updates the moment the signature lands; no manual re-scan is needed on desktop.

Secondary, later-phase option for advanced/air-gapped users: an animated QR standard (BC-UR or BBQr) to carry a PSBT that exceeds a single QR code's practical size limit, for wallets that have no network connection of their own. This MUST NOT be the default flow since it needs a camera-equipped counterparty device and is materially more fiddly; it is an addition for the air-gapped-hardware-wallet crowd, not the primary buyer/seller path.

**C. Live status updates.** The checkout and order-status pages MUST reflect a status change (section 13's states) the moment the watcher writes it, not on the next manual refresh. Server-Sent Events, one connection per open order, pushed from `pay-api` whenever the watcher (section 6) transitions that order, is the primary mechanism; plain polling of `GET /v1/orders/{id}` remains as the fallback for any client or network that blocks SSE, so the page still works, just less immediately.

**Checklist for this section:**
- [ ] Device detection (user agent or viewport) to switch between "scan" and "tap" presentation of the payment QR
- [ ] Session-handoff link generation and expiry (short-lived, single-use)
- [ ] Server-Sent Events endpoint for live order-status push, with polling as the automatic fallback

## 11. Order messaging as a dispute record

No OP_RETURN, no on-chain memo of any kind for this. Matching a payment to an order is already solved off-chain by the never-reused address (section 9) plus `orders.external_reference` (section 4); nothing about identification needs to touch the chain at all.

What is worth having instead: a per-order, append-only message log both sides can write to, so that if a dispute ever comes up, there is an actual timestamped record of what was said and when, rather than relying on emails, chat screenshots, or memory. `order_messages` (section 4) is exactly this: every message is its own row, with a server-assigned timestamp (`created_at DEFAULT now()`, never a client-supplied time, so a party cannot backdate or reorder their own messages) and a `sender` marking it as `buyer` or `merchant`.

**This log MUST be treated as a record, not a live chat feature, which drives a few concrete requirements:**
- Append-only, permanently. No `PUT`/`DELETE` on a posted message, at the API layer or anywhere else; once written, a message stays exactly as written. `POST /v1/orders/{id}/messages` (section 5) is the only way to add to it.
- Multiple messages per order are the normal case, not an edge case: a buyer and merchant may go back and forth several times over the life of one order, each exchange landing as its own row with its own timestamp, so the full sequence is reconstructable later.
- Both sides read the same thread: the buyer sees it on the order-status page (section 10) via their order capability token, the merchant sees it on the admin dashboard (section 21); there is exactly one log per order, not a separate copy per side.
- Kept distinct from `order_events` (section 4): `order_events` is the system's own automated audit trail (funded, released, webhook sent); `order_messages` is human-authored communication. Do not merge the two tables or let one imply the other.
- A merchant SHOULD be able to export or print the full message log for a given order (plain chronological listing is enough; no special formatting requirement) so it can actually be handed over or attached somewhere in a real dispute process.

**Checklist for this section:**
- [ ] `order_messages` API endpoints (section 5) implemented as append-only; no edit/delete path exists anywhere, including in the admin UI
- [ ] Server-assigned `created_at` only; no client-supplied timestamp accepted from either side
- [ ] Message thread rendered identically (same content, same order) on both the buyer's order-status page and the merchant's admin dashboard
- [ ] Simple export/print view of an order's message log available from the admin dashboard
- [ ] Basic rate limiting on `POST /v1/orders/{id}/messages` to prevent spamming the log

## 12. Pricing and currency conversion

**Today's reality drives the default: `elektron-net` (ELEK) is not listed on any exchange, so there is no live price feed, and every merchant prices and gets paid in ELEK.** This MUST work with zero configuration and MUST remain the permanent fallback regardless of what gets built for the fiat case below.

**Design for when a price feed eventually exists, without waiting for one to build the plumbing.** A `PriceFeedProviderInterface` in `core/`, mirroring `ChainDataProviderInterface`'s existing pattern exactly (an interface, a fallback-capable aggregator if more than one source ever exists, and a perfectly valid "no provider configured" state that is not an error). No implementation ships initially; the interface exists so plugging one in later is a config change, not a redesign.

**Two directions, both merchant-chosen, both driven by the same feed once one exists:**
- **Fiat as base currency.** `merchants.base_currency` set to an ISO code (e.g. `USD`, `EUR`) instead of `ELEK`. `POST /v1/orders` then accepts an amount in that currency; the server converts to ELEK at creation time via the feed and freezes the resulting `amount_lep` permanently into the order, exactly like T1/T2 are frozen at creation and never recomputed (section 4). `orders.fiat_currency`, `orders.fiat_amount`, and `orders.exchange_rate_used` record what was actually charged and at what rate, for the merchant's own bookkeeping and for any later dispute. This is the rate lock: once created, an order's ELEK amount never moves even if the market rate does.
- **ELEK as base currency (the default), with an optional informational fiat readout.** The merchant still prices and is paid in ELEK; if they have also set `merchants.default_display_currency`, the checkout page additionally shows a converted amount in that currency purely for the buyer's orientation, computed live from the feed at display time, clearly marked as approximate and never authoritative, never frozen, never affecting `amount_lep`.
- **`merchants.base_currency` MUST be locked to `ELEK`** (fiat options hidden in the admin dashboard, not merely disabled) whenever no `PriceFeedProviderInterface` implementation is configured server-wide, the same treatment section 20 already gives the escrow flag: a feature with nothing behind it yet MUST NOT be presented as choosable.

**Rate-lock window.** The frozen conversion rate is only meaningful for as long as the order actually accepts payment; it MUST use the same expiry window as section 13's `orders.expires_at`, so a stale, long-expired order can never be paid at a rate quoted an hour ago.

**Checklist for this section:**
- [ ] `PriceFeedProviderInterface` defined in `core/`, no implementation required for launch
- [ ] `base_currency` fiat options hidden in the admin UI until a real price feed is configured
- [ ] Fiat-priced order creation freezes `amount_lep`, `fiat_currency`, `fiat_amount`, and `exchange_rate_used` at creation, never recomputed afterward
- [ ] ELEK-priced checkout's optional fiat readout is visually distinct from the actual charge amount (e.g. "approx." label), never the number a buyer is expected to pay exactly

## 13. Order lifecycle states and payment tolerances

`orders.status` (section 4) MUST use a small, explicit, BTCPay-style state set rather than an open-ended free-text status field, so every consumer (watcher, API, webhooks, admin UI) agrees on what each state means:

- **New**: created, address (and QR) shown, nothing seen on chain yet.
- **Processing**: a qualifying payment is visible on chain but has not yet reached `orders.required_confirmations`.
- **Settled**: a qualifying payment has reached the required confirmation depth, within the underpayment tolerance below. This is the terminal success state.
- **Expired**: `orders.expires_at` passed with no qualifying payment ever seen. Terminal.
- **Invalid**: something disqualifying happened (the funding transaction was replaced/double-spent, or a payment arrived after expiry with no prior qualifying payment). Terminal.

**Expiry.** `merchants.order_expiry_minutes` (default e.g. 15) is frozen into `orders.expires_at` at creation, the same freeze-at-creation pattern used throughout this design. The watcher (section 6) is the only place that transitions an order to `expired`.

**Confirmation depth.** `merchants.default_required_confirmations` (default 1) is frozen into `orders.required_confirmations` at creation. A merchant MAY configure 0 (accept on sight in the mempool, fastest but exposed to a double-spend) for low-value/low-risk use cases; the admin UI MUST show a plain-language warning of that trade-off whenever a merchant sets it below 1, not silently accept it.

**Under/overpayment tolerance.** `merchants.underpayment_tolerance_percent` (default 0) lets a merchant accept a slightly short payment as `settled` anyway (e.g. because a buyer's wallet mis-estimated a fee and the net amount landed a fraction under the requested total). Overpayment is always accepted as `settled`; the excess amount MUST be flagged clearly to the merchant (surfaced via `order_events` and the admin order view) since it is the natural trigger for the refund flow in section 15.

**Checklist for this section:**
- [ ] `orders.status` constrained to exactly these five values at the database or application-validation layer
- [ ] Confirmation-depth warning shown in the admin UI whenever a merchant sets `default_required_confirmations` below 1
- [ ] Overpayment automatically flagged and surfaced to the merchant, linked to section 15's refund flow
- [ ] Every state transition writes exactly one `order_events` row and fires exactly one webhook, never silently

## 14. Scoped API keys

A single blanket key per merchant (as originally sketched in section 4) is more access than most integrations need. `merchant_api_keys` (section 4) replaces it: a merchant MAY hold several keys, each labeled for its own purpose, each restricted to a fixed list of scopes, each independently revocable without affecting the others.

**Scope list**, matching the endpoint table in section 5: `orders:create`, `orders:read`, `orders:messages`, `payment_requests:manage`, `branding:write`, `settings:write`. An endpoint checks for its required scope in one shared middleware, never reimplemented per route.

**Why this matters concretely:** a storefront plugin (the common integration case, e.g. a future `osclass-escrow`-style adapter per section 22) only ever needs to create and read orders; it gets a key scoped to `orders:create` and `orders:read` alone. If that key leaks (a real risk for anything embedded in third-party storefront code), it cannot touch branding, webhooks, payment requests, or account settings.

**Key handling.** The raw key is shown exactly once, at creation, in the admin dashboard (section 21); only its hash is ever stored, the same treatment `webhook_secret` already gets. `last_used_at` is updated on each authenticated call so a merchant can spot a key nobody uses anymore and revoke it.

**Checklist for this section:**
- [ ] `merchant_api_keys` and the scope-check middleware implemented before any other endpoint ships, since every endpoint in section 5 depends on it
- [ ] Admin UI: create, label, view (masked) list, and revoke keys; raw key never retrievable after creation
- [ ] `last_used_at` tracked per key

## 15. Refund path for direct payments

The server stays non-custodial by design (section 24 restates this as a hard requirement): it never holds a private key, so it cannot itself send a refund. What it can do is give a refund an actual place to live in the system instead of happening entirely outside it over email.

**Flow.** A buyer MAY submit a return address on the order-status page, via `POST /v1/orders/{id}/refund-address` (section 5), authenticated the same way as the rest of the checkout by the order's own capability token; format-validated only, exactly like any other address field in this design, never treated as anything more sensitive. Once a merchant decides to refund (most commonly triggered by the overpayment flag from section 13, or their own decision to cancel an order), they send the funds from their own wallet exactly as they would for any outgoing payment, then call `POST /v1/orders/{id}/mark-refunded` to self-report it.

**This is a self-reported status, explicitly, not a verified one.** The server does not watch for or verify an outgoing merchant transaction; doing so would mean tracking arbitrary transactions from a merchant-controlled wallet, well outside what a payment-receiving service needs to do. The status exists so the order record reflects reality and so it shows correctly on the admin dashboard and in the order's own `order_events`/`order_messages` history, not as an on-chain guarantee.

**Checklist for this section:**
- [ ] `orders.refund_address` collected optionally, format-validated only
- [ ] `mark-refunded` clearly labeled in the admin UI as self-reported, not verified, so nobody mistakes it for an automated guarantee
- [ ] Overpayment flag (section 13) links directly to this flow in the admin order view

## 16. Reusable payment requests

Everything so far assumes a merchant creates one `orders` row per sale. Some real cases do not fit that: a donation button, a "pay what you owe" link, a tip jar. `payment_requests` (section 4) covers these without weakening anything already established.

**A payment request is a template, not a payable object itself.** It carries an optional fixed amount (`amount_lep` null means the buyer enters their own amount) and an optional expiry (null means it never expires) in the merchant's chosen currency (section 12). Its own public page (`GET /v1/payment-requests/{id}`) is a thin "confirm or enter an amount, then pay" screen.

**Every actual payment still gets its own fresh, one-time `orders` row underneath, with its own never-reused address.** The moment someone commits to paying through a payment request, the system creates a normal order (`orders.payment_request_id` links it back to the request that spawned it) and hands off to the exact same checkout flow as any other order. This is deliberate: it means section 9's no-address-reuse rule holds without exception even for a link that gets used a hundred times, because the reusable part is the request, never the order or the address underneath it.

**Checklist for this section:**
- [ ] `payment_requests` never itself carries an address; every payment under it creates a genuine, fresh `orders` row
- [ ] Open-amount requests validate buyer-entered amounts against sane bounds (not zero, not unreasonably large) before creating an order
- [ ] Admin UI: create, archive, and view aggregate history (all orders spawned from) a payment request

## 17. Merchant branding and white-label customization

Every merchant-facing surface (checkout page, order-status page, emails, reminder notifications) MUST read its display name, logo, theme color, and text strings from the merchant record rather than from any hardcoded default, so the buyer-facing experience can look entirely like the merchant's own service rather than a shared third-party page.

**Identity and appearance**
- `merchants.display_name` replaces any generic page title/header; falls back to `merchants.name` if unset.
- `merchants.logo_url`, `merchants.theme_color` as before (section 4). Logo upload MUST be validated for file type and size before storage. The checkout layout MUST hold up with no logo set (a sane default mark), a very wide logo, and a very tall logo, since merchants will not all provide well-cropped assets. Theme color applies to accents (buttons, countdown bar) only, never to text-on-background contrast in a way that breaks legibility; enforce a minimum contrast ratio server-side rather than trusting whatever hex a merchant enters.

**URL / address of the checkout itself**
- `merchants.checkout_subdomain` gives every merchant a stable, brandable URL of the form `<subdomain>.pay.elektron-net.org` with no extra infrastructure work: one wildcard TLS certificate for `*.pay.elektron-net.org` covers all of them.
- `merchants.custom_domain` (e.g. `pay.merchant-shop.com`) is a further step: the merchant points a CNAME at the server, and the server MUST provision and renew a TLS certificate for that domain automatically (ACME HTTP-01 or DNS-01 challenge) before routing traffic to it. This is materially more infrastructure than the subdomain case (certificate issuance, renewal, and domain-ownership verification per merchant) and SHOULD be treated as a distinct, later milestone rather than bundled into the first release; see the open question in section 26.
- `merchants.success_url` and `merchants.cancel_url` let the merchant's own site regain control of the buyer's browser once an order finishes or is abandoned, independent of which checkout URL was used to get there.

**Text customization**
- `merchants.text_overrides` is a per-locale key/value map using the same keys `I18n\MessageCatalog` already defines (see section 18 for the full multilingual design). The checkout renderer MUST look up a merchant's override for the buyer's active locale first, then the merchant's own default locale, then the catalog's default text, in that order.
- Overrides MUST be validated against the same placeholder set the catalog entry expects (e.g. an override for a string that includes `{amount}` must not drop that placeholder), so a merchant cannot accidentally ship a checkout page with a broken or missing value.

**Checklist for this section:**
- [ ] Subdomain-based branding shipped in the first release (low infrastructure cost, immediate value)
- [ ] Custom-domain support scoped as its own milestone once ACME automation is in place
- [ ] Text-override validation against the placeholder set of each `I18n\MessageCatalog` key
- [ ] Redirect URLs (`success_url`/`cancel_url`) restricted to `https://` targets to avoid an open redirect via a merchant-controlled field

## 18. Internationalization (i18n)

Not yet properly covered before this revision, and worth stating explicitly since it is a hard requirement: **multilingual support is a MUST**, not a later add-on.

**Where the project stands today.** `core/I18n/MessageCatalog` only defines the canonical key list and an English fallback string per key (see `shared/README.md`). The actual translated content for the ten-plus languages this suite already ships (see the `osclass-escrow` commit history, "Add ten more languages to osclass-escrow" and the earlier `en_US` addition) lives in `osclass-escrow/languages/*.po`/`*.mo`, wired through Osclass's own translation system. That translation system does not exist once `osclass-escrow` is no longer the thing rendering the checkout page, so the standalone server needs its own equivalent, not a reference to Osclass's.

**Plan:**
- Add a `pay-server`-owned locale store (plain PHP/JSON arrays per locale is enough; gettext is not required once Osclass's own tooling is out of the picture), keyed identically to `I18n\MessageCatalog`'s keys, so every existing translated string can be carried over mechanically instead of retranslated from scratch.
- Import the existing `.po` content for every language `osclass-escrow` already ships as the starting point for `pay-server`'s own catalog, then keep both in sync going forward (or, once `osclass-escrow` becomes a thin client per section 22, retire its own copies and have it defer to `pay-server` entirely for anything buyer-facing).
- Locale resolution order for a buyer-facing string: merchant text override (buyer's locale) -> merchant text override (merchant's own default locale) -> `pay-server` catalog (buyer's locale) -> `pay-server` catalog (English). This is the same fallback chain named in section 17, spelled out here in full.
- Buyer locale detection: `Accept-Language` header as the initial signal, with an explicit language switcher on the checkout page so a buyer is never stuck with a wrong guess; the chosen locale MUST persist for the rest of that order's session.
- This applies to every buyer-facing surface, not just the checkout page: order-status page, funded/reminder emails, and webhook-triggered notification templates all resolve through the same chain.
- The admin UI (merchant-facing, section 4/17 settings, branding, order list) is a separate, smaller translation surface from the buyer-facing catalog; it MAY ship with fewer languages initially without blocking the buyer-facing requirement.
- Right-to-left language support (if any target language needs it) affects the checkout layout (section 19) directly: mirroring, not just string substitution, so this MUST be confirmed against the actual language list before the checkout UI's CSS is finalized.

**Checklist for this section:**
- [ ] `pay-server`'s own locale store created, seeded from `osclass-escrow/languages/*.po` for every language already translated
- [ ] Full fallback chain (merchant override -> merchant default locale -> catalog buyer locale -> catalog English) implemented once, reused by checkout, order-status, and notifications alike
- [ ] Language switcher on the checkout page, persisted per order session
- [ ] Placeholder validation (section 17) applied uniformly across every locale, not just the merchant's override language
- [ ] Confirm whether any target language requires right-to-left layout support before the checkout CSS is finalized

## 19. Frontend and UI direction

A slim, modern checkout interface: minimal chrome, large QR/amount display, clear countdown for escrow timeouts (reusing the existing "one countdown at a time, T1 then T2" behavior described in the root README), fully responsive rather than a separate mobile template. Recommendation: a single-page checkout view per order (no multi-step wizard for the common case), with the PSBT-signing step only appearing when an order actually reaches that stage.

## 20. Escrow mode: implemented but disabled at launch

Escrow code stays exactly where it already lives in `core/` (`Escrow/`, `Psbt/`) and is carried into `pay-server` untouched; nothing is deleted, commented out, or moved to a separate branch. What changes is that it is not reachable through any active path at launch:

- **Config:** a single server-wide flag, e.g. `ESCROW_ENABLED` (default `false`). This is a server-wide switch for the first release, not a per-merchant one; see the open question in section 26 on whether a later per-merchant toggle is worth adding once escrow is turned back on generally.
- **API layer:** `POST /v1/orders` MUST reject `mode: "escrow"` with a clear, typed error (e.g. `escrow_disabled`) while the flag is off, rather than silently accepting it or 500-ing. The `orders.mode` column and every escrow-specific column (`buyer_pubkey`, `seller_pubkey`, `t1_seconds`, `t2_seconds`) stay in the schema unchanged from section 4; only the code path that would populate them is gated.
- **Admin UI:** merchant settings for escrow (T1/T2 defaults, the guardrail validation on them) are hidden, not just disabled/greyed out, while the flag is off, so the interface does not advertise a mode that cannot actually be used.
- **Checkout UI:** since no escrow orders can exist while the flag is off, the PSBT-signing step, the session-handoff QR flow (section 10, part B), and the T1/T2 countdown display never render at all; only the plain payment QR (section 10, part A) is reachable.
- **Watcher:** the T1/T2 reminder logic in `pay-watcher` simply never fires, since no order can carry a non-null `t1_seconds`/`t2_seconds` while the flag is off; no special-casing needed there beyond the API-layer gate already stopping such orders from being created.

This keeps re-enabling escrow later a config change plus UI/API un-hiding, not a re-implementation.

**Checklist for this section:**
- [ ] `ESCROW_ENABLED` flag wired into the API validation layer, the admin UI, and the checkout UI as the single source of truth (no separate flags per layer that could drift out of sync)
- [ ] Automated test confirming `POST /v1/orders` with `mode: "escrow"` returns the typed error while the flag is off
- [ ] Admin UI escrow settings fully hidden (not merely disabled) while the flag is off

## 21. Admin and buyer interfaces

Yes: two distinct surfaces, with different audiences, different auth models, and different lifetimes.

**Admin view (merchant-facing).** A dashboard the merchant logs into to manage their own account: order list and history, branding (section 17: display name, logo, theme, subdomain/custom domain, text overrides), API key management (view masked key, regenerate, revoke), webhook URL and secret configuration, chain endpoint overrides (section 7), and, once section 20's flag is lifted, escrow T1/T2 settings. This needs its own login, separate from the API key: the API key authenticates server-to-server calls (section 5), but a human sitting down at a dashboard needs a normal login (email plus password, or a magic link) rather than pasting a bearer token into a browser. This implies a `merchant_users` concept distinct from the `merchants` record itself (one merchant account MAY have more than one human user logging into it); see section 4 for the table.

**User view (buyer-facing).** The checkout and order-status pages from section 10/19: no login, no account, no history across orders. The order id itself (embedded in the checkout URL) is the buyer's only credential, exactly like any other hosted-checkout link; a buyer is a one-off visitor to a specific order, not a persisted identity in this system. Nothing in this view should imply otherwise (no "create an account" prompt, no buyer-side order history across merchants).

**Checklist for this section:**
- [ ] `merchant_users` table (section 4) wired into a real login flow, distinct from the merchant's API key
- [ ] Admin login mechanism decided (password-based, magic link, or both) and rate-limited independently from the buyer-facing API
- [ ] Confirm no buyer-facing page ever asks a buyer to authenticate beyond the order id itself

## 22. Migration path for `osclass-escrow`

The Osclass adapter does not need to disappear; it becomes a thin client:
- `checkout.php` (controller) changes from building the escrow address and order locally to calling `POST /v1/orders` and redirecting to the returned `checkout_url`.
- `payment-watcher.php` and `psbt.php` in the current adapter become unnecessary; that logic now lives in `pay-watcher` and `pay-api`.
- `wallet.php` (pubkey format validation) can stay client-side for a quick UX check, but the server MUST re-validate independently rather than trusting the plugin.
- Existing orders created under the old, plugin-local flow should be allowed to finish under the old code path rather than being force-migrated; only new orders go through the new API.

## 23. GitHub workflows and CI/CD changes

The existing `release-osclass-escrow.yml` MUST stay untouched in behavior and MUST be scoped so a `pay-server` change never triggers it and vice versa (path filters), matching the existing convention of one release workflow per platform adapter with its own tag prefix.

**New: `.github/workflows/ci-pay-server.yml`**
- Trigger: pull requests touching `pay-server/**` or `core/**`.
- Steps: install Composer dependencies, run static analysis and the test suite for both `core/` and `pay-server/`.

**New: `.github/workflows/release-pay-server.yml`**
- Trigger: tag matching `pay-v*` (e.g. `pay-v0.1.0`), plus `workflow_dispatch` for a throwaway build.
- Steps: build the `pay-api` and `pay-watcher` Docker images, tag them with the release version, push to the chosen container registry, attach build metadata to a GitHub Release the same way `release-osclass-escrow.yml` attaches its zip.

**Update: existing `release-osclass-escrow.yml`**
- Add a `paths:` filter so it only triggers on changes under `osclass-escrow/**` and whatever subset of `core/` it actually consumes; without this, any `pay-server` change touching `core/` risks triggering an unrelated Osclass release build.

**Checklist for this section:**
- [ ] Decide on the container registry target (GHCR is the natural default given everything else already lives on GitHub)
- [ ] Add `paths:` filters to `release-osclass-escrow.yml` if not already present
- [ ] Add a matrix or separate jobs for `pay-api` versus `pay-watcher` in `release-pay-server.yml`, since they are separate images with separate Dockerfiles

## 24. Security considerations

- The server MUST NOT ever hold a private key belonging to a buyer or seller; this is unchanged from the existing trust model and MUST be preserved as the standalone server takes over what the plugin used to do.
- API keys stored as hashes only, never plaintext, same treatment as `webhook_secret`.
- Webhook payloads signed (HMAC) so a merchant's endpoint can verify authenticity.
- `PsbtSignatureInspector` (existing, in `core/`) MUST remain the gate for any PSBT accepted back from a party: only new signatures under one of the order's own two pubkeys may be added, exactly as it already enforces today.

## 25. Overall checklist

- [ ] `core/` extracted and confirmed platform-agnostic
- [ ] Data model created and migrated
- [ ] `POST /v1/orders` and `GET /v1/orders/{id}` implemented for direct mode first (simplest case), then escrow mode
- [ ] Watcher process implemented and deployed as its own container
- [ ] Checkout page: payment QR, device-aware presentation, session-handoff QR for the signing round trip
- [ ] Merchant branding (display name, logo, theme color, checkout subdomain, text overrides) wired into the checkout page and every buyer-facing notification
- [ ] Multilingual catalog seeded from `osclass-escrow`'s existing translations, with the full locale fallback chain implemented
- [ ] `ESCROW_ENABLED` flag gating API validation, admin UI, and checkout UI consistently, with escrow code untouched in `core/`
- [ ] No address ever reused across orders, in direct mode exactly as in escrow mode, enforced by a hard database constraint
- [ ] `order_messages` shipped as an append-only, timestamped record per order, readable identically by buyer and merchant, usable as a dispute reference
- [ ] `PriceFeedProviderInterface` defined; ELEK remains the sole working `base_currency` until a real feed is configured
- [ ] Order lifecycle states (new/processing/settled/expired/invalid), expiry, confirmation depth, and tolerance implemented as the single source of truth the watcher, API, and webhooks all agree on
- [ ] `merchant_api_keys` with scoped permissions replacing the single blanket key
- [ ] Refund address capture and merchant self-reported refund status for direct payments
- [ ] `payment_requests` implemented so every payment under a reusable link still spawns its own fresh, one-time order and address
- [ ] Admin dashboard (`merchant_users` login) and buyer-facing checkout kept as clearly separate surfaces with separate auth models
- [ ] `ci-pay-server.yml` and `release-pay-server.yml` added; `release-osclass-escrow.yml` path-scoped
- [ ] `osclass-escrow` migrated to call the new API instead of running the logic locally

## 26. Open questions

1. Exact base path confirmation for the three `mempool.elektron-net.org` forks (assumed `/api`, matching upstream mempool.space).
2. Container registry choice for the new Docker images.
3. Whether `ElectrumChainDataProvider` (for the three `electrs.*` instances) is a near-term or later milestone.
4. Whether existing in-flight Osclass orders need any bridging at all, or simply finish under the current code path untouched.
5. Whether merchant-owned custom domains (`merchants.custom_domain`) are in scope for the first release, or deferred until ACME-based automatic certificate provisioning is built (subdomain-based branding covers most of the same need with far less infrastructure).
6. Whether the full existing `osclass-escrow` language list is the required day-one scope for `pay-server`, or whether a smaller initial subset is acceptable with the rest following incrementally.
7. Whether escrow, once re-enabled, should be a single server-wide flag as designed here, or a per-merchant toggle (some merchants live with direct-only, others opt into escrow) - the latter needs an `escrow_enabled` column on `merchants` rather than one global flag.
8. Admin login mechanism: plain password, magic link, or both, and whether any form of two-factor is required given this dashboard controls webhook URLs and branding that buyers see.
9. Which price feed source (if any) should back `PriceFeedProviderInterface` once one becomes viable, given ELEK is not currently listed anywhere; this has no answer yet by necessity, not by oversight.
10. What server-wide defaults to ship for `order_expiry_minutes`, `default_required_confirmations`, and `underpayment_tolerance_percent` before any merchant has expressed a preference.

## Links

- Website: https://elektron-net.org
- X (Twitter): @elektronnet and @kutlusoy
- Telegram: @elektronnet
