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

Draft. Every Osclass integration point below (hooks, DAO usage, routes, i18n) has been checked directly against source, not documentation, and not assumed. The same standard was applied to the escrow transaction logic itself after a direct question about it turned up real gaps: address uniqueness per order was not actually guaranteed (fixed, see `../shared/README.md`, "Escrow address correctness"), a stale/settled order could be resumed on repurchase (fixed, same section), the reference script builder called two bitwasp/bitcoin methods that do not exist in that library and would have thrown on every call (fixed, `../shared/README.md`, "Open items"), and "confirm receipt" had no protection against being started too close to the buyer's own refund deadline to be safe (fixed, see "Order lifecycle" below). A real install also turned up a fatal error: `index.php` used `osc_plugin_path(__FILE__)` (which returns the full path to `index.php` itself, confirmed from that helper's own source) to build paths to other files, producing e.g. `.../osclass-escrow/index.phpvendor/autoload.php` with no separator; fixed to `__DIR__`. Its two other uses in that file (`osc_plugin_configure`/`_uninstall` hook names) had a related but different bug -- they need the plugin's path *relative to* `PLUGINS_PATH` (confirmed against `Plugins::uninstall()` and `CAdminPlugins`'s "Configure" action, both of which strip that prefix before firing the hook), not the full path `osc_plugin_path(__FILE__)` returns either; also fixed. A real install then surfaced the plugin's single biggest gap: nothing actually linked to the wallet-connect page, so no seller could ever connect a wallet, so the "Buy" button never appeared for any listing (it hides itself when the seller has no connected pubkey, working exactly as designed against an environment where that could never become true). The root cause was a wrong assumption about `osc_add_route()`'s `$user_menu = true` flag, corrected by reading `Rewrite::addRoute()`/`CWebCustom.php`/`osc_private_user_menu()` directly: that flag only affects a route's own page chrome once visited, it does not add anything to the account menu. Fixed by adding a `user_menu_filter` hook (see "Hooks used"), the same real mechanism Shopclass's own billing feature uses for its account-menu links. A live install then showed the wallet-connect form giving no feedback at all on save (neither a success nor an error message, saved value not reflected back): both `controllers/wallet.php` and `controllers/order-status.php` reported their outcome via `osc_add_flash_ok_message()`/`osc_add_flash_error_message()` followed by a redirect back to the same route, but that message is only ever rendered by whatever the *active theme's* `custom.php`/`user-custom.php` template does with it -- and Shopclass ships with no bundled theme at all (`oc-content/themes/` is empty except for the standard security stub), so that rendering is entirely outside this plugin's control on any given site. Fixed by reporting the outcome directly in the same response instead (matching the pattern `$errorMessage` already used), which also means the saved public key is reflected back immediately, in the same request, with no redirect round trip for a theme or cache to get stale on.

### The save still didn't work: the live site is not actually Shopclass

Diagnosing the above did not fix a real install: submitting the wallet form still did nothing, on both a friendly-URL and a plain-query-string version of the same page, ruling out permalinks entirely. Adding temporary debug output (since removed) showed the actual request: `save` and the submitted public key both arrived correctly, but the CSRF fields never did. Viewing the live page's actual HTML source explained why: `<meta name="generator" content="Osclass 8.3.1" />`, footer "Powered by ... osclass" linking to `osclass-classifieds.com` -- **the deployed site runs that other, separately-maintained Osclass continuation** (the one from `osclass-classifieds.com` / SourceForge, mentioned early in this project's history and explicitly set aside once `mindstellar/shopclass` was identified as the target -- see "Why the reference implementation is `mindstellar/shopclass`" above), not `mindstellar/shopclass`. Its CSRF mechanism is a single `octoken` hidden field, not Shopclass's `CSRFName`/`CSRFToken` pair, confirmed directly in that page's rendered HTML.

`controllers/wallet.php`, `controllers/order-status.php`, and `admin/settings.php` all additionally required `Params::getParam('CSRFName') !== ''` before even attempting `osc_csrf_check()` -- hardcoding Shopclass's own token field name as a precondition, when it should simply have trusted `osc_csrf_check()` (whatever its real implementation is on whichever platform is actually running) to reject a missing or invalid token itself. That guard was always false on this real deployment, so the save logic never ran at all. Fixed by dropping the field-name check everywhere it appeared and calling `osc_csrf_check()` unconditionally once the action's own marker field (`save`/`confirm_receipt`) matches.

