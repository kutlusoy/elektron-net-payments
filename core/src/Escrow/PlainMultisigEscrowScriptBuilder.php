<?php

namespace ElektronNet\Payments\Core\Escrow;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\Opcodes;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Buffertools\Buffer;

/**
 * Reference implementation of EscrowScriptBuilderInterface, built on
 * bitwasp/bitcoin. Supersedes BitwaspEscrowScriptBuilder (the nonce-prefixed
 * nested-conditional script): confirmed by actually running it, not
 * assumed, that neither the real Elektron Net wallet nor bitwasp/bitcoin's
 * own high-level Signer can sign that script at all, cooperative branch or
 * timeout branches alike. This builder produces the plain script every
 * wallet's own descriptor-based signing already understands:
 *
 *   2 <buyerPubKey> <sellerPubKey> 2 OP_CHECKMULTISIG
 *
 * i.e. `wsh(multi(2,buyerPubKey,sellerPubKey))`. There is no order nonce
 * and no OP_CHECKLOCKTIMEVERIFY branch in the script itself: the
 * one-address-per-order guarantee the old nonce provided is now the
 * caller's responsibility instead, by deriving a fresh child public key
 * per order from each party's xpub (see XpubChildKeyDeriver) rather than
 * reusing their registered key directly -- a nonce baked into the script
 * is exactly what made the old script unsignable, so per-order uniqueness
 * has to live outside the script from now on.
 *
 * The two timeout paths are NOT enforced on-chain by this script. That is
 * a real, currently-open gap (see the README's "Open Questions"): a
 * cooperative 2-of-2 script has no unilateral recourse if one party goes
 * silent. The planned fix is a pair of pre-signed, time-locked
 * transactions exchanged cooperatively at funding time (Lightning-channel
 * style), not a script change -- this class only builds the address; it
 * is not where that mechanism will live. $timeoutPolicy and
 * $fundedAtUnixTime are still accepted and still produce the two
 * locktimes on the returned EscrowAddress purely for display/reminder
 * purposes (order-status countdown, cron reminder emails): nothing here
 * currently reads them back to build a transaction.
 */
final class PlainMultisigEscrowScriptBuilder implements EscrowScriptBuilderInterface
{
    /** @var Network */
    private $network;

    public function __construct(Network $network)
    {
        $this->network = $network;
    }

    public function build(
        string $buyerPubKeyHex,
        string $sellerPubKeyHex,
        TimeoutPolicy $timeoutPolicy,
        int $fundedAtUnixTime
    ): EscrowAddress {
        $witnessScript = new WitnessScript(ScriptFactory::sequence([
            Opcodes::OP_2,
            Buffer::hex($buyerPubKeyHex),
            Buffer::hex($sellerPubKeyHex),
            Opcodes::OP_2,
            Opcodes::OP_CHECKMULTISIG,
        ]));

        $address = $witnessScript->getAddress();

        return new EscrowAddress(
            $address->getAddress($this->network),
            $witnessScript->getHex(),
            $timeoutPolicy->buyerRefundLocktime($fundedAtUnixTime),
            $timeoutPolicy->sellerReleaseLocktime($fundedAtUnixTime)
        );
    }
}
