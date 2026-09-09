-- Adds a small server-wide key/value settings store, plus a way to mark a
-- merchant_users row as a platform administrator.
--
-- Section 12's price feed is explicitly server-wide ("driven by the same
-- feed once one exists"), not a per-merchant setting like chain_endpoints,
-- so it does not belong on the merchants table; platform_settings holds it
-- (and any future server-wide, operator-editable setting) instead of
-- requiring a redeploy (env var) for every change. The env var
-- (PAY_SERVER_PRICE_FEED_ENDPOINTS) remains the seed default for a fresh
-- install with no row here yet.

CREATE TABLE platform_settings (
    key         VARCHAR(100) PRIMARY KEY,
    value       JSONB NOT NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- No new auth model: a platform admin logs in through the exact same
-- merchant_users login as any other admin user, this flag just also
-- grants access to the /admin/platform/* screens. Defaults to false so
-- existing merchant_users rows (and the vast majority of merchants, who
-- run someone else's deployment) are unaffected.
ALTER TABLE merchant_users ADD COLUMN is_platform_admin BOOLEAN NOT NULL DEFAULT false;
