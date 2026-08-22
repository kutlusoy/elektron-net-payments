# Elektron Net Payments, `osclass-escrow` API Specification

- **Version:** 0.1.0 (draft)
- **Date:** August 22, 2026
- **Audience:** Osclass developers, marketplace operators, plugin reviewers
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net), and [`mindstellar/shopclass`](https://github.com/mindstellar/shopclass) for every hook/helper cited below (see "Status" for why, and for what changed from the original `osclass/Osclass`)
- **Consumer of:** [`../shared`](../shared), the platform-agnostic escrow core
- **See also:** [`../README.MD`](../README.MD) for the escrow model and the timeout policy this plugin implements

---

This document specifies how the Osclass adapter plugin is wired into Osclass itself: which hooks it uses, its database schema, its routes, its admin settings, and its i18n conventions. Everything platform-agnostic (the escrow script, the timeout policy, chain-data access, the order state machine) is specified once in [`../shared/README.md`](../shared/README.md) instead of here; this document only covers what is specific to the Osclass integration.

## Status

Draft. Every Osclass integration point below (hooks, DAO usage, routes, i18n) has been checked directly against source, not documentation, and not assumed. Two things in the core it depends on are explicitly not production-ready yet, and this plugin inherits that limitation:

- `EscrowScriptBuilderInterface`'s reference implementation (script/address derivation) is not verified against a live Elektron Net node.
- `ReleasePsbtBuilderInterface` has no reference implementation yet, so "confirm receipt" records the status change but does not yet produce a real PSBT (see "Open Questions" below).

### Why the reference implementation is `mindstellar/shopclass`, not `osclass/Osclass`

The original `osclass/Osclass` repository has had no commits since July 2018; it is not a live target. The actively maintained continuation is Mindstellar's fork, renamed **Shopclass** (PHP 8.0+, its own composer.json, CI, and a modernised admin theme), which states plainly that it "keeps Osclass's plugin and theme APIs ... so existing extensions keep working." This plugin now targets Shopclass specifically, on the explicit basis that the original is dead and every real installation going forward will be a Shopclass one.

That compatibility promise was verified, not taken on faith, by cloning `mindstellar/shopclass` and checking every API this plugin touches against its actual source:

- **Unchanged, confirmed identical:** `osc_add_hook`, `osc_add_route` (same 8-parameter signature), `osc_register_plugin`, `osc_admin_menu_plugins`, `osc_admin_render_plugin_url`, the `DAO` base class (`setTableName`/`setPrimaryKey`/`setFields`/`findByPrimaryKey`/`updateByPrimaryKey`/`insert`/`update`/`delete`, all still validating field keys against `setFields()` exactly as before), the legacy `$this->dao` query builder (`select`/`from`/`where`/`whereIn`/`get`, including the CodeIgniter-style `'column <='` embedded-operator convention this plugin's DAOs use), the `DBRecordsetClass` result object (`row()`/`numRows()`/`result()`), `osc_csrf_check()`/`osc_csrf_token_form()`, the `t_item` schema (`fk_i_user_id`, `f_price`, `fk_c_currency_code`), and the custom-route security check in `CWebCustom` (rejects any path containing `/admin/`).
- **The `.mo` loading path was load-tested, not just read.** Shopclass parses translations with the real `gettext/gettext` Composer package (`Gettext\Translations::fromMoFile()`), not the hand-rolled reader the original used. Every one of this plugin's 12 compiled `.mo` files was loaded through that exact parser (`vendor/gettext/gettext` from a real Shopclass checkout) and every entry read back correctly, including the non-Latin scripts (Russian, Arabic, Chinese).
- **Broken, and fixed:** the `item_detail` hook was renamed to `show_item` (same `$item` argument; see `includes/hooks.php`). The `user_profile_form` hook, previously used to add the wallet-connect field to the profile-edit page, **does not exist in Shopclass at all**: its only call site in the original was Osclass's bundled fallback GUI theme, which Shopclass removed outright, and no core controller replaced it. Wallet connect is now its own route (`elektron_escrow_wallet`, see "Hooks used") rather than a field embedded in a page this plugin does not control; this is also more robust than the old approach, since it no longer depends on whatever the active theme's profile template happens to render.
- **Deprecated, not removed:** `Preference::insert()`/`update()`/`delete()` no longer exist as such; they still work via the inherited `DAO` methods (verified: the field keys this plugin passes are in `Preference`'s `setFields()` list, so `checkFieldKeys()` does not reject them), but bypass Shopclass's in-memory preference cache (`Preference::toArray()`, loaded once per request). This plugin now uses `Preference::replace($key, $value, $section, $type)` for every settings write, the same method the rest of Shopclass's own code uses, so a value written this request is never read back stale.

None of this was assumed from the "keeps the Osclass extension API" claim alone; every point above was checked against `mindstellar/shopclass`'s actual source before this plugin's code was written against it, precisely because getting a payments plugin's platform assumptions wrong is not the kind of thing to guess at.

## Plugin identity

- Deployed as `oc-content/plugins/osclass-escrow/` (this directory, copied or symlinked as-is).
- Entry point: `index.php` (Osclass loads exactly this file per active plugin).
- Translation domain: `osclass-escrow` (the `ELEKTRON_ESCROW_DOMAIN` constant, defined once in `index.php`).
- Admin preference section: `plugin-osclass-escrow`.

## Dependencies (Composer)

See `composer.json`. `vendor/` must be installed (`composer install` inside this directory) before the plugin is activated; `index.php` requires `vendor/autoload.php` unconditionally. Requires PHP 8.0+, matching Shopclass's own `composer.json`.

| Package | Used for |
|---|---|
| `elektron-net/payments-core` | Everything platform-agnostic; see `../shared/README.md`. Wired in via a local path repository during development. |
| `bitwasp/bitcoin` | Pulled in transitively by the core, for script/address construction. |
| `endroid/qr-code` | Renders the payment QR code on the checkout page. |

## Building a release zip

This directory as checked into the repository is source, not an installable plugin: it has no `vendor/`, since that would mean committing every dependency's code into version control. Turning it into something that can be extracted straight into `oc-content/plugins/osclass-escrow/` on a real Osclass site means running Composer first. Two ways to get there:

### Automated (GitHub Actions)

Pushing a tag matching `osclass-v*` (e.g. `osclass-v0.1.0`) runs [`.github/workflows/release-osclass-escrow.yml`](../.github/workflows/release-osclass-escrow.yml), which builds the zip via the script below and attaches it to a new GitHub Release. Running that workflow manually (`workflow_dispatch`, with a version string of your choice) instead uploads the zip as a downloadable Actions artifact, useful for trying a build before committing to a real tag.

### Automated (local script)

From the repository root:

```bash
bash scripts/build-osclass-plugin.sh 0.1.0
```

Produces `dist/osclass-escrow-0.1.0.zip`. This is exactly what the GitHub Action runs; read it if you want to see precisely what the manual steps below automate.

### Manual (no script, no CI)

If you would rather not run someone else's shell script, or need to adapt a step for your own setup, here is every step it performs, spelled out:

1. **Copy both directories, keeping their relative layout.** `osclass-escrow/composer.json` depends on the shared core via a `"../shared"` path repository, so copy `osclass-escrow/` and `shared/` side by side into a scratch location (not in place inside your git checkout, so a mistake here cannot dirty your working tree):
   ```bash
   mkdir -p /tmp/elektron-build && cp -a osclass-escrow /tmp/elektron-build/ && cp -a shared /tmp/elektron-build/
   cd /tmp/elektron-build/osclass-escrow
   ```
2. **Install dependencies without dev packages:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
   `osclass-escrow/composer.json` sets `"symlink": false` on the path repository, so this copies the shared core's actual files into `vendor/elektron-net/payments-core` rather than creating a symlink; a symlink into a sibling directory would stop resolving the moment this zip is extracted somewhere else.
3. **Recompile every translation.** Skip this step if you have not touched a `.po` file since the `.mo` files in this repository were last committed:
   ```bash
   for po in languages/*/messages.po; do
     msgfmt -o "$(dirname "$po")/messages.mo" "$po"
   done
   ```
   No `msgfmt`? Use the fallback compiler this repository ships instead: `php languages/po2mo.php <locale>/messages.po <locale>/messages.mo`, once per locale.
4. **(Optional) Stamp a version** into the `Version:` line of `index.php`'s plugin header, so the deployed copy reports the version you are shipping:
   ```bash
   sed -i -E "s/^Version: .*/Version: 0.1.0/" index.php
   ```
5. **Remove dev-only files** that a deployed copy does not need (`vendor/` already carries everything required at runtime):
   ```bash
   rm -f composer.json composer.lock languages/po2mo.php
   ```
6. **Zip it**, with `osclass-escrow/` itself as the top-level folder inside the archive, so extracting the zip directly into `oc-content/plugins/` produces `oc-content/plugins/osclass-escrow/`:
   ```bash
   cd /tmp/elektron-build && zip -rq osclass-escrow-0.1.0.zip osclass-escrow
   ```

The resulting zip is what you upload through Osclass's own plugin installer, or extract manually into `oc-content/plugins/`.

## Hooks used

| Hook | Where | Purpose |
|---|---|---|
| `osc_register_plugin()` | `index.php` | Activation callback, `elektron_escrow_install()` (creates DB tables, seeds preference defaults). |
| `<plugin_path>_uninstall` | `index.php` | Deactivation callback, `elektron_escrow_uninstall()` (removes preferences, keeps the order table). |
| `<plugin_path>_configure` | `index.php` | "Configure" action in the admin plugin list, renders `admin/settings.php`. |
| `admin_menu_init` | `index.php` | Adds a dedicated "Elektron Net Escrow" entry under Admin > Plugins. |
| `show_item` | `includes/hooks.php` | Injects the "Buy with Elektron" / "View your order" widget on the item detail page. Receives the current item array; called `item_detail` with the same signature in the original Osclass, renamed in Shopclass (see "Status"). |
| `cron_daily` | `includes/hooks.php` | Daily sweep that raises `elektron_escrow_buyer_refund_reminder_due` / `elektron_escrow_seller_release_reminder_due` for orders whose T1/T2 reminder window (see `../shared`'s `ReminderScheduler`) is open. Actual notification delivery is a TODO, see below. |

Custom routes (via `osc_add_route()`, registered unconditionally at the top level of `index.php`, matching the pattern Osclass's own bundled `google_maps` plugin uses):

| Route id | URL | Handler | Notes |
|---|---|---|---|
| `elektron_escrow_checkout` | `elektron-escrow/checkout?item=<id>` | `controllers/checkout.php` | Creates or resumes the current buyer's order for one item. |
| `elektron_escrow_order_status` | `elektron-escrow/order?order=<id>` | `controllers/order-status.php` | Status/countdown page for either party; `$user_menu = true`, so it also appears inside the logged-in user's own menu. |
| `elektron_escrow_wallet` | `elektron-escrow/wallet` | `controllers/wallet.php` | The one-time wallet-connect page (see `includes/wallet.php`'s docblock, and "Status", for why this is a route rather than the `user_profile_form` hook the original Osclass used). `$user_menu = true`, same as order status. |

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

The buyer's own wallet connection is checked at checkout time instead (redirects to `elektron_escrow_wallet` if missing), since that is the point where it first matters for them.

Neither the order table nor the checkout flow uses Shopclass's native billing/credits system (`mindstellar\billing\Billing`, `Order`, `t_billing_order`): that subsystem is a user buying credits from the marketplace operator itself, later spent on premium listing features. There is no seller and no per-item payment in it, and its `Billing::markPaid()` mints credits to the buyer's own account rather than paying a different user, so it does not fit escrow (buyer pays a specific seller for a specific item; funds sit in a 2-of-2 address, not an account balance). See `install.php`'s docblock for the same note where it is architecturally relevant.

## Terminology and number formatting

Three distinct names, used consistently throughout this plugin's strings (see the whitepaper's "The Lydian Elektron" section for where they come from):

- **Elektron Net**: the network/protocol itself. Used for network-level things: wallets, nodes, public keys ("Connect an Elektron Net wallet...").
- **Elektron (ELEK)**: the currency unit. Used wherever an amount or a purchase is meant: "Buy with Elektron (ELEK)", "Elektron order".
- **lepton (lep)**: the subunit, 1 ELEK = 100,000,000 lepton. Only shown in the admin settings' fee-rate field (`lep/vByte`), never on a buyer/seller-facing page.

"Elektron Net Escrow" (the plugin's own name, in its admin pages and plugin header) is the one deliberate exception: it names the plugin itself, not a currency amount, the same way "Bitcoin Core" names software rather than an amount of BTC.

Amounts shown to buyers and sellers (currently just the checkout page's payment amount) go through `elektron_escrow_format_amount()` (`includes/formatting.php`), not a raw PHP float. It reads the current visitor's decimal point and thousands separator from Osclass's own per-locale `t_locale` settings (`osc_locale_dec_point()`, `osc_locale_thousands_sep()`), the same mechanism `osc_format_price()` uses for listing prices, so `1234.5` ELEK renders as `1,234.5` for an `en_US` visitor and `1.234,5` for a `de_DE` one. It is a separate function rather than a call to `osc_format_price()` itself: that helper divides by a hardcoded 1,000,000 to match Osclass's own generic `i_price` column, which is the wrong scale for lepton (100,000,000 per ELEK), and it always pads to a fixed decimal count where trimming trailing zeros reads better for a crypto amount.

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

Every write here (`install.php`'s defaults, and this save handler) uses `Preference::replace($key, $value, $section, $type)`, not the DAO-inherited `insert()`/`update()`; see "Status" above for why the latter would read back stale within the same request on Shopclass.

## i18n

Translation domain `osclass-escrow`, following Osclass's own gettext convention exactly: `__($text, 'osclass-escrow')` looks `$text` up as a `msgid` against `languages/<locale>/messages.mo`, compiled from the matching `messages.po`. Two things to know when adding a string:

1. A literal, hardcoded string passed straight to `__()`/`_e()` (form labels, error messages) needs its own `msgid`/`msgstr` pair added to **every** `languages/<locale>/messages.po`, then recompiled.
2. A string that also exists in the shared core's `ElektronNet\Payments\Core\I18n\MessageCatalog` (order-status text, reminder text) should go through `elektron_escrow_t($key, $vars)` (see `includes/i18n.php`) instead of a hardcoded literal, so the core's key list and this plugin's `.po` files describe the same string exactly once. `elektron_escrow_t()` looks up the catalog's English fallback text, translates it via `__()` like any other string, then fills in `{placeholder}` substitutions.

Twelve locales ship today, all fully translated (`en_US` carries identity translations, i.e. the source language itself): `en_US`, `de_DE`, `es_ES`, `pl_PL`, `it_IT`, `tr_TR`, `ru_RU`, `fr_FR`, `pt_BR`, `nl_NL`, `ar_SA`, `zh_CN`. Adding another locale means adding one more `languages/<locale>/messages.po` with the same `msgid`s.

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
4. **Wallet connect UX.** The public key field on the dedicated wallet-connect page (`views/wallet.php`) is a plain text input. A real wallet (browser extension, QR-based mobile companion) providing the key automatically, instead of the user copy-pasting a hex string, is a likely follow-up once such a wallet exists.
5. **`BitwaspEscrowScriptBuilder` and `ElektronNetworkFactory`** need verification against a live Elektron Net node/regtest before this plugin should be trusted with real funds; see both classes' docblocks in `../shared`.
