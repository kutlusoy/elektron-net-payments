# Elektron Net Payments

- **Version:** 0.2 (draft)
- **Date:** August 22, 2026
- **Audience:** Marketplace operators, plugin developers, integrators
- **Scope:** Suite-wide standard for every platform integration in this repository (Osclass today; WooCommerce and further platforms planned)
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net), treat as ground truth for anything this doc references
- **Consumer:** [`osclass-escrow`](./osclass-escrow), part of this repository
- **See also:** [`elektron-net-mempool`](https://github.com/kutlusoy/elektron-net-mempool) and [`elektron-net-electrs`](https://github.com/kutlusoy/elektron-net-electrs) for chain/watcher data

---

Multi-platform payment plugin suite for Elektron Net. This repository bundles trustless escrow integrations for third-party e-commerce platforms, starting with Osclass and planned to extend to WooCommerce and others. The escrow rules described below (timeout defaults, no signing or broadcasting by the plugin, fallback chain-data endpoints) are a suite-wide standard defined once in `shared/` and inherited by every platform adapter, not something each integration reinvents.

## Overview

Elektron Net Payments lets marketplace operators accept Elektron Net (ELEK) as a payment method without relying on a centralized payment processor. Instead of a trusted third party holding funds, transactions use 2-of-2 multisig escrow with CLTV-based timeout paths, so buyers and sellers can transact directly while still having a built-in fallback if something goes wrong.

## Why escrow instead of a simple payment gateway

A standard payment plugin just forwards funds from buyer to seller. That works for card payments backed by a bank or processor that can reverse a charge, but it does not work well for irreversible on-chain payments. Elektron Net Payments solves this differently:

- Funds are locked in a 2-of-2 multisig address controlled jointly by buyer and seller.
- Funds only move when both parties agree, or automatically after a timeout, via CLTV-based fallback paths (buyer refund if the seller never delivers, seller release if the buyer never confirms). Default timeout lengths and how they can be adjusted are covered in [Escrow timeout policy](#escrow-timeout-policy) below.
- No arbiter, no custodian, no platform holding customer funds at any point.

## Repository structure

```
elektron-net-payments/
    osclass-escrow/       Osclass plugin implementing the 2-of-2 escrow flow
    shared/                Shared library: escrow script logic, watcher daemon client, RPC wrapper
    scripts/               Build scripts that package a platform adapter into an installable zip
    .github/workflows/     One release workflow per platform adapter
    docs/
        doc-elektron/       Project documentation (guidelines, fix reports)
```

Each platform integration lives in its own subdirectory and depends on the shared library rather than duplicating escrow logic. This keeps the core escrow behavior consistent across every plugin in the suite, and makes it straightforward to add support for additional platforms later. The timeout defaults, the frozen-per-order rule, and the chain-data fallback list described in this README all live in `shared/`, so a new platform adapter (WooCommerce or otherwise) gets them for free instead of having to reimplement them.

## Packaging and releases

Each platform adapter ships as a plain zip a marketplace operator extracts straight into that platform's plugin directory (for Osclass: `oc-content/plugins/osclass-escrow/`), with its Composer dependencies (the shared core plus whichever third-party libraries it needs) already baked into its own `vendor/` folder. Nobody installing the plugin is expected to run Composer themselves; most Osclass hosting has no shell access to do that anyway.

- `scripts/build-osclass-plugin.sh [version]` builds that zip locally: installs Composer dependencies with `--no-dev`, copies the shared core in as real files rather than a symlink (`osclass-escrow/composer.json`'s path repository sets `"symlink": false` for exactly this reason, since a symlink into a sibling directory stops resolving the moment the zip is extracted somewhere else), recompiles every `.mo` from its `.po` in case one was edited without recompiling, stamps the given version into `index.php`'s header, strips dev-only files (`composer.json`, `composer.lock`, the `.po`-to-`.mo` fallback compiler), and writes `dist/osclass-escrow-<version>.zip`.
- `.github/workflows/release-osclass-escrow.yml` runs that same script in CI. Pushing a tag matching `osclass-v*` (e.g. `osclass-v0.1.0`) builds the zip and attaches it to a new GitHub Release; running the workflow manually (`workflow_dispatch`) instead builds a throwaway zip as a downloadable Actions artifact, for trying a build before committing to a real tag.

Every future platform adapter gets its own `build-<platform>-plugin.sh` and `release-<platform>.yml`, scoped to its own tag prefix, rather than a shared workflow: that keeps one adapter's release from ever being able to build or publish another's.

Tag:
```bash
git tag osclass-v0.1.0
git push origin osclass-v0.1.0
```

If you must delete and tag new:

```bash
git tag -d osclass-v0.1.0 && git push origin :osclass-v0.1.0 && git tag osclass-v0.1.0 && git push origin osclass-v0.1.0
```

## Trustless by design

At no point does the server, the plugin, or the platform operator hold or have access to a private key belonging to the buyer or the seller. Only public keys and unsigned or partially signed transactions ever pass through the system. Funds can only move if the buyer and seller sign together, or if a timeout path is used, and that logic is enforced by the escrow script itself, not by a party that has to be trusted.

The plugin never signs or broadcasts a transaction on either party's behalf. It only relays the PSBT (the partially signed transaction) between the buyer's and seller's wallets and reads chain data to update order status; signing and the final broadcast always happen inside the buyer's or seller's own wallet software.

## Chain data access

The watcher only ever reads chain data; it has no signing or broadcast capability of its own, for the same reason described above. It queries a configurable, ordered list of Elektron Net Electrum or Esplora-compatible endpoints, with automatic failover to the next entry if one is slow or unreachable. Operators can point this at a self-hosted node, a self-hosted [`elektron-net-electrs`](https://github.com/kutlusoy/elektron-net-electrs) or [`elektron-net-mempool`](https://github.com/kutlusoy/elektron-net-mempool) instance, or a third-party endpoint; running your own is recommended for production so payment confirmation does not depend on a service outside your control.

## How it works, step by step

### 1. One-time wallet setup (buyer and seller)

- Buyer and seller each connect an Elektron Net wallet to the marketplace account. This can be a browser extension, a desktop wallet, or a companion mobile wallet.
- The wallet shares only its public key with the plugin. The private key never leaves the user's device.
- This is a one-time step. It does not need to be repeated for future orders.

### 2. Order placed

- The buyer places an order on the marketplace as usual, with no extra steps compared to a normal checkout.
- The plugin automatically generates a fresh 2-of-2 multisig escrow address for this specific order, built from the buyer's and the seller's public keys. This happens instantly and requires no action from either party.

### 3. Payment

- The buyer is shown the escrow address and amount, typically as a QR code, and pays from their wallet like any other Elektron Net payment.
- A watcher daemon monitors the chain in the background and updates the order status automatically once the payment is detected and confirmed. Neither party needs to check anything manually.

### 4. Delivery and confirmation

- The seller ships or delivers the goods or service as agreed.
- Once the buyer receives it and is satisfied, they confirm this in the marketplace with a single action.
- That confirmation triggers the plugin to build a release transaction (a PSBT, or partially signed transaction) that pays the funds to the seller.

### 5. Signing the release

- The buyer's wallet prompts them to sign the release transaction. This is one click or one scan, similar to approving a payment.
- The partially signed transaction is passed through the plugin to the seller's wallet, which signs it as well.
- Once both signatures are present, the transaction is complete. The wallet that added the second signature broadcasts it to the network directly; the plugin itself never sends anything.

### 6. If something goes wrong

- If the seller never delivers, the buyer can reclaim the funds themselves once the buyer-side timeout (CLTV) has passed, without needing the seller's signature or cooperation. By default this is 30 days after the escrow address was funded.
- If the buyer receives the goods but never confirms and never responds, the seller can claim the funds themselves once the longer seller-side timeout has passed, without needing the buyer's signature or cooperation. By default this is 60 days after funding.
- These fallback paths are built into the escrow script from the start. Nobody needs to request them or set them up manually, and no arbiter or support ticket is required.
- Neither the buyer nor the seller can change these timeouts for their own order; see [Escrow timeout policy](#escrow-timeout-policy) for how the values are set and why.

### What stays simple for participants

- No private keys are ever typed into the marketplace, the plugin, or any web form.
- No manual address generation, no copying of scripts, no command line steps.
- The only actions either party ever takes are: connect a wallet once, pay an address, and approve a signature when it matters (confirming receipt, or releasing funds). Address generation, payment detection, and PSBT construction are handled automatically by the plugin and the watcher daemon; signing and broadcasting always happen in the buyer's or seller's own wallet, never in the plugin.

## Escrow timeout policy

These rules are the suite-wide standard for every platform integration in this repository, defined once in `shared/` so Osclass, WooCommerce, and any future adapter all behave the same way.

### Defaults

- **Buyer refund threshold (T1): 30 days** after the escrow address is funded. From this point on, the buyer alone can reclaim the funds, even without the seller's cooperation.
- **Seller release threshold (T2): 60 days** after the escrow address is funded. From this point on, the seller alone can claim the funds, even without the buyer's cooperation.
- T2 is set well past T1 on purpose. It gives a slow or distracted buyer a full 30-day buffer after their own refund window opens before the seller's claim window opens, which keeps the period where both fallback paths are simultaneously valid as short as reasonably possible.

### Why neither party can set these per order

The buyer-refund path has no way to know whether delivery actually happened; it only checks elapsed time. A short buyer threshold would let a dishonest buyer keep the goods and still reclaim the payment. A short seller threshold would let a dishonest seller claim the payment without ever shipping, especially if the buyer is not watching the chain closely enough to react in time. Letting either side negotiate these values per order (or hide them in listing terms) reopens exactly those two problems. Keeping the values fixed and identical for every order removes that whole class of manipulation.

### Admin-configurable, not user-configurable

Marketplace operators can change T1 and T2 for their own installation in the plugin's admin settings; individual buyers and sellers cannot override them for a single order. Two guardrails apply to the admin setting itself:

- T2 MUST exceed T1 by a safe margin (at least 1.5x); the plugin MUST reject a configuration that violates this.
- T1 and T2 MUST stay within sane bounds (for example, not below a few days, not above roughly half a year), so an accidental misconfiguration cannot recreate the problems described above.

### Values are frozen per order

T1 and T2 are written into the escrow's redeem script at the moment its address is generated, not read from live configuration when a fallback path is used. Changing the admin default only affects orders placed afterward; every escrow already funded keeps the timeout values it was created with, even if the operator changes the defaults later.

### Countdown display

Because the locktime is expressed as a timestamp rather than a block height, the remaining time can be shown exactly rather than estimated from an average block interval. The order page shows one countdown at a time, depending on where the order currently stands:

- Before the buyer confirms receipt: a countdown to the buyer's own refund threshold (T1).
- If T1 has passed and the order is still unconfirmed: a countdown to the seller's release threshold (T2).

The plugin also sends reminder notifications ahead of each threshold (for example a few days before T1 to the buyer, and a few days before T2 to the seller), so neither side has to actively watch the countdown to avoid missing their own window.

## Status

This project is under active development. Interfaces, script structure, and supported platforms may change without notice while still in early versions.

## License

See the LICENSE file in this repository for full license terms.

## Links

- Website: https://elektron-net.org
- X (Twitter): @elektronnet and @kutlusoy
- Telegram: @elektronnet
