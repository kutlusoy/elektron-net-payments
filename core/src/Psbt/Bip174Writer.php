<?php

namespace ElektronNet\Payments\Core\Psbt;

use InvalidArgumentException;

/**
 * Minimal, hand-rolled BIP174 (Partially Signed Bitcoin Transaction) binary
 * encoder. Written from scratch because no PHP library implements PSBT at
 * all: confirmed by exhaustive source search that bitwasp/bitcoin (this
 * project's only Bitcoin library, every released version up to v1.1.0) has
 * no PSBT/BIP174 support whatsoever, and no standalone PHP PSBT library
 * exists on Packagist either. bitwasp's low-level primitives (Buffer,
 * Script, Transaction, TransactionOutput) still do all the actual
 * transaction/script construction; this class only implements the outer
 * BIP174 byte format around them.
 *
 * Deliberately generic at this layer (raw key/value byte pairs in, raw PSBT
 * bytes out) -- the Bitcoin-specific meaning of each field (which type byte
 * means what) lives in Bip174PsbtBuilder, not here, so this class's own
 * correctness can be checked purely against the BIP174 spec's byte-layout
 * rules and its official test vectors, independent of any escrow-specific
 * logic.
 *
 * Spec: github.com/bitcoin/bips/blob/master/bip-0174.mediawiki
 *   <psbt> := <magic> <global-map> <input-map>* <output-map>*
 *   <magic> := 0x70 0x73 0x62 0x74 0xff ("psbt" + 0xff separator)
 *   <global-map> / <input-map> / <output-map> := <keypair>* 0x00
 *   <keypair> := <key> <value>
 *   <key> := <keylen> <keytype> <keydata>   (keylen counts keytype+keydata)
 *   <value> := <valuelen> <valuedata>
 * <keylen> and <valuelen> are BIP174/Bitcoin "compact size" unsigned
 * integers, minimally encoded (same encoding Bitcoin transactions use for
 * varints).
 */
final class Bip174Writer
{
    private const MAGIC = "\x70\x73\x62\x74\xff";

    /**
     * @param array<int,array{key:string,value:string}> $globalPairs
     * @param array<int,array<int,array{key:string,value:string}>> $inputMaps one map per transaction input, in order
     * @param array<int,array<int,array{key:string,value:string}>> $outputMaps one map per transaction output, in order
     */
    public static function encode(array $globalPairs, array $inputMaps, array $outputMaps): string
    {
        $out = self::MAGIC . self::encodeMap($globalPairs);
        foreach ($inputMaps as $pairs) {
            $out .= self::encodeMap($pairs);
        }
        foreach ($outputMaps as $pairs) {
            $out .= self::encodeMap($pairs);
        }

        return $out;
    }

    /**
     * @param array<int,array{key:string,value:string}> $pairs
     */
    public static function encodeMap(array $pairs): string
    {
        $out = '';
        foreach ($pairs as $pair) {
            $out .= self::compactSize(strlen($pair['key'])) . $pair['key'];
            $out .= self::compactSize(strlen($pair['value'])) . $pair['value'];
        }

        return $out . "\x00";
    }

    /**
     * Builds a PSBT map key: <keytype> (itself compact-size encoded, always
     * a single byte for every type this project uses, all of which are
     * < 0xfd) followed by the type-specific key data (e.g. a pubkey for
     * PSBT_IN_BIP32_DERIVATION), which is empty for most field types.
     */
    public static function key(int $type, string $keyData = ''): string
    {
        return self::compactSize($type) . $keyData;
    }

    public static function compactSize(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('compactSize() cannot encode a negative value.');
        }
        if ($value < 0xfd) {
            return chr($value);
        }
        if ($value <= 0xffff) {
            return "\xfd" . pack('v', $value);
        }
        if ($value <= 0xffffffff) {
            return "\xfe" . pack('V', $value);
        }

        return "\xff" . pack('P', $value);
    }

    /** 4-byte little-endian uint32 -- one component of a BIP32 derivation path (never hardened here, see Bip174PsbtBuilder). */
    public static function uint32LE(int $value): string
    {
        return pack('V', $value);
    }
}
