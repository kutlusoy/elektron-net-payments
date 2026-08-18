# Elektron Net Payments

- **Version:** 0.1 (draft)
- **Date:** August 18, 2026
- **Audience:** Marketplace operators, plugin developers, integrators
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net), treat as ground truth for anything this doc references
- **Consumer:** [`osclass-escrow`](./osclass-escrow), part of this repository
- **See also:** [`elektron-net-mempool`](https://github.com/kutlusoy/elektron-net-mempool) for chain/watcher data

---

Multi-platform payment plugin suite for Elektron Net. This repository bundles trustless escrow integrations for third-party e-commerce platforms, starting with Osclass.

## Overview

Elektron Net Payments lets marketplace operators accept Elektron Net (ELEK) as a payment method without relying on a centralized payment processor. Instead of a trusted third party holding funds, transactions use 2-of-2 multisig escrow with CLTV-based timeout paths, so buyers and sellers can transact directly while still having a built-in fallback if something goes wrong.

## Why escrow instead of a simple payment gateway

A standard payment plugin just forwards funds from buyer to seller. That works for card payments backed by a bank or processor that can reverse a charge, but it does not work well for irreversible on-chain payments. Elektron Net Payments solves this differently:

- Funds are locked in a 2-of-2 multisig address controlled jointly by buyer and seller.
- Funds only move when both parties agree, or automatically after a timeout, via CLTV-based fallback paths (buyer refund if the seller never delivers, seller release if the buyer never confirms).
- No arbiter, no custodian, no platform holding customer funds at any point.

## Repository structure

```
elektron-net-payments/
    osclass-escrow/       Osclass plugin implementing the 2-of-2 escrow flow
    shared/                Shared library: escrow script logic, watcher daemon client, RPC wrapper
    docs/
        doc-elektron/       Project documentation (guidelines, fix reports)
```

Each platform integration lives in its own subdirectory and depends on the shared library rather than duplicating escrow logic. This keeps the core escrow behavior consistent across every plugin in the suite, and makes it straightforward to add support for additional platforms later.

## Trustless by design

At no point does the server, the plugin, or the platform operator hold or have access to a private key belonging to the buyer or the seller. Only public keys and unsigned or partially signed transactions ever pass through the system. Funds can only move if the buyer and seller sign together, or if a timeout path is used, and that logic is enforced by the escrow script itself, not by a party that has to be trusted.

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
- The partially signed transaction is passed to the seller's wallet, which signs it as well.
- Once both signatures are present, the transaction is complete and is broadcast to the network automatically. No manual broadcasting step is needed.

### 6. If something goes wrong

- If the seller never delivers, the buyer can reclaim the funds themselves once the buyer-side timeout (CLTV) has passed, without needing the seller's signature or cooperation.
- If the buyer receives the goods but never confirms and never responds, the seller can claim the funds themselves once the longer seller-side timeout has passed, without needing the buyer's signature or cooperation.
- These fallback paths are built into the escrow script from the start. Nobody needs to request them or set them up manually, and no arbiter or support ticket is required.

### What stays simple for participants

- No private keys are ever typed into the marketplace, the plugin, or any web form.
- No manual address generation, no copying of scripts, no command line steps.
- The only actions either party ever takes are: connect a wallet once, pay an address, and approve a signature when it matters (confirming receipt, or releasing funds). Everything else, including address generation, payment detection, PSBT construction, and broadcasting, is handled automatically by the plugin and the watcher daemon.

## Status

This project is under active development. Interfaces, script structure, and supported platforms may change without notice while still in early versions.

## License

See the LICENSE file in this repository for full license terms.

## Links

- Website: https://elektron-net.org
- X (Twitter): @elektronnet and @kutlusoy
- Telegram: @elektronnet
