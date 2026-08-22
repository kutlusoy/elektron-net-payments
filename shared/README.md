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

1. Require this package via Composer (a path repository during development inside this monorepo, a tagged version once it is published on its own). Your adapter's own `composer.json` also needs `"lastguest/murmurhash": "2.1.1 as 2.0.0"` in its own `require` block: `bitwasp/bitcoin` (this package's own dependency) exact-pins that package at `2.0.0`, which requires PHP `^7` and fails on PHP 8 -- a known, still-open upstream bug (`Bit-Wasp/bitcoin-php#875`) that this alias works around by installing the real, PHP-8-compatible `2.1.1` release under the version number `bitwasp/bitcoin` asks for. This has to be declared in *your adapter's* composer.json specifically, not here: Composer's inline-alias syntax (`"x as y"`) only takes effect in the project's root composer.json, never in a dependency's own (it was tried here first and silently had no effect, exactly because of this).
2. Load your platform's own admin settings into a `Config\PaymentsConfig`, building its `Escrow\TimeoutPolicy` with `TimeoutPolicy::fromDays()` so the guardrails are enforced, or `TimeoutPolicy::defaults()` if the admin has not overridden it.
3. Wire your platform's hooks to call into the core for: generating an `EscrowAddress` when an order starts, checking `ChainData\FallbackChainDataProvider` for payment/confirmation status, validating status transitions through `OrderStateMachine`, and relaying PSBT blobs through `PsbtRelay`.
4. For every string shown to a user, look the key up in `I18n\MessageCatalog` for the fallback text and the placeholders it expects, then translate it through your own platform's i18n system.

## Open items

- `Escrow\BitwaspEscrowScriptBuilder` is a reference implementation, checked line-by-line against bitwasp/bitcoin's actual source (cloned `Bit-Wasp/bitcoin-php` to verify every call it makes) rather than against that library's docs, but is **still not verified against a live Elektron Net node**; see its class docblock before relying on it with real funds. That source check itself caught and fixed two bugs that would have thrown on every call (`ScriptFactory::scriptNum()` does not exist in the library; `SegwitAddress`'s constructor takes a `WitnessProgram`, not a script-hash `Buffer`) and one in `ElektronNetworkFactory` (there is no `setSegwitBech32Prefix()` setter on `Network`; representing a parameter-fork network requires a dedicated `Network` subclass, now in `Escrow/Network/`, one per Elektron Net network). See "Escrow address correctness" below for the full list of what this affects.
- `Psbt\ReleasePsbtBuilderInterface` has no reference implementation yet. Whichever implementation is built should produce its `$feeRateLepPerVByte` via `ChainData\FeeRateResolver::resolve()` (live mempool estimate, falling back to the admin's fixed `PaymentsConfig::feeRateLepPerVByte()` only when no estimate is available), not by passing that fixed value straight through.
- Order creation is not race-safe against a genuine double-submit: two concurrent checkout requests for the same (item, buyer) pair before the first `insert()` completes can both see no existing non-terminal order and both create one. Each resulting order is individually well-formed (distinct nonce, distinct address, no fund-loss or address-collision risk), so the effect is at most two live "awaiting payment" orders for the same intended purchase rather than a security issue. Not yet fixed: a plain unique index on (item, buyer) is not sufficient by itself, since a legitimate repurchase after a terminal order must still be allowed for that same pair; the correct fix needs a compare-and-set insert or an application-level lock, not attempted here to avoid guessing at Osclass/Shopclass's DAO transaction API without checking it as carefully as everything else in this repository has been checked.

## Escrow address correctness

Three properties this suite depends on, and where each is actually enforced -- written out explicitly because none of it can be "probably right" for a payments system:

- **One address per order, always, even for repeat buyers/sellers.** `EscrowScriptBuilderInterface::build()` takes a mandatory, caller-generated `$orderNonceHex` (`bin2hex(random_bytes(16))` in `osclass-escrow/controllers/checkout.php`), pushed and immediately dropped (`<orderNonce> OP_DROP`) at the start of the script before any spending path. Without it, the script -- and so the address -- depended only on (buyerPubKey, sellerPubKey, the marketplace's one global TimeoutPolicy, fundedAt-to-the-second), which two orders between the same pair created within the same second would derive identically. See `EscrowScriptBuilderInterface`'s docblock.
- **The address is deterministically derived from both parties' already-existing public keys** (plus the nonce, the timeout policy, and the funding timestamp) -- never from a private key, seed, or any material this suite generates itself. Neither the escrow script nor the address derivation cares which HD derivation path produced a given pubkey (see "SLIP-44 coin type" below); a raw compressed public key is all `build()` ever sees.
- **Resuming "the" order for a repeat visit to checkout must never resume a *settled* one.** `EscrowOrderDAO::findByItemAndBuyer()` (Osclass adapter) excludes every `OrderStatus::TERMINAL` status and returns the most recent remaining match; a version that ignored status would have resumed an already-released order's stale address on a genuine repurchase.

### SLIP-44 coin type (1370) is irrelevant to this package, by design

Elektron Net registered its own SLIP-44 coin type, 1370 (`doc-elektron/CHANGELOG-slip44-coin-type.md`), activated from a specific block height onward; wallets created before that height keep deriving under the legacy coin type `0'`, so both `0'`- and `1370'`-derived keys -- and the addresses either can produce -- are valid and coexist on mainnet indefinitely (that changelog is explicit that no old wallet or address is invalidated). This package never performs HD key derivation itself: `EscrowScriptBuilderInterface::build()` only ever receives an already-derived compressed public key, as opaque hex, from whichever wallet the buyer or seller connected (`osclass-escrow/includes/wallet.php` validates only the key *format* -- `^(02|03)[0-9a-fA-F]{64}$` -- never its derivation history). A key derived under `0'` and one derived under `1370'` are indistinguishable to the multisig/timeout script and to address derivation; there is nothing here that needs to "know about" or special-case either coin type, and nothing to get wrong by mixing them. The only place coin type matters at all is inside whichever wallet software the user runs -- see `doc-elektron/guideline-wallet-integration.md` §3.1 for the MUST-level requirement that a wallet vendor's seed-recovery flow scan *both* paths.

### Chain parameters (bech32 HRP, base58/BIP32 prefixes)

Verified directly against elektron-net's own `src/kernel/chainparams.cpp` (the ground truth per `doc-elektron/guideline-wallet-integration.md` §2.1), not against secondary documentation -- see `Escrow/Network/ElektronMainnetNetwork.php`, `ElektronTestnetNetwork.php`, and `ElektronRegtestNetwork.php` for the exact byte values and per-network notes (mainnet's base58/BIP32 values are byte-identical to Bitcoin mainnet's; only the bech32 HRP, `be`, differs; testnet/testnet4/signet are byte-identical to Bitcoin testnet including the HRP `tb`; regtest keeps testnet's base58/BIP32 values but uses its own HRP, `bcrt`, confirmed distinct in chainparams.cpp even though bitwasp/bitcoin's own `BitcoinRegtest` class does not make the equivalent distinction for Bitcoin itself).
