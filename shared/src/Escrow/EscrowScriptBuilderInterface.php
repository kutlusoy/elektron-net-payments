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
 * Implements the script described in the README:
 *
 *   <orderNonce> OP_DROP
 *   OP_IF
 *       2 <buyerPubKey> <sellerPubKey> 2 OP_CHECKMULTISIG
 *   OP_ELSE
 *       OP_IF
 *           <buyerRefundLocktime> OP_CHECKLOCKTIMEVERIFY OP_DROP
 *           <buyerPubKey> OP_CHECKSIG
 *       OP_ELSE
 *           <sellerReleaseLocktime> OP_CHECKLOCKTIMEVERIFY OP_DROP
 *           <sellerPubKey> OP_CHECKSIG
 *       OP_ENDIF
 *   OP_ENDIF
 *
 * The leading `<orderNonce> OP_DROP` is not part of any spending condition;
 * it is pushed and immediately dropped on every path, purely so the script
 * (and so the address) is unique per order even when a buyer and seller
 * transact more than once. Without it, the script depends only on
 * (buyerPubKey, sellerPubKey, timeoutPolicy, fundedAtUnixTime): two orders
 * between the same buyer and seller, funded in the same second, under the
 * marketplace's one global timeout policy, would derive the byte-identical
 * script and so the byte-identical address, which breaks the one-address-
 * per-order guarantee the rest of this suite assumes (see the root README,
 * "How it works, step by step" and "Escrow timeout policy"). The nonce is
 * a one-off value the caller generates per order and never reuses; it does
 * not need to be stored separately afterward, since it is already captured
 * for good inside the redeem script this method returns
 * (EscrowAddress::redeemScriptHex()).
 *
 * IMPORTANT: any concrete implementation MUST be checked against Elektron
 * Net's own parameters before it is trusted with real funds, in particular:
 * the bech32 HRP per network (mainnet `be`, testnet/testnet4/signet `tb`,
 * regtest `bcrt` -- verified directly against elektron-net's own
 * src/kernel/chainparams.cpp, see ElektronNetworkFactory's docblock), and
 * that OP_CHECKLOCKTIMEVERIFY is evaluated against median-time-past when
 * the locktime value looks like a Unix timestamp (BIP113), which affects
 * how close to a threshold a transaction can actually be confirmed.
 */
interface EscrowScriptBuilderInterface
{
    /**
     * @param string $buyerPubKeyHex compressed public key, hex-encoded
     * @param string $sellerPubKeyHex compressed public key, hex-encoded
     * @param string $orderNonceHex random bytes, hex-encoded, unique per
     *     order (e.g. `bin2hex(random_bytes(16))`); see this interface's
     *     docblock for why it exists. The caller need not remember it
     *     afterward: it is only ever needed once, folded into the script
     *     this method returns via EscrowAddress::redeemScriptHex().
     */
    public function build(
        string $buyerPubKeyHex,
        string $sellerPubKeyHex,
        string $orderNonceHex,
        TimeoutPolicy $timeoutPolicy,
        int $fundedAtUnixTime
    ): EscrowAddress;
}
