<?php

namespace ElektronNet\Payments\Core\Escrow;

use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\Hash;
use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\Opcodes;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Buffertools\Buffer;

/**
 * Reference implementation of EscrowScriptBuilderInterface, built on
 * bitwasp/bitcoin.
 *
 * STATUS: reference/draft, not verified against a live Elektron Net node.
 * Before this is trusted with real funds it MUST be checked on Elektron Net
 * regtest: confirm the produced address matches what `elektrond`/`elektron-cli`
 * expects for the same redeem script, and confirm both fallback paths
 * actually spend once their locktime has passed. See the class-level notes
 * on EscrowScriptBuilderInterface for the two Elektron-Net-specific details
 * (bech32 HRP, BIP113 median-time-past) that are easiest to get subtly wrong.
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
        TimeoutPolicy $timeoutPolicy,
        int $fundedAtUnixTime
    ): EscrowAddress {
        $buyerRefundLocktime = $timeoutPolicy->buyerRefundLocktime($fundedAtUnixTime);
        $sellerReleaseLocktime = $timeoutPolicy->sellerReleaseLocktime($fundedAtUnixTime);

        $buyerPubKey = Buffer::hex($buyerPubKeyHex);
        $sellerPubKey = Buffer::hex($sellerPubKeyHex);

        $cooperative = ScriptFactory::sequence([
            Opcodes::OP_2,
            $buyerPubKey,
            $sellerPubKey,
            Opcodes::OP_2,
            Opcodes::OP_CHECKMULTISIG,
        ]);

        $buyerRefund = ScriptFactory::sequence([
            ScriptFactory::scriptNum($buyerRefundLocktime),
            Opcodes::OP_CHECKLOCKTIMEVERIFY,
            Opcodes::OP_DROP,
            $buyerPubKey,
            Opcodes::OP_CHECKSIG,
        ]);

        $sellerRelease = ScriptFactory::sequence([
            ScriptFactory::scriptNum($sellerReleaseLocktime),
            Opcodes::OP_CHECKLOCKTIMEVERIFY,
            Opcodes::OP_DROP,
            $sellerPubKey,
            Opcodes::OP_CHECKSIG,
        ]);

        $witnessScript = new WitnessScript(ScriptFactory::sequence([
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

        $address = new SegwitAddress($witnessScript->getWitnessScriptHash($witnessScript));

        return new EscrowAddress(
            $address->getAddress($this->network),
            $witnessScript->getHex(),
            $buyerRefundLocktime,
            $sellerReleaseLocktime
        );
    }
}
