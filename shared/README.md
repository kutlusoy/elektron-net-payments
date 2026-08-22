# Elektron Net Payments, Core Library

- **Version:** 0.1 (draft)
- **Date:** August 22, 2026
- **Audience:** Developers building a platform adapter (osclass-escrow today; WooCommerce and others planned)
- **Reference implementation:** [`elektron-net`](https://github.com/kutlusoy/elektron-net), treat as ground truth for network/consensus parameters
- **Consumer:** [`osclass-escrow`](../osclass-escrow), and every future platform adapter in this repository
- **See also:** [`../README.MD`](../README.MD) for the escrow model and the timeout policy this library implements

---

`elektron-net/payments-core` is the framework-agnostic PHP library behind every payment-platform integration in this repository. It has no knowledge of Osclass, WooCommerce, or any other platform; a platform adapter depends on it via Composer and wires it into that platform's own hooks, storage, and admin UI.

## What lives here, and why

| Namespace | Responsibility |
|---|---|
| `Escrow\TimeoutPolicy` | The suite-wide default timeouts (T1/T2) and the validation guardrails an admin setting must satisfy. |
| `Escrow\OrderStatus`, `Escrow\OrderStateMachine` | The platform-independent order lifecycle and its legal transitions. |
| `Escrow\EscrowAddress`, `Escrow\EscrowScriptBuilderInterface` | Escrow script and address derivation. The interface exists so the low-level script/address bytes are implemented once, on top of a maintained Bitcoin-protocol library, instead of per platform. |
| `Psbt\PsbtRelay`, `Psbt\ReleasePsbtBuilderInterface` | Opaque PSBT handling. The plugin only ever stores or forwards a blob here; it never signs or broadcasts (see the root README, "Trustless by design"). |
| `ChainData\*` | Read-only chain-data access with a configurable, ordered, failover-capable list of Electrum/Esplora-style endpoints. |
| `Notifications\ReminderScheduler` | Pure date math for "your window opens soon" reminders; the adapter still does the actual sending. |
| `I18n\MessageCatalog` | The canonical key list and English fallback text for every user-facing string, looked up by each adapter's own translation system. |

## What does not live here

Anything platform-specific: hook wiring, the plugin's own DB schema and admin settings storage, routing/URLs, and translation files themselves (`.po`/`.mo`). Those belong in each adapter, one level up (see [`osclass-escrow/README.MD`](../osclass-escrow/README.MD) for how the Osclass adapter does this).

## Using it from a new adapter

1. Require this package via Composer (a path repository during development inside this monorepo, a tagged version once it is published on its own).
2. Load your platform's own admin settings into a `Config\PaymentsConfig`, building its `Escrow\TimeoutPolicy` with `TimeoutPolicy::fromDays()` so the guardrails are enforced, or `TimeoutPolicy::defaults()` if the admin has not overridden it.
3. Wire your platform's hooks to call into the core for: generating an `EscrowAddress` when an order starts, checking `ChainData\FallbackChainDataProvider` for payment/confirmation status, validating status transitions through `OrderStateMachine`, and relaying PSBT blobs through `PsbtRelay`.
4. For every string shown to a user, look the key up in `I18n\MessageCatalog` for the fallback text and the placeholders it expects, then translate it through your own platform's i18n system.

## Open items

- `Escrow\BitwaspEscrowScriptBuilder` is a reference implementation and is **not yet verified** against a live Elektron Net node; see its class docblock before relying on it with real funds.
- `Psbt\ReleasePsbtBuilderInterface` has no reference implementation yet.
