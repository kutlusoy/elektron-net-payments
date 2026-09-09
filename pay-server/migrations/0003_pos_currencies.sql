-- A merchant-chosen list of fiat currencies accepted for live-converted
-- order creation (typed at /admin/terminal, or via POST /v1/orders with a
-- non-ELEK currency): section 12's "fiat as base currency" freeze
-- semantics (amount_lep/fiat_currency/fiat_amount/exchange_rate_used
-- frozen at creation via the price feed), applied per order rather than
-- only through the single, permanent merchants.base_currency switch --
-- a merchant can accept ELEK by default and still let staff charge an
-- occasional sale in EUR or USD at the till.
--
-- Empty by default and only meaningful once a price feed is configured
-- server-wide (see OrderValidation), matching every other price-feed-gated
-- field already in this schema (default_display_currency).

ALTER TABLE merchants ADD COLUMN enabled_fiat_currencies JSONB NOT NULL DEFAULT '[]'::jsonb;
