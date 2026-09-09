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
 * transaction, and per input the witness UTXO, witness script, and both
 * parties' BIP32 derivation entries) must come back byte-for-byte
 * identical; only new PSBT_IN_PARTIAL_SIG entries may be added, only under
 * a pubkey this order's own script actually uses, and consistently across
 * every input -- Bip174PsbtBuilder can produce more than one input (see its
 * docblock: a resumed order whose buyer paid more than once), and a real
 * wallet signs every input it holds a key for in one pass, so a PSBT that
 * signed some of its inputs but not others is treated as invalid rather
 * than guessed at.
 */
final class PsbtSignatureInspector
{
    /**
     * @return int how many valid, expected partial signatures are present,
     *     consistently across every input, of $submittedPsbtBase64
     * @throws RuntimeException if $submittedPsbtBase64 differs from
     *     $originalPsbtBase64 in anything other than added partial
     *     signatures under one of this order's own two pubkeys, applied
     *     identically to every input
     */
    public static function countSignatures(string $submittedPsbtBase64, string $originalPsbtBase64): int
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
        if (count($submitted['inputs']) !== count($original['inputs'])) {
            throw new RuntimeException('Submitted PSBT has a different number of inputs.');
        }

        $signatureCount = null;
        foreach ($original['inputs'] as $index => $originalInputMap) {
            $submittedInputMap = $submitted['inputs'][$index];

            $originalNonSigPairs = self::withoutPartialSigs($originalInputMap);
            $submittedNonSigPairs = self::withoutPartialSigs($submittedInputMap);
            if ($originalNonSigPairs !== $submittedNonSigPairs) {
                throw new RuntimeException("Submitted PSBT changed a field other than its signatures on input {$index} (witness UTXO, witness script, or derivation info).");
            }

            $expectedPubKeys = array_keys(Bip174Reader::bip32Pubkeys($originalInputMap));
            $signatures = Bip174Reader::partialSignatures($submittedInputMap);
            foreach (array_keys($signatures) as $pubKeyBinary) {
                if (!in_array($pubKeyBinary, $expectedPubKeys, true)) {
                    throw new RuntimeException("Submitted PSBT contains a signature under an unexpected pubkey on input {$index}.");
                }
            }

            if ($signatureCount === null) {
                $signatureCount = count($signatures);
            } elseif ($signatureCount !== count($signatures)) {
                throw new RuntimeException('Submitted PSBT signed some inputs but not others.');
            }
        }

        return $signatureCount ?? 0;
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
