<?php

namespace ElektronNet\Payments\Core\Psbt;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use ElektronNet\Payments\Core\Escrow\EscrowAddress;
use RuntimeException;

/**
 * Reference (and, for now, only) implementation of
 * ReleasePsbtBuilderInterface, built on bitwasp/bitcoin's low-level
 * primitives plus Bip174Writer for the outer BIP174 byte format -- see that
 * class's docblock for why this had to be hand-rolled instead of using an
 * existing PSBT library.
 *
 * Produces exactly one input (the escrow funding outpoint) and one output
 * (the seller's payout address), matching PlainMultisigEscrowScriptBuilder's
 * plain `2 <buyerPubKey> <sellerPubKey> 2 OP_CHECKMULTISIG` script -- there
 * is no change output because the entire escrow UTXO is always spent in one
 * go, and no other input because every escrow address is one-time-use (see
 * EsploraChainDataProvider's docblock).
 */
final class Bip174PsbtBuilder implements ReleasePsbtBuilderInterface
{
    /**
     * Conservative virtual-size estimate, in vBytes, for exactly this
     * transaction shape (1 P2WSH 2-of-2-multisig input, 1 P2WPKH output),
     * derived from BIP141's weight formula (weight = base_size*3 +
     * total_size, vsize = ceil(weight/4)):
     *
     *   base (non-witness) size: version(4) + incount(1) +
     *     [outpoint(36)+scriptSigLen(1)+sequence(4)](41) + outcount(1) +
     *     [value(8)+P2WPKH scriptPubKey len+script(23)](31) + locktime(4)
     *     = 82 bytes
     *   witness size: marker+flag(2) + itemcount(1) + dummy-OP_0(1) +
     *     sig1 (len-byte + up to 72 DER+sighash bytes)(73) +
     *     sig2(73) + witnessScript (len-byte + this script's fixed 71
     *     bytes)(72) = 222 bytes
     *   total_size = 82 + 222 = 304; weight = 82*3 + 304 = 550
     *   vsize = ceil(550 / 4) = 138, rounded up to 140 for headroom.
     *
     * Only a fee-estimate input, not consensus-critical: the actual
     * signatures each wallet produces decide the real final size, and any
     * of this project's supported wallets lets its own user review the fee
     * before signing.
     */
    private const ESTIMATED_RELEASE_VSIZE = 140;

    public function buildReleasePsbt(
        EscrowAddress $escrowAddress,
        string $fundingTxid,
        int $fundingVout,
        int $fundingAmountLep,
        PsbtKeyOrigin $buyerKey,
        PsbtKeyOrigin $sellerKey,
        string $sellerPayoutAddress,
        PsbtKeyOrigin $sellerPayoutKey,
        int $feeRateLepPerVByte,
        Network $network
    ): string {
        $fee = $feeRateLepPerVByte * self::ESTIMATED_RELEASE_VSIZE;
        $outputAmountLep = $fundingAmountLep - $fee;
        if ($outputAmountLep <= 0) {
            throw new RuntimeException(
                "Release PSBT: funding amount ({$fundingAmountLep} lep) does not cover the estimated fee ({$fee} lep)."
            );
        }

        $witnessScript = ScriptFactory::fromHex($escrowAddress->redeemScriptHex());
        $escrowScriptPubKey = (new WitnessScript($witnessScript))->getOutputScript();

        $payoutAddress = (new AddressCreator())->fromString($sellerPayoutAddress, $network);

        $unsignedTx = (new TxBuilder())
            ->input($fundingTxid, $fundingVout, null, 0xffffffff)
            ->payToAddress($outputAmountLep, $payoutAddress)
            ->get();

        $globalPairs = [
            ['key' => Bip174Writer::key(0x00), 'value' => $unsignedTx->getBinary()],
        ];

        $witnessUtxo = new TransactionOutput($fundingAmountLep, $escrowScriptPubKey);
        $inputPairs = [
            ['key' => Bip174Writer::key(0x01), 'value' => $witnessUtxo->getBinary()],
            ['key' => Bip174Writer::key(0x05), 'value' => $witnessScript->getBinary()],
            $this->bip32DerivationPair($buyerKey, $network),
            $this->bip32DerivationPair($sellerKey, $network),
        ];

        $outputPairs = [
            $this->bip32DerivationPair($sellerPayoutKey, $network, 0x02),
        ];

        $psbtBytes = Bip174Writer::encode($globalPairs, [$inputPairs], [$outputPairs]);

        return base64_encode($psbtBytes);
    }

    /**
     * @return array{key:string,value:string}
     */
    private function bip32DerivationPair(PsbtKeyOrigin $key, Network $network, int $keyType = 0x06): array
    {
        $fingerprint = (new HierarchicalKeyFactory())
            ->fromExtended($key->xpub(), $network)
            ->getPublicKey()
            ->getPubKeyHash()
            ->slice(0, 4)
            ->getBinary();

        $value = $fingerprint . Bip174Writer::uint32LE(0) . Bip174Writer::uint32LE($key->index());

        return [
            'key' => Bip174Writer::key($keyType, Buffer::hex($key->pubKeyHex())->getBinary()),
            'value' => $value,
        ];
    }
}
