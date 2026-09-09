<?php

namespace ElektronNet\Payments\Core\Escrow;

/**
 * Builds the actual on-chain escrow script and derives its address. This is
 * the one piece of the core that touches low-level script/address bytes, so
 * it is kept behind an interface on purpose: the safe way to implement it is
 * to wrap a maintained Bitcoin-protocol library (this package depends on
 * bitwasp/bitcoin, see composer.json), not to hand-roll script serialization
 * or bech32 encoding from scratch.
 *
 * Implements the plain 2-of-2 script described in
 * PlainMultisigEscrowScriptBuilder's docblock:
 *
 *   2 <buyerPubKey> <sellerPubKey> 2 OP_CHECKMULTISIG
 *
 * $buyerPubKeyHex and $sellerPubKeyHex are expected to already be per-order
 * child keys derived from each party's registered xpub (see
 * XpubChildKeyDeriver), not their long-lived registered key directly: the
 * one-address-per-order guarantee used to come from a nonce baked into the
 * script, which is exactly what made the old script unsignable by any real
 * wallet. It now comes from key derivation instead, entirely outside this
 * interface.
 *
 * IMPORTANT: any concrete implementation MUST be checked against Elektron
 * Net's own parameters before it is trusted with real funds, in particular:
 * the bech32 HRP per network (mainnet `be`, testnet/testnet4/signet `tb`,
 * regtest `bcrt` -- verified directly against elektron-net's own
 * src/kernel/chainparams.cpp, see ElektronNetworkFactory's docblock).
 */
interface EscrowScriptBuilderInterface
{
    public function build(
        string $buyerPubKeyHex,
        string $sellerPubKeyHex,
        TimeoutPolicy $timeoutPolicy,
        int $fundedAtUnixTime
    ): EscrowAddress;
}
