-- pay-server initial schema.
--
-- Implements the data model from doc-elektron/guideline-standalone-payment-server.md
-- section 4, plus the hard constraints that section's own checklists (4, 9)
-- require but leave to the migration layer to enforce:
--   - orders.address is UNIQUE (section 9: no address is ever reused across orders)
--   - orders.order_nonce_hex is UNIQUE (mirrors escrow-side uniqueness, section 4 note)
--   - a per-merchant monotonic child-derivation index (merchants.next_receiving_index,
--     section 9 checklist: "strictly monotonic child-derivation index per xpub, never
--     reused even for a cancelled or expired order")
--   - the double-submit race closed via a unique index on
--     (merchant_id, external_reference) where external_reference is set, plus the
--     application-level compare-and-set insert described in section 4's notes
--
-- Target: PostgreSQL (JSONB/TIMESTAMPTZ/UUID types below assume it).

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE merchants (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name                VARCHAR(255) NOT NULL,
    display_name        VARCHAR(255),
    webhook_url         TEXT,
    webhook_secret_hash VARCHAR(255),
    logo_url            TEXT,
    theme_color         VARCHAR(7),
    checkout_subdomain  VARCHAR(63) UNIQUE,
    custom_domain       VARCHAR(255) UNIQUE,
    receiving_xpub      VARCHAR(120),
    -- Section 9 checklist: strictly monotonic per-xpub child-derivation
    -- index, never reused even for a cancelled/expired order. Incremented
    -- with the row locked (SELECT ... FOR UPDATE) at order-creation time,
    -- never decremented or reused.
    next_receiving_index INTEGER NOT NULL DEFAULT 0,
    success_url         TEXT,
    cancel_url           TEXT,
    text_overrides       JSONB,
    default_t1_days      INTEGER NOT NULL DEFAULT 30,
    default_t2_days      INTEGER NOT NULL DEFAULT 60,
    chain_endpoints       JSONB,
    base_currency                    VARCHAR(10) NOT NULL DEFAULT 'ELEK',
    default_display_currency         VARCHAR(10),
    order_expiry_minutes             INTEGER NOT NULL DEFAULT 15,
    default_required_confirmations   INTEGER NOT NULL DEFAULT 1,
    underpayment_tolerance_percent   NUMERIC(5,2) NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_merchants_t2_ratio CHECK (default_t2_days >= default_t1_days * 1.5),
    CONSTRAINT chk_merchants_t1_min CHECK (default_t1_days >= 3),
    CONSTRAINT chk_merchants_t2_max CHECK (default_t2_days <= 183)
);

CREATE TABLE merchant_api_keys (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    merchant_id   UUID NOT NULL REFERENCES merchants(id),
    label         VARCHAR(255) NOT NULL,
    key_hash      VARCHAR(255) NOT NULL UNIQUE,
    -- Last 4 characters of the raw key, stored alongside the hash purely
    -- so the admin UI's key list (section 14) can show a merchant enough
    -- to recognize their own key ("...a1b2") without ever being able to
    -- reconstruct it; the raw key itself is never stored anywhere.
    key_suffix    VARCHAR(8) NOT NULL,
    scopes        JSONB NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at  TIMESTAMPTZ,
    revoked_at    TIMESTAMPTZ
);

CREATE INDEX idx_merchant_api_keys_merchant ON merchant_api_keys(merchant_id);

CREATE TABLE payment_requests (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    merchant_id   UUID NOT NULL REFERENCES merchants(id),
    amount_lep    BIGINT,
    currency      VARCHAR(10) NOT NULL,
    description   TEXT,
    expires_at    TIMESTAMPTZ,
    archived_at   TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_payment_requests_merchant ON payment_requests(merchant_id);

CREATE TABLE merchant_users (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    merchant_id     UUID NOT NULL REFERENCES merchants(id),
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE orders (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    merchant_id         UUID NOT NULL REFERENCES merchants(id),
    mode                VARCHAR(10) NOT NULL,
    status              VARCHAR(30) NOT NULL,
    amount_lep          BIGINT NOT NULL,
    address             VARCHAR(100) NOT NULL,
    buyer_pubkey        VARCHAR(66),
    seller_pubkey       VARCHAR(66),
    t1_seconds          INTEGER,
    t2_seconds          INTEGER,
    order_nonce_hex     VARCHAR(32) NOT NULL,
    external_reference  VARCHAR(255),
    expires_at              TIMESTAMPTZ NOT NULL,
    required_confirmations  INTEGER NOT NULL,
    fiat_currency           VARCHAR(10),
    fiat_amount             NUMERIC(20,2),
    exchange_rate_used      NUMERIC(20,8),
    refund_address          VARCHAR(100),
    payment_request_id      UUID REFERENCES payment_requests(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    funded_at           TIMESTAMPTZ,
    completed_at        TIMESTAMPTZ,
    CONSTRAINT chk_orders_mode CHECK (mode IN ('direct', 'escrow')),
    CONSTRAINT chk_orders_status CHECK (status IN ('new', 'processing', 'settled', 'expired', 'invalid')),
    -- Section 9: no address is ever reused across more than one order, for
    -- direct payments exactly as much as for escrow. Enforced here as a
    -- hard constraint, not merely an application-level convention.
    CONSTRAINT uq_orders_address UNIQUE (address),
    CONSTRAINT uq_orders_nonce UNIQUE (order_nonce_hex)
);

CREATE INDEX idx_orders_merchant ON orders(merchant_id);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_payment_request ON orders(payment_request_id);

-- Section 4 note: the double-submit race MUST be closed with a proper
-- unique constraint plus compare-and-set insert now that this is a real
-- service with its own transaction boundary. A merchant's own retry after
-- a client-side timeout (section 5 checklist: idempotency key support)
-- must not create two orders for the same external_reference while a
-- prior, non-terminal order for it still exists; a legitimate repurchase
-- after a terminal order must still be allowed. This partial unique index
-- enforces "at most one non-terminal order per (merchant, external
-- reference)" at the database layer; the API layer's compare-and-set
-- insert (see api/src/Http/Controllers/OrdersController.php) is what
-- actually resolves the race rather than merely detecting it after the
-- fact.
CREATE UNIQUE INDEX uq_orders_merchant_external_ref_open
    ON orders(merchant_id, external_reference)
    WHERE external_reference IS NOT NULL AND status IN ('new', 'processing');

CREATE TABLE order_messages (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id    UUID NOT NULL REFERENCES orders(id),
    sender      VARCHAR(10) NOT NULL,
    body        TEXT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_order_messages_sender CHECK (sender IN ('buyer', 'merchant'))
);

CREATE INDEX idx_order_messages_order ON order_messages(order_id, created_at);

CREATE TABLE order_events (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id    UUID NOT NULL REFERENCES orders(id),
    type        VARCHAR(50) NOT NULL,
    payload     JSONB,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_order_events_order ON order_events(order_id, created_at);
