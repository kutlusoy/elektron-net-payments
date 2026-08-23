# Elektron Net Payments, `osclass-escrow` API Specification

- **Version:** 0.1.0 (draft)
- **Date:** August 23, 2026
- **Audience:** Osclass developers, marketplace operators, plugin reviewers
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net); every Osclass hook/helper cited below has been checked against both [`mindstellar/shopclass`](https://github.com/mindstellar/shopclass) and the operator's actual deployment target, `osclass v8.3.1` from [`osclass-classifieds.com`](https://osclass-classifieds.com/download) (see "Status" for the platform differences that matter)
- **Consumer of:** [`../shared`](../shared), the platform-agnostic escrow core
- **See also:** [`../README.MD`](../README.MD) for the escrow model and the timeout policy this plugin implements

---

This document specifies how the Osclass adapter plugin is wired into Osclass itself: which hooks it uses, its database schema, its routes, its admin settings, and its i18n conventions. Everything platform-agnostic (the escrow script, the timeout policy, chain-data access, the order state machine) is specified once in [`../shared/README.md`](../shared/README.md) instead of here; this document only covers what is specific to the Osclass integration.

## Status

Draft, but functionally complete for the core buy -> escrow -> confirm/refund/release flow. Every Osclass integration point (hooks, routes, DAO usage, CSRF, pricing, i18n) is verified directly against source, against both real platforms this plugin targets: [`mindstellar/shopclass`](https://github.com/mindstellar/shopclass) and the actual currently deployed `osclass v8.3.1` from [`osclass-classifieds.com`](https://osclass-classifieds.com/download). Detailed rationale for each platform-specific choice below lives in this repository's commit history, not repeated here.

Two things are not yet production-ready, and this plugin inherits that limitation:

- `EscrowScriptBuilderInterface`'s reference implementation (script/address derivation) is checked line-by-line against bitwasp/bitcoin's own source, but not verified against a live Elektron Net node.
- `ReleasePsbtBuilderInterface`'s reference implementation (`Bip174PsbtBuilder`, a hand-rolled BIP174 encoder since no PHP library implements PSBT at all) is verified against the official BIP174 test vector and end-to-end against the real bitwasp script interpreter, but likewise not yet verified against a live Elektron Net node with real wallets on both sides.

### Platform notes

- Hook names: `item_detail` (the hook the theme's own template fires, at the correct page position), not `show_item` (fires too early in the request lifecycle to place HTML correctly). `user_menu_filter` is the real mechanism for an account-menu link; `osc_add_route()`'s `$user_menu` flag does not add one by itself.
- CSRF: never assumes a specific token field name (some platforms use a `CSRFName`/`CSRFToken` pair, others a single `octoken`); always calls `osc_csrf_check()` unconditionally once an action's own marker field is present.
- This plugin's four custom-route controllers never call `osc_redirect_to()`: a redirect attempted from that specific rendering path cannot succeed on the real, currently deployed platform (real page markup is already buffered by the time a controller runs), so each renders its result directly in the same response instead (`includes/notice.php`, `includes/order-status.php`).
- Pricing reads `t_item.i_price` (scaled by 1,000,000); the also-present `f_price` column is never populated by real item-submission code.
- Currency eligibility is an admin-configurable list (`currency_codes` preference, default `ELE`/`ELK`), not a single hardcoded code: Osclass stores a currency code as `CHAR(3)`, so a 4-letter code can never be stored there.
- `.mo` loading (all 12 locales) is verified against the real `gettext/gettext` parser both platforms actually use.
- `user_profile_form`/`item_detail` still exist as hooks on the original Osclass lineage but not in Shopclass, which is why the wallet-connect page and the buy widget use their own dedicated mechanisms (a route, and the `item_detail` hook above) rather than relying on either.

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
| `endroid/qr-code` | Renders the payment QR code on the order-status page. |

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
| `item_detail` | `includes/hooks.php` | Injects the "Buy with Elektron" / "View your order" widget on the item detail page. Receives the current item array. **Not** `show_item`: that hook fires from the item controller's own `doModel()` (data-preparation) method, before the theme emits any page markup at all, so output echoed from it lands ahead of the entire page instead of anywhere inside it; `item_detail` is the hook the theme's own template actually calls at the intended position (confirmed against the real, deployed `osclass-classifieds.com` theme, `oc-content/themes/sigma/item.php`), see "Status". |
| `user_menu_filter` | `includes/hooks.php` | Adds the wallet-connect page and the "My Elektron orders" page to the logged-in user's own account menu, the same mechanism Shopclass's own billing feature uses for its "Credits"/"Buy credits" links (`hBilling.php`). This is the actual, only thing that makes either page reachable through normal navigation; see "Status" for the bug this fixed. |
| `cron_minutely` | `includes/hooks.php` | The payment watcher: moves each non-terminal order through `awaiting_payment` -> `confirming` -> `funded` by checking its own escrow address against `elektron_escrow_chain_data()`. See "Order lifecycle" and "Status". |
| `cron_daily` | `includes/hooks.php` | Daily sweep that raises `elektron_escrow_buyer_refund_reminder_due` / `elektron_escrow_seller_release_reminder_due` for orders whose T1/T2 reminder window (see `../shared`'s `ReminderScheduler`) is open. Actual notification delivery is a TODO, see below. |

Custom routes (via `osc_add_route()`, registered unconditionally at the top level of `index.php`, matching the pattern Osclass's own bundled `google_maps` plugin uses):

| Route id | URL | Handler | Notes |
|---|---|---|---|
| `elektron_escrow_checkout` | `elektron-escrow/checkout?item=<id>` | `controllers/checkout.php` | GET shows a no-side-effect preview (item, price, timeout policy); only a POST with `confirm_checkout=1` and a valid CSRF token creates the order, rendered directly in this same response (not a redirect, see "Status"). Renders the existing order's own details page the same way instead of re-showing the preview if the buyer already has a live order for this item. See "Status" for why a GET used to create the order outright. |
| `elektron_escrow_order_status` | `elektron-escrow/order?order=<id>` | `controllers/order-status.php` | The order's own permanent details page (item, amount, and per-status: payment address + QR code, countdown, or "confirm receipt") for either party. `$user_menu = true` only affects this page's own chrome once visited (see "Status"); it is not linked from the account menu itself, only reached from checkout, the item-widget "View your order" link, or the "My Elektron orders" page below. |
| `elektron_escrow_wallet` | `elektron-escrow/wallet` | `controllers/wallet.php` | The one-time wallet-connect page (see `includes/wallet.php`'s docblock, and "Status", for why this is a route rather than the `user_profile_form` hook the original Osclass used). Reachable via the account menu through the separate `user_menu_filter` hook above, not through `$user_menu = true` itself (see "Status"). |
| `elektron_escrow_orders` | `elektron-escrow/orders` | `controllers/orders.php` | "My Elektron orders": every order the logged-in user is a party to, as buyer or as seller, each linking to its own `elektron_escrow_order_status` page. Reachable via the account menu, same mechanism as the wallet page. See "Status" for why this exists. |

## Database schema

Two tables, entirely owned by this plugin; Osclass's own tables (`t_item`, `t_user`) are only ever read, never modified. Created by `install.php`, dropped by nothing (see "Uninstall behavior" below).

### `elektron_escrow_order`

| Column | Type | Notes |
|---|---|---|
| `pk_i_id` | `INT UNSIGNED AUTO_INCREMENT` | Primary key. |
| `fk_i_item_id` | `INT UNSIGNED` | The `t_item` being purchased. |
| `fk_i_buyer_id` | `INT UNSIGNED` | The `t_user` buying. |
| `fk_i_seller_id` | `INT UNSIGNED` | Copied from the item's `fk_i_user_id` at order creation. |
| `buyer_pubkey`, `seller_pubkey` | `VARCHAR(66)` | Compressed public keys, hex -- per-order child keys derived from each party's xpub (`XpubChildKeyDeriver`), never either party's registered xpub itself. |
| `buyer_pubkey_index`, `seller_pubkey_index` | `INT UNSIGNED` | The `XpubChildKeyDeriver` index each pubkey above was derived at. Not recoverable from the pubkey alone; needed later to embed a `PSBT_IN_BIP32_DERIVATION` entry for that key when building the release PSBT (`Bip174PsbtBuilder`). |
| `seller_payout_address` | `VARCHAR(90)` | Derived from the seller's xpub (fixed index, reused across all their orders -- see `controllers/checkout.php`) at order creation. This is where the release PSBT's output pays. |
| `seller_payout_index` | `INT UNSIGNED` | The index `seller_payout_address` was derived at (currently always `0`); stored explicitly rather than assumed again at PSBT-build time. |
| `buyer_shipping_name`, `buyer_shipping_address`, `buyer_shipping_phone`, `buyer_contact_email` | `VARCHAR`/`TEXT` | Collected at checkout (`views/checkout.php`), per order rather than once on the buyer's profile, since a buyer may ship to a different address next time. Shown to the seller (order-status page and the `funded` email) only once the order is no longer `awaiting_payment`/`confirming` -- see "Order lifecycle". |
| `s_address` | `VARCHAR(90)` | The escrow address, from `EscrowAddress::address()`. |
| `redeem_script_hex` | `TEXT` | From `EscrowAddress::redeemScriptHex()`. Frozen at creation, never recomputed; see `../README.MD`, "Values are frozen per order". |
| `buyer_refund_locktime`, `seller_release_locktime` | `INT UNSIGNED` | Unix timestamps, from `EscrowAddress`. Also frozen. |
| `amount_lep` | `BIGINT UNSIGNED` | The item's price converted to lep (1 ELEK = 100,000,000 lep), at order creation time. |
| `status` | `VARCHAR(40)` | One of `ElektronNet\Payments\Core\Escrow\OrderStatus`'s constants. |
| `psbt_base64` | `MEDIUMTEXT NULL` | Set once a `PsbtRelay` blob exists for this order: the unsigned release PSBT once the buyer confirms receipt, then overwritten with the buyer-signed version once they submit it (`controllers/order-status.php`). The plugin only ever stores/relays this value; it never signs or broadcasts it (see `../shared/README.md`, "Trustless by design"). |
| `psbt_signature_count` | `TINYINT` | Mirrors `PsbtRelay::signatureCount()`: `0` while unsigned, `1` once the buyer's signature is on it. The seller signs and broadcasts entirely in their own wallet and is never expected to submit anything back, so this never reaches `2` in the current flow. |
| `dt_pub_date`, `dt_mod_date` | `DATETIME` | Standard Osclass-style timestamps. |

### `elektron_escrow_wallet`

| Column | Type | Notes |
|---|---|---|
| `fk_i_user_id` | `INT UNSIGNED` | Primary key; one row per user who has connected a wallet. |
| `xpub` | `VARCHAR(120)` | Extended public key. Parsed with `HierarchicalKeyFactory` before it is ever stored (must actually parse as a public, not private, extended key for this network); a private key, xprv, or seed phrase pasted here is rejected at that same check, not merely discouraged in the label text. |
| `next_index` | `INT UNSIGNED` | The next unused derivation index for this xpub (`EscrowWalletDAO::allocateNextIndex()`); reset to 0 whenever the xpub itself changes. Never reused, so no two orders ever share a derived key -- see `XpubChildKeyDeriver`'s docblock. |
| `dt_pub_date` | `DATETIME` | |

`views/wallet.php` includes a collapsible "How do I find my xpub?" help block covering all three wallet variants actually in use (Elektrum desktop, the Elektron Net node wallet, and Elektrum mobile's current gap), each checked against that wallet's own source rather than assumed. After saving, the page also derives and shows the first address the xpub produces, so the user can check it against their own wallet before trusting it for a real order -- see `XpubChildKeyDeriver`'s docblock for why that matters (a wallet whose xpub does not sit at the depth this platform assumes will otherwise only be caught the hard way, at release time).

### Changing a connected xpub

`elektron_escrow_save_user_xpub()` (`includes/wallet.php`) refuses to actually change an already-saved xpub while `EscrowOrderDAO::hasActiveOrderForUser()` finds any non-terminal order for that user, as buyer or as seller (re-saving the identical xpub is always allowed, since it is not a change). This is a UX safeguard, not a cryptographic one, and the distinction matters: every order's `buyer_pubkey`/`seller_pubkey`/`redeem_script_hex` is a child key derived once, at order creation, and never re-derived from `elektron_escrow_wallet` afterward (see "Database schema" above), so this table's contents have **no effect at all** on any already-created order's actual on-chain address or script, whether the block exists or not. What the block actually prevents is a user connecting a *different* wallet mid-order and then discovering that the order they still have open needs a signature from a key derived from the *old* xpub they just replaced, since the escrow script is permanently bound to whichever child keys were derived at that order's creation. A wallet that is genuinely gone (lost, reset) makes that specific order's cooperative-release path unrecoverable regardless of what this plugin's own connected-xpub setting is changed to afterward; the block exists to stop that from happening by mistake, not because it is fixable after the fact.

## Escrow eligibility

A listing is only ever offered escrow if **both** hold:

1. The item's `fk_c_currency_code` (uppercased) is one of the admin-configured `currency_codes` (default `ELE`, `ELK`; see "Admin settings" below). This is a list, not the single fixed `ELEK`, because Osclass's own currency table stores a code as `CHAR(3)` (confirmed against a real installation's `oc-includes/osclass/installer/struct.sql`), so the 4-letter `ELEK` can never actually be stored as a currency code there -- an operator has to pick a 3-letter contraction instead, and different operators may already have picked different ones (`ELE` vs `ELK`) before this was known. Converting a fiat-priced listing would also need a live exchange rate, and no reliable ELEK price feed exists yet (see `elektron-net-mempool`'s README on why the fiat price feed is disabled). The check is enforced both where the "Buy" link is rendered (`views/item-widget.php`) and again in `controllers/checkout.php` itself, so navigating straight to the checkout URL for an ineligible listing still shows a notice instead of a preview (see "Status" for why that is a notice, not a redirect).
2. The item has a numeric price set at all (`elektron_escrow_item_price_elek()` returns non-null; see "Status" -- a "price on request" listing has nothing fixed to escrow).
3. The seller has a row in `elektron_escrow_wallet` (connected a wallet once, see "One-time wallet setup" in `../README.MD`).

The buyer's own wallet connection is checked at checkout time instead (shows a notice linking to `elektron_escrow_wallet` if missing), since that is the point where it first matters for them.

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
| Accepted currency codes (one per line) | `currency_codes` | `ELE`, `ELK` |
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

1. **Checkout** (`controllers/checkout.php`): a GET on first visit for a given (item, buyer) pair shows a preview only (item, price, `TimeoutPolicy` in days, and the shipping-details form) and creates nothing. Only a POST with `confirm_checkout=1` (and a valid CSRF token, and all shipping fields filled in) derives a fresh, guaranteed-unique per-order child pubkey for each party from their own xpub (`XpubChildKeyDeriver`, replacing the earlier per-order nonce baked into the script -- see `PlainMultisigEscrowScriptBuilder`'s docblock) and builds the `EscrowAddress` from those plus the configured `TimeoutPolicy`, inserts the order row with `status = awaiting_payment`, then renders that order's own details page (`elektron_escrow_render_order_status()`, see "Status") directly in this same response -- not a redirect there, see "Status" for why one from inside this plugin's own controllers cannot work on the real, currently deployed platform. Revisiting the checkout URL (GET) resumes the existing *non-terminal* order (rendering its details page the same way) instead of showing the preview again or creating a second one; a settled (released/refunded/claimed) order from an earlier purchase of the same item is never resumed -- a fresh order, and a fresh address, is created instead. Known limitation: two concurrent confirm-POST requests can each create their own order before either finishes (see `../shared/README.md`, "Open items"); this produces at most a harmless duplicate "awaiting payment" order, not an address collision or fund-loss risk.
2. **Watching for payment** (`elektron_escrow_check_payment()`, `includes/payment-watcher.php`): checks a non-terminal order's own escrow address against `elektron_escrow_chain_data()` (a `FallbackChainDataProvider` built from the admin-configured endpoint list). Moves `awaiting_payment` to `confirming` as soon as the address has received at least `amount_lep` in total (even at 0 confirmations -- "seen on chain" already), and `confirming` to `funded` once the best confirmation count among its transactions reaches the admin-configured threshold. Checks total value received *at the address*, never which address or wallet it came from. Runs two ways: automatically, on every non-terminal order, via the `cron_minutely` hook (`includes/hooks.php`) -- itself only as reliable as Osclass's own `auto_cron` setting or a real system cron actually triggering it; and manually, on a single order, via the "Check payment now" button on the order-status page (either party) and the "Check payment" action per row on "My Elektron orders", for whenever a party does not want to wait for the automatic pass. A short-paid order is left in `awaiting_payment` indefinitely; see "Open Questions" for why that is not handled further yet.
3. **Cancelling** (`controllers/order-status.php`, buyer only, only while `awaiting_payment`): deletes the order outright (`EscrowOrderDAO::cancelByBuyer()`) rather than moving it to a new "cancelled" status, since nothing about an order that was never paid into is worth keeping as history. The delete's own `WHERE` clause re-checks buyer and status atomically, so a cancel racing the payment watcher above can never delete an order a payment has actually reached. Sends both parties a cancellation email (see "Order-lifecycle emails" below).
4. **Confirm receipt** (`controllers/order-status.php`): the buyer's only action. Moves the status to `release_pending_seller_signature` via `OrderStateMachine::canTransition()`, but only once `TimeoutPolicy::canSafelyStartRelease()` also holds. That second check exists because "confirm receipt" is purely a database status change with no on-chain effect at all: the buyer's own timeout-refund path stays spendable by the buyer alone from T1 onward regardless of what this plugin's status column says, so starting a release too close to T1 risks the seller being told funds are on the way while the buyer can still reclaim them first (an honest delay on the seller's side -- their signing device offline, asleep -- is enough, no bad faith required). Within `TimeoutPolicy::MIN_CONFIRM_RECEIPT_SAFETY_MARGIN_DAYS` of T1, the action is refused outright with a translated error, not merely discouraged.
5. **Mark as released** (`controllers/order-status.php`, seller only, from `release_pending_seller_signature`): since `ReleasePsbtBuilderInterface` has no implementation yet (see "Open Questions"), this plugin cannot build or relay the actual release transaction; the redeem script is shown so the two parties can cooperatively sign and broadcast it with their own wallets instead. This action only records that they did -- a database status change with no on-chain effect of its own, the same as "confirm receipt" -- since nothing otherwise moves an order out of `release_pending_seller_signature` at all.
6. **Reminders**: `includes/hooks.php`'s `cron_daily` hook identifies which orders have a reminder due; delivering that reminder (email, in-portal message) is a TODO, see below.

### Order-lifecycle emails

`includes/mail.php`, via Osclass's own `osc_sendMail()` (confirmed contract: `to`/`to_name`/`subject`/`body`, always sent as HTML -- `oc-includes/osclass/utils.php`), not the admin-editable Page/email-template system real core notifications use (`oc-includes/osclass/emails.php`): that system needs its own seeded `Page` rows and an admin UI this plugin does not have, and every other piece of user-facing text in this plugin already goes through its own gettext domain instead. Sent on three events: an order being created (`elektron_escrow_send_order_created_emails()`, called from `controllers/checkout.php` right after the insert), an order being cancelled (`elektron_escrow_send_order_cancelled_emails()`, called from `controllers/order-status.php`), and an order reaching `funded` (`elektron_escrow_send_order_funded_emails()`, called from `elektron_escrow_check_payment()` in `includes/payment-watcher.php`). Both parties are emailed on all three events, each with wording addressed to their own role -- the buyer's created-email includes the payment address and amount; the seller's does not, matching "Escrow eligibility" below. The seller's `funded` email also reminds them to deactivate their own listing if it is now sold, since nothing does that automatically (see "Order lifecycle").

## Uninstall behavior

Deactivating the plugin (`elektron_escrow_uninstall()`) deletes its admin preferences only. Both database tables are kept deliberately: `elektron_escrow_order` is transaction history, and `elektron_escrow_wallet` is a user's own saved setting, not plugin configuration. An operator who wants them gone drops them manually.

## Open Questions

1. **Release PSBT construction.** Implemented: `ReleasePsbtBuilderInterface`'s reference implementation is `Bip174PsbtBuilder` (in `../shared`), built on a hand-rolled BIP174 encoder/decoder since no PHP library implements PSBT at all (confirmed by exhaustive source search). "Confirm receipt" now builds the unsigned release PSBT (`includes/psbt.php`, using `ChainDataProviderInterface::getFundingOutputs()` for the exact funding vout) and the order-status page walks the buyer through signing it in their own wallet (QR + copy/paste) and submitting the result back (validated by `PsbtSignatureInspector` before being shown to the seller), then walks the seller through signing and broadcasting the result entirely in their own wallet -- the platform never signs or broadcasts either step. The rare case where the funding output cannot be resolved to exactly one UTXO (e.g. a split payment, see item 2 below) falls back to showing the raw redeem script for manual cooperation, same as before this was built. `PlainMultisigEscrowScriptBuilder`'s script (plain `wsh(multi(2,...))`) is signable by a real wallet's normal descriptor-based flow, unlike its predecessor -- but has no on-chain timeout enforcement at all yet (see its own docblock for the planned pre-signed-transaction mechanism, item 5 below). `tools/legacy-release.php` remains a manual, CLI-only, delete-after-use recovery path for orders funded under the old nonce-prefixed script (see its own docblock).
2. **Partial payments.** The payment watcher (`cron_minutely`, see "Order lifecycle") leaves an order in `awaiting_payment` if its address has received something but less than the full `amount_lep`; there is no separate "underpaid" status, notification, or top-up flow yet. A split payment that does reach the full amount across more than one transaction also cannot get an automatic release PSBT (see item 1) since `Bip174PsbtBuilder` only builds a single-input transaction.
3. **Reminder delivery.** `cron_daily` already identifies which orders have a reminder due (see "Order lifecycle" above); nothing sends it yet. Order-lifecycle emails already exist for creation, cancellation, and funding (see "Order-lifecycle emails"), so this would extend the same `includes/mail.php`, not introduce a new mechanism.
4. **Wallet connect UX.** The xpub field on the dedicated wallet-connect page (`views/wallet.php`) is a plain text input. A real wallet (browser extension, QR-based mobile companion) providing the key automatically, instead of the user copy-pasting a base58 string, is a likely follow-up once such a wallet exists. Elektrum's mobile app in particular has no in-app xpub screen at all yet (verified against its own source), so its users currently need to open the same seed once in the desktop app.
5. **Timeout enforcement mechanism.** See item 1: nothing currently gives either party unilateral on-chain recourse if the other goes silent. The planned fix is a pair of pre-signed, time-locked transactions exchanged cooperatively at funding time, not a script change.
6. **Duplicate order on a concurrent double-submit.** Two checkout requests for the same (item, buyer) pair arriving before the first finishes inserting can each create their own order; see `../shared/README.md`, "Open items", for why a plain unique index does not fix this cleanly and what actually would.
7. **Shipped/received lifecycle gating.** There is currently no separate "seller marked as shipped" step before the buyer can confirm receipt, and no cancel/refund path while an order is `funded` beyond waiting for `buyer_refund_locktime` -- confirming receipt is the buyer's only action once funded. A tighter flow (seller confirms shipped -> buyer confirms received, with a cooperative cancel/refund while still `funded`) has been discussed but not built.
8. **Non-English locale sync.** Every string added since the xpub redesign (this includes the whole PSBT UI in item 1) has only been added to `languages/en_US/messages.po`; the other locales have not been resynced.
