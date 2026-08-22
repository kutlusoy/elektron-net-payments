# Elektron Net Payments, `osclass-escrow` API Specification

- **Version:** 0.1 (draft)
- **Date:** August 22, 2026
- **Audience:** Osclass developers, marketplace operators, plugin reviewers
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net), and [`osclass/Osclass`](https://github.com/osclass/Osclass) for every hook/helper cited below
- **Consumer of:** [`../shared`](../shared), the platform-agnostic escrow core
- **See also:** [`../README.MD`](../README.MD) for the escrow model and the timeout policy this plugin implements

---

This document specifies how the Osclass adapter plugin is wired into Osclass itself: which hooks it uses, its database schema, its routes, its admin settings, and its i18n conventions. Everything platform-agnostic (the escrow script, the timeout policy, chain-data access, the order state machine) is specified once in [`../shared/README.md`](../shared/README.md) instead of here; this document only covers what is specific to the Osclass integration.

## Status

Draft. Every Osclass integration point below (hooks, DAO usage, routes) has been checked against the actual `osclass/Osclass` source, not just its documentation. Two things in the core it depends on are explicitly not production-ready yet, and this plugin inherits that limitation:

- `EscrowScriptBuilderInterface`'s reference implementation (script/address derivation) is not verified against a live Elektron Net node.
- `ReleasePsbtBuilderInterface` has no reference implementation yet, so "confirm receipt" records the status change but does not yet produce a real PSBT (see "Open Questions" below).

## Plugin identity

- Deployed as `oc-content/plugins/osclass-escrow/` (this directory, copied or symlinked as-is).
- Entry point: `index.php` (Osclass loads exactly this file per active plugin).
- Translation domain: `osclass-escrow` (the `ELEKTRON_ESCROW_DOMAIN` constant, defined once in `index.php`).
- Admin preference section: `plugin-osclass-escrow`.

## Dependencies (Composer)

See `composer.json`. `vendor/` must be installed (`composer install` inside this directory) before the plugin is activated; `index.php` requires `vendor/autoload.php` unconditionally.

| Package | Used for |
|---|---|
| `elektron-net/payments-core` | Everything platform-agnostic; see `../shared/README.md`. Wired in via a local path repository during development. |
| `bitwasp/bitcoin` | Pulled in transitively by the core, for script/address construction. |
| `endroid/qr-code` | Renders the payment QR code on the checkout page. |

## Hooks used

| Hook | Where | Purpose |
|---|---|---|
| `osc_register_plugin()` | `index.php` | Activation callback, `elektron_escrow_install()` (creates DB tables, seeds preference defaults). |
| `<plugin_path>_uninstall` | `index.php` | Deactivation callback, `elektron_escrow_uninstall()` (removes preferences, keeps the order table). |
| `<plugin_path>_configure` | `index.php` | "Configure" action in the admin plugin list, renders `admin/settings.php`. |
| `admin_menu_init` | `index.php` | Adds a dedicated "Elektron Net Escrow" entry under Admin > Plugins. |
| `item_detail` | `includes/hooks.php` | Injects the "Pay with Elektron Net" / "View your order" widget on the item detail page. Receives the current item array, per Osclass core (`osc_run_hook('item_detail', osc_item())`). |
| `cron_daily` | `includes/hooks.php` | Daily sweep that raises `elektron_escrow_buyer_refund_reminder_due` / `elektron_escrow_seller_release_reminder_due` for orders whose T1/T2 reminder window (see `../shared`'s `ReminderScheduler`) is open. Actual notification delivery is a TODO, see below. |
| `user_profile_form` | `includes/wallet.php` | Adds the one-time "Elektron Net public key" field to the user's own profile form. Receives the current user array. |
| `user_edit_completed` | `includes/wallet.php` | Validates and stores that field. Receives the user id. |

Custom routes (via `osc_add_route()`, registered unconditionally at the top level of `index.php`, matching the pattern Osclass's own bundled `google_maps` plugin uses):

| Route id | URL | Handler | Notes |
|---|---|---|---|
| `elektron_escrow_checkout` | `elektron-escrow/checkout?item=<id>` | `controllers/checkout.php` | Creates or resumes the current buyer's order for one item. |
| `elektron_escrow_order_status` | `elektron-escrow/order?order=<id>` | `controllers/order-status.php` | Status/countdown page for either party; `$user_menu = true`, so it also appears inside the logged-in user's own menu. |

## Database schema

Two tables, entirely owned by this plugin; Osclass's own tables (`t_item`, `t_user`) are only ever read, never modified. Created by `install.php`, dropped by nothing (see "Uninstall behavior" below).

### `elektron_escrow_order`

| Column | Type | Notes |
|---|---|---|
| `pk_i_id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key. |
| `fk_i_item_id` | `INT UNSIGNED` | The `t_item` being purchased. |
| `fk_i_buyer_id` | `INT UNSIGNED` | The `t_user` buying. |
| `fk_i_seller_id` | `INT UNSIGNED` | Copied from the item's `fk_i_user_id` at order creation. |
| `buyer_pubkey`, `seller_pubkey` | `VARCHAR(66)` | Compressed public keys, hex. |
| `s_address` | `VARCHAR(90)` | The escrow address, from `EscrowAddress::address()`. |
| `redeem_script_hex` | `TEXT` | From `EscrowAddress::redeemScriptHex()`. Frozen at creation, never recomputed; see `../README.MD`, "Values are frozen per order". |
| `buyer_refund_locktime`, `seller_release_locktime` | `INT UNSIGNED` | Unix timestamps, from `EscrowAddress`. Also frozen. |
| `amount_lep` | `BIGINT UNSIGNED` | The item's price converted to lep (1 ELEK = 100,000,000 lep), at order creation time. |
| `status` | `VARCHAR(40)` | One of `ElektronNet\Payments\Core\Escrow\OrderStatus`'s constants. |
| `psbt_base64` | `MEDIUMTEXT NULL` | Set once a `PsbtRelay` blob exists for this order. |
| `psbt_signature_count` | `TINYINT` | Mirrors `PsbtRelay::signatureCount()`. |
| `dt_pub_date`, `dt_mod_date` | `DATETIME` | Standard Osclass-style timestamps. |

### `elektron_escrow_wallet`

| Column | Type | Notes |
|---|---|---|
| `fk_i_user_id` | `INT UNSIGNED` | Primary key; one row per user who has connected a wallet. |
| `pubkey_hex` | `VARCHAR(66)` | Compressed public key. Validated as `^(02\|03)[0-9a-fA-F]{64}$` before it is ever stored; a private key or seed phrase pasted here is rejected at that same check, not merely discouraged in the label text. |
| `dt_pub_date` | `DATETIME` | |

## Escrow eligibility

A listing is only ever offered escrow if **both** hold:

1. The item's `fk_c_currency_code` is `ELEK`. Converting a fiat-priced listing would need a live exchange rate, and no reliable ELEK price feed exists yet (see `elektron-net-mempool`'s README on why the fiat price feed is disabled). The marketplace operator adds `ELEK` as a currency in Osclass's own currency settings; sellers who want escrow price their listing in it directly.
2. The seller has a row in `elektron_escrow_wallet` (connected a wallet once, see "One-time wallet setup" in `../README.MD`).

The buyer's own wallet connection is checked at checkout time instead (redirects to the profile page if missing), since that is the point where it first matters for them.

## Admin settings

Rendered by `views/admin-settings.php`, saved by `admin/settings.php`. Every field maps 1:1 to a `plugin-osclass-escrow` preference (see `install.php` for defaults):

| Field | Preference name | Default |
|---|---|---|
| Network | `network` | `mainnet` |
| Chain-data endpoints (one per line) | `chain_data_endpoints` | `https://mempool.elektron-net.org/api`, `https://mempool2.elektron-net.org/api` |
| Confirmations required | `required_confirmations` | `3` |
| Fee rate (lep/vByte) | `fee_rate_lep_per_vbyte` | `10` |
| Buyer refund threshold, T1 (days) | `buyer_refund_days` | `30` |
| Seller release threshold, T2 (days) | `seller_release_days` | `60` |

Saving re-validates T1/T2 through `TimeoutPolicy::fromDays()` server-side (never trusts client-side form validation alone) and rejects the save with a flash error if the guardrails from `../README.MD`, "Escrow timeout policy", are violated. A change here only affects orders created afterward; see "Values are frozen per order" in the root README.

## i18n

Translation domain `osclass-escrow`, following Osclass's own gettext convention exactly: `__($text, 'osclass-escrow')` looks `$text` up as a `msgid` against `languages/<locale>/messages.mo`, compiled from the matching `messages.po`. Two things to know when adding a string:

1. A literal, hardcoded string passed straight to `__()`/`_e()` (form labels, error messages) needs its own `msgid`/`msgstr` pair added to **every** `languages/<locale>/messages.po`, then recompiled.
2. A string that also exists in the shared core's `ElektronNet\Payments\Core\I18n\MessageCatalog` (order-status text, reminder text) should go through `elektron_escrow_t($key, $vars)` (see `includes/i18n.php`) instead of a hardcoded literal, so the core's key list and this plugin's `.po` files describe the same string exactly once. `elektron_escrow_t()` looks up the catalog's English fallback text, translates it via `__()` like any other string, then fills in `{placeholder}` substitutions.

Two locales ship today: `en_US` (identity translations, i.e. the source language) and `de_DE` (fully translated). Adding a third locale means adding one more `languages/<locale>/messages.po` with the same `msgid`s.

`.po` files are the human-edited source; `.mo` files are what Osclass actually loads at runtime and must be recompiled after editing a `.po`. Prefer `msgfmt -o messages.mo messages.po` (part of standard gettext tooling); `languages/po2mo.php` is a fallback compiler for a machine without `msgfmt` installed (`php po2mo.php <locale>/messages.po <locale>/messages.mo`).

## Order lifecycle (Osclass-specific parts only)

The platform-agnostic lifecycle itself (`OrderStatus`, `OrderStateMachine`) is specified in `../shared/README.md`. In this adapter:

1. **Checkout** (`controllers/checkout.php`): on first visit for a given (item, buyer) pair, derives a fresh `EscrowAddress` from both parties' stored public keys and the configured `TimeoutPolicy`, and inserts the order row with `status = awaiting_payment`. Revisiting the same checkout URL resumes the existing order instead of creating a second one.
2. **Watching for payment**: not implemented as a background daemon yet in this draft; see "Open Questions". Once implemented, it moves the status through `confirming` to `funded` using `elektron_escrow_chain_data()` (a `FallbackChainDataProvider` built from the admin-configured endpoint list) and the configured confirmation threshold.
3. **Confirm receipt** (`controllers/order-status.php`): the buyer's only action. Moves the status to `release_pending_seller_signature` via `OrderStateMachine::canTransition()`. Does **not** yet build a real release PSBT (see "Open Questions").
4. **Reminders**: `includes/hooks.php`'s `cron_daily` hook identifies which orders have a reminder due; delivering that reminder (email, in-portal message) is a TODO, see below.

## Uninstall behavior

Deactivating the plugin (`elektron_escrow_uninstall()`) deletes its admin preferences only. Both database tables are kept deliberately: `elektron_escrow_order` is transaction history, and `elektron_escrow_wallet` is a user's own saved setting, not plugin configuration. An operator who wants them gone drops them manually.

## Open Questions

1. **Release PSBT construction.** `ReleasePsbtBuilderInterface` (in `../shared`) has no implementation yet. Until it does, "confirm receipt" changes the order's status but produces no PSBT, and there is nothing yet for `PsbtRelay` to actually carry between the two wallets.
2. **Payment watching.** Nothing in this draft polls `elektron_escrow_chain_data()` on a schedule to move orders from `awaiting_payment` through `confirming` to `funded`. Likely a `cron_hourly` (or more frequent, if Osclass's cron granularity allows) hook in `includes/hooks.php`, mirroring the `cron_daily` reminder sweep.
3. **Reminder delivery.** `cron_daily` already identifies which orders have a reminder due (see "Order lifecycle" above); nothing sends it yet. Likely Osclass's own outgoing-mail helpers, matching how core sends its own `hook_email_*` notifications.
4. **Wallet connect UX.** The public key field on the profile form (`views/wallet-field.php`) is a plain text input. A real wallet (browser extension, QR-based mobile companion) providing the key automatically, instead of the user copy-pasting a hex string, is a likely follow-up once such a wallet exists.
5. **`BitwaspEscrowScriptBuilder` and `ElektronNetworkFactory`** need verification against a live Elektron Net node/regtest before this plugin should be trusted with real funds; see both classes' docblocks in `../shared`.