**This is not fully resolved, only unblocked.** The fix above is genuinely platform-agnostic and correct regardless of which Osclass-family platform is deployed, but every *other* Shopclass-specific verification in this document (`show_item` vs `item_detail`, `Preference::replace()`, the `DAO` base class, `user_menu_filter`, `osc_add_route()`'s `$user_menu` flag, and everything else checked against a `mindstellar/shopclass` checkout throughout this README) has **not** been re-verified against `osclass-classifieds.com`'s actual source, because that source has not been made available to check against -- unlike every other claim in this document, which was checked against real, present source before being written down. Some of it demonstrably already works on the live site regardless (the account-menu link and the item-widget both render correctly, per direct observation), which suggests this platform kept close compatibility with the same Osclass plugin API Shopclass also preserved -- but "demonstrably works" and "verified against source" are not the same standard this document holds everywhere else, and payments code should not run on the weaker one. Whether to invest in checking `osclass-classifieds.com`'s actual source the same way, or to confirm `mindstellar/shopclass` is still the intended production target and treat this site as a compatibility-testing environment only, is an open decision, not resolved here.

Two things in the core it depends on are still explicitly not production-ready, and this plugin inherits that limitation:

- `EscrowScriptBuilderInterface`'s reference implementation (script/address derivation) has been checked line-by-line against bitwasp/bitcoin's actual source, but is not verified against a live Elektron Net node.
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
| `user_menu_filter` | `includes/hooks.php` | Adds the wallet-connect page to the logged-in user's own account menu, the same mechanism Shopclass's own billing feature uses for its "Credits"/"Buy credits" links (`hBilling.php`). This is the actual, only thing that makes the wallet page reachable through normal navigation; see "Status" for the bug this fixed. |
| `cron_daily` | `includes/hooks.php` | Daily sweep that raises `elektron_escrow_buyer_refund_reminder_due` / `elektron_escrow_seller_release_reminder_due` for orders whose T1/T2 reminder window (see `../shared`'s `ReminderScheduler`) is open. Actual notification delivery is a TODO, see below. |

Custom routes (via `osc_add_route()`, registered unconditionally at the top level of `index.php`, matching the pattern Osclass's own bundled `google_maps` plugin uses):

| Route id | URL | Handler | Notes |
|---|---|---|---|
| `elektron_escrow_checkout` | `elektron-escrow/checkout?item=<id>` | `controllers/checkout.php` | Creates or resumes the current buyer's order for one item. |
| `elektron_escrow_order_status` | `elektron-escrow/order?order=<id>` | `controllers/order-status.php` | Status/countdown page for either party. `$user_menu = true` only affects this page's own chrome once visited (see "Status"); it is not linked from the account menu itself, only reached from the checkout/item-widget "View your order" link. |
| `elektron_escrow_wallet` | `elektron-escrow/wallet` | `controllers/wallet.php` | The one-time wallet-connect page (see `includes/wallet.php`'s docblock, and "Status", for why this is a route rather than the `user_profile_form` hook the original Osclass used). Reachable via the account menu through the separate `user_menu_filter` hook above, not through `$user_menu = true` itself (see "Status"). |

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

`views/wallet.php` includes a collapsible "How do I find my public key?" help block with the actual two-command flow (`getnewaddress` then `getaddressinfo "<address>"`) and a real example of each command's output, since this is the one step a non-technical user is most likely to get stuck on. The two RPC commands work identically whether typed into a GUI wallet's built-in Console or run with `elektron-cli` in a terminal, so one example covers both.
| `dt_pub_date` | `DATETIME` | |

### Changing a connected public key

`elektron_escrow_save_user_pubkey()` (`includes/wallet.php`) refuses to actually change an already-saved key while `EscrowOrderDAO::hasActiveOrderForUser()` finds any non-terminal order for that user, as buyer or as seller (re-saving the identical key is always allowed, since it is not a change). This is a UX safeguard, not a cryptographic one, and the distinction matters: every order's `buyer_pubkey`/`seller_pubkey`/`redeem_script_hex` is copied once, at order creation, and never re-read from `elektron_escrow_wallet` afterward (see "Database schema" above), so this table's contents have **no effect at all** on any already-created order's actual on-chain address or script, whether the block exists or not. What the block actually prevents is a user connecting a *different* wallet mid-order and then discovering that the order they still have open needs a signature from the *old* key they just replaced, since the escrow script is permanently bound to whichever pubkeys were current at that order's creation. A wallet that is genuinely gone (lost, reset) makes that specific order's cooperative-release and both timeout-fallback paths unrecoverable regardless of what this plugin's own connected-key setting is changed to afterward; the block exists to stop that from happening by mistake, not because it is fixable after the fact.

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
| Fee rate (lep/vByte) | `fee_rate_lep_per_vbyte` | `10` (fallback only -- see below) |
| Buyer refund threshold, T1 (days) | `buyer_refund_days` | `30` |
| Seller release threshold, T2 (days) | `seller_release_days` | `60` |

The fee-rate field is a fallback, not the value actually used: whichever `ReleasePsbtBuilderInterface` implementation eventually ships should request a live estimate through `ChainData\FeeRateResolver::resolve()` first (see `../shared/README.md`, "Open items") and fall back to this admin-configured value only when no configured chain-data endpoint can currently supply one.

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

1. **Checkout** (`controllers/checkout.php`): on first visit for a given (item, buyer) pair, generates a fresh random per-order nonce and derives a fresh, guaranteed-unique `EscrowAddress` from it plus both parties' stored public keys and the configured `TimeoutPolicy` (see `../shared/README.md`, "Escrow address correctness"), then inserts the order row with `status = awaiting_payment`. Revisiting the same checkout URL resumes the existing *non-terminal* order instead of creating a second one; a settled (released/refunded/claimed) order from an earlier purchase of the same item is never resumed -- a fresh order, and a fresh address, is created instead. Known limitation: two concurrent first-visit requests can each create their own order before either finishes (see `../shared/README.md`, "Open items"); this produces at most a harmless duplicate "awaiting payment" order, not an address collision or fund-loss risk.
2. **Watching for payment**: not implemented as a background daemon yet in this draft; see "Open Questions". Once implemented, it moves the status through `confirming` to `funded` using `elektron_escrow_chain_data()` (a `FallbackChainDataProvider` built from the admin-configured endpoint list) and the configured confirmation threshold.
3. **Confirm receipt** (`controllers/order-status.php`): the buyer's only action. Moves the status to `release_pending_seller_signature` via `OrderStateMachine::canTransition()`, but only once `TimeoutPolicy::canSafelyStartRelease()` also holds. That second check exists because "confirm receipt" is purely a database status change with no on-chain effect at all: the buyer's own timeout-refund path stays spendable by the buyer alone from T1 onward regardless of what this plugin's status column says, so starting a release too close to T1 risks the seller being told funds are on the way while the buyer can still reclaim them first (an honest delay on the seller's side -- their signing device offline, asleep -- is enough, no bad faith required). Within `TimeoutPolicy::MIN_CONFIRM_RECEIPT_SAFETY_MARGIN_DAYS` of T1, the action is refused outright with a translated error, not merely discouraged. Does **not** yet build a real release PSBT (see "Open Questions").
4. **Reminders**: `includes/hooks.php`'s `cron_daily` hook identifies which orders have a reminder due; delivering that reminder (email, in-portal message) is a TODO, see below.

## Uninstall behavior

Deactivating the plugin (`elektron_escrow_uninstall()`) deletes its admin preferences only. Both database tables are kept deliberately: `elektron_escrow_order` is transaction history, and `elektron_escrow_wallet` is a user's own saved setting, not plugin configuration. An operator who wants them gone drops them manually.

## Open Questions

1. **Release PSBT construction.** `ReleasePsbtBuilderInterface` (in `../shared`) has no implementation yet. Until it does, "confirm receipt" changes the order's status but produces no PSBT, and there is nothing yet for `PsbtRelay` to actually carry between the two wallets.
2. **Payment watching.** Nothing in this draft polls `elektron_escrow_chain_data()` on a schedule to move orders from `awaiting_payment` through `confirming` to `funded`. Likely a `cron_hourly` (or more frequent, if Osclass's cron granularity allows) hook in `includes/hooks.php`, mirroring the `cron_daily` reminder sweep.
3. **Reminder delivery.** `cron_daily` already identifies which orders have a reminder due (see "Order lifecycle" above); nothing sends it yet. Likely Osclass's own outgoing-mail helpers, matching how core sends its own `hook_email_*` notifications.
4. **Wallet connect UX.** The public key field on the dedicated wallet-connect page (`views/wallet.php`) is a plain text input. A real wallet (browser extension, QR-based mobile companion) providing the key automatically, instead of the user copy-pasting a hex string, is a likely follow-up once such a wallet exists.
5. **`BitwaspEscrowScriptBuilder` and `ElektronNetworkFactory`** need verification against a live Elektron Net node/regtest before this plugin should be trusted with real funds; see both classes' docblocks in `../shared`. (Both have since been checked against bitwasp/bitcoin's own source, which is a different, narrower kind of verification -- see `../shared/README.md`, "Open items" -- and is not a substitute for this one.)
6. **Duplicate order on a concurrent double-submit.** Two checkout requests for the same (item, buyer) pair arriving before the first finishes inserting can each create their own order; see `../shared/README.md`, "Open items", for why a plain unique index does not fix this cleanly and what actually would.
