# Elektron Net Payments

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

## How it works (high level)

1. A buyer places an order on a supported marketplace platform.
2. The plugin generates a fresh 2-of-2 multisig address for that specific order.
3. The buyer sends payment to the escrow address. A watcher daemon monitors the chain and updates the order status once the payment is detected.
4. Once the buyer confirms receipt of the goods, both parties sign and the funds are released to the seller.
5. If the buyer never confirms, or the seller never delivers, the applicable CLTV timeout path allows the affected party to reclaim or release the funds without needing the other party's cooperation.

## Status

This project is under active development. Interfaces, script structure, and supported platforms may change without notice while still in early versions.

## License

See the LICENSE file in this repository for full license terms.

## Links

- Website: https://elektron-net.org
- X (Twitter): @elektronnet and @kutlusoy
- Telegram: @elektronnet
