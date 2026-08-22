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
 * IMPORTANT: any concrete implementation MUST be checked against Elektron
 * Net's own parameters before it is trusted with real funds, in particular:
 * the mainnet bech32 HRP (`be`, see elektron-net-electrs's doc/elektron.md
 * §3.3), and that OP_CHECKLOCKTIMEVERIFY is evaluated against
 * median-time-past when the locktime value looks like a Unix timestamp
 * (BIP113), which affects how close to a threshold a transaction can
 * actually be confirmed.
 */
interface EscrowScriptBuilderInterface
{
    /**
     * @param string $buyerPubKeyHex compressed public key, hex-encoded
     * @param string $sellerPubKeyHex compressed public key, hex-encoded
     */
    public function build(
        string $buyerPubKeyHex,
        string $sellerPubKeyHex,
        TimeoutPolicy $timeoutPolicy,
        int $fundedAtUnixTime
    ): EscrowAddress;
}
