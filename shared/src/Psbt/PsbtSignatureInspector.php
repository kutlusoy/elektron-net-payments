<?php

namespace ElektronNet\Payments\Core\Psbt;

use RuntimeException;

/**
 * Validates a PSBT the buyer's wallet hands back after signing, before the
 * plugin relays it on to the seller (see PsbtRelay's docblock: the plugin
 * never signs or broadcasts, but it does sit in the middle of this one
 * hand-off, so it is the only place that can catch a corrupted or
 * maliciously altered PSBT before it reaches the other party). This is
 * defense in depth, not the primary safety net -- the seller's own wallet
 * always shows them the real destination and amount before they sign,
 * exactly like it would for any other transaction -- but a platform that
 * *can* check should not skip checking just because something else also
 * would.
 *
 * Every field this project's own Bip174PsbtBuilder writes (the unsigned
 * transaction, witness UTXO, witness script, and both parties' BIP32
 * derivation entries) must come back byte-for-byte identical; only new
 * PSBT_IN_PARTIAL_SIG entries may be added, and only under a pubkey this
 * order's own script actually uses.
 */
final class PsbtSignatureInspector
{
    /**
     * @return int how many valid, expected partial signatures are present
     *     on input 0 of $submittedPsbtBase64
     * @throws RuntimeException if $submittedPsbtBase64 differs from
     *     $originalPsbtBase64 in anything other than added partial
     *     signatures under one of this order's own two pubkeys
     */
    public static function countSignaturesForInput0(string $submittedPsbtBase64, string $originalPsbtBase64): int
    {
        $submittedRaw = base64_decode($submittedPsbtBase64, true);
        if ($submittedRaw === false) {
            throw new RuntimeException('Not valid base64.');
        }

        $submitted = Bip174Reader::decode($submittedRaw);
        $original = Bip174Reader::decode(base64_decode($originalPsbtBase64));

        if ($submitted['unsignedTx']->getBinary() !== $original['unsignedTx']->getBinary()) {
            throw new RuntimeException('Submitted PSBT does not match the original unsigned transaction.');
        }
        if (!isset($submitted['inputs'][0])) {
            throw new RuntimeException('Submitted PSBT is missing its input map.');
        }

        $originalNonSigPairs = self::withoutPartialSigs($original['inputs'][0]);
        $submittedNonSigPairs = self::withoutPartialSigs($submitted['inputs'][0]);
        if ($originalNonSigPairs !== $submittedNonSigPairs) {
            throw new RuntimeException('Submitted PSBT changed a field other than its signatures (witness UTXO, witness script, or derivation info).');
        }

        $expectedPubKeys = array_keys(Bip174Reader::bip32Pubkeys($original['inputs'][0]));
        $signatures = Bip174Reader::partialSignatures($submitted['inputs'][0]);
        foreach (array_keys($signatures) as $pubKeyBinary) {
            if (!in_array($pubKeyBinary, $expectedPubKeys, true)) {
                throw new RuntimeException('Submitted PSBT contains a signature under an unexpected pubkey.');
            }
        }

        return count($signatures);
    }

    /**
     * @param array<int,array{key:string,value:string}> $inputMap
     * @return array<int,array{key:string,value:string}>
     */
    private static function withoutPartialSigs(array $inputMap): array
    {
        return array_values(array_filter($inputMap, function (array $pair): bool {
            return $pair['key'] === '' || ord($pair['key'][0]) !== 0x02;
        }));
    }
}
