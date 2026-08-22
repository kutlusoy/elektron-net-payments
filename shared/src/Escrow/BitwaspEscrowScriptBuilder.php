<?php

namespace ElektronNet\Payments\Core\Escrow;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\Interpreter\Number;
use BitWasp\Bitcoin\Script\Opcodes;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Buffertools\Buffer;

/**
 * Reference implementation of EscrowScriptBuilderInterface, built on
 * bitwasp/bitcoin.
 *
 * STATUS: reference/draft, checked against bitwasp/bitcoin's actual source
 * (cloned Bit-Wasp/bitcoin-php to verify every call below, not assumed from
 * its docs) but still NOT verified against a live Elektron Net node. Before
 * this is trusted with real funds it MUST be checked on Elektron Net
 * regtest: confirm the produced address matches what `elektrond`/`elektron-cli`
 * expects for the same redeem script, and confirm both fallback paths
 * actually spend once their locktime has passed. See the class-level notes
 * on EscrowScriptBuilderInterface for the two Elektron-Net-specific details
 * (bech32 HRP, BIP113 median-time-past) that are easiest to get subtly wrong.
 *
 * Two real bugs were caught and fixed by that source check, both of which
 * would have thrown on every single call:
 * - `ScriptFactory::scriptNum()` does not exist in this library; a CLTV
 *   locktime value must be pushed as a
 *   \BitWasp\Bitcoin\Script\Interpreter\Number (see its use below), which
 *   ScriptFactory::sequence() specifically knows how to encode. A bare int
 *   in that sequence is treated as an opcode, not a data push, so it is not
 *   a valid substitute either.
 * - `SegwitAddress`'s constructor requires a `WitnessProgram`, not a
 *   script-hash Buffer; `WitnessScript` already builds one internally and
 *   exposes it via `getAddress(): SegwitAddress`, which is what this class
 *   now calls instead of constructing a SegwitAddress by hand.
 */
final class BitwaspEscrowScriptBuilder implements EscrowScriptBuilderInterface
{
    /** @var Network */
    private $network;

    public function __construct(Network $network)
    {
        // Pass a Network instance whose getSegwitBech32Prefix() returns
        // Elektron Net's own HRP ('be' on mainnet), not Bitcoin's ('bc').
        $this->network = $network;
    }

    public function build(
        string $buyerPubKeyHex,
        string $sellerPubKeyHex,
        string $orderNonceHex,
        TimeoutPolicy $timeoutPolicy,
        int $fundedAtUnixTime
    ): EscrowAddress {
        $buyerRefundLocktime = $timeoutPolicy->buyerRefundLocktime($fundedAtUnixTime);
        $sellerReleaseLocktime = $timeoutPolicy->sellerReleaseLocktime($fundedAtUnixTime);

        $buyerPubKey = Buffer::hex($buyerPubKeyHex);
        $sellerPubKey = Buffer::hex($sellerPubKeyHex);
        $orderNonce = Buffer::hex($orderNonceHex);

        $cooperative = ScriptFactory::sequence([
            Opcodes::OP_2,
            $buyerPubKey,
            $sellerPubKey,
            Opcodes::OP_2,
            Opcodes::OP_CHECKMULTISIG,
        ]);

        $buyerRefund = ScriptFactory::sequence([
            Number::int($buyerRefundLocktime),
            Opcodes::OP_CHECKLOCKTIMEVERIFY,
            Opcodes::OP_DROP,
            $buyerPubKey,
            Opcodes::OP_CHECKSIG,
        ]);

        $sellerRelease = ScriptFactory::sequence([
            Number::int($sellerReleaseLocktime),
            Opcodes::OP_CHECKLOCKTIMEVERIFY,
            Opcodes::OP_DROP,
            $sellerPubKey,
            Opcodes::OP_CHECKSIG,
        ]);

        $witnessScript = new WitnessScript(ScriptFactory::sequence([
            $orderNonce,
            Opcodes::OP_DROP,
            Opcodes::OP_IF,
            $cooperative,
            Opcodes::OP_ELSE,
            Opcodes::OP_IF,
            $buyerRefund,
            Opcodes::OP_ELSE,
            $sellerRelease,
            Opcodes::OP_ENDIF,
            Opcodes::OP_ENDIF,
        ]));

        $address = $witnessScript->getAddress();

        return new EscrowAddress(
            $address->getAddress($this->network),
            $witnessScript->getHex(),
            $buyerRefundLocktime,
            $sellerReleaseLocktime
        );
    }
}
