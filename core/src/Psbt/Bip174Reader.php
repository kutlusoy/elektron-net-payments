<?php

namespace ElektronNet\Payments\Core\Psbt;

use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\Buffer;
use RuntimeException;

/**
 * Counterpart to Bip174Writer: parses PSBT bytes back into raw key/value
 * pairs, self-delimited exactly like the format itself is (see
 * Bip174Writer's docblock for the byte layout and why this is hand-rolled).
 *
 * The number of input/output maps is not read from a length field of its
 * own -- BIP174 does not have one -- but derived from the
 * PSBT_GLOBAL_UNSIGNED_TX field's own input/output counts, exactly as every
 * real PSBT parser does. After that, every byte of the input just handed in
 * MUST be consumed; anything left over is treated as malformed rather than
 * silently ignored, since a buyer or seller's browser round-trips this
 * value from their own wallet before the platform ever hands it to the
 * other party (see Bip174PsbtBuilder's docblock on why that hand-off is
 * validated at all, given this plugin never signs or broadcasts).
 */
final class Bip174Reader
{
    private const MAGIC = "\x70\x73\x62\x74\xff";

    /** @var string */
    private $bytes;

    /** @var int */
    private $offset = 0;

    private function __construct(string $bytes)
    {
        $this->bytes = $bytes;
    }

    /**
     * @return array{
     *     unsignedTx: TransactionInterface,
     *     global: array<int,array{key:string,value:string}>,
     *     inputs: array<int,array<int,array{key:string,value:string}>>,
     *     outputs: array<int,array<int,array{key:string,value:string}>>
     * }
     */
    public static function decode(string $bytes): array
    {
        $reader = new self($bytes);
        $reader->readMagic();

        $global = $reader->readMap();
        $unsignedTxBytes = null;
        foreach ($global as $pair) {
            if ($pair['key'] === Bip174Writer::key(0x00)) {
                $unsignedTxBytes = $pair['value'];
                break;
            }
        }
        if ($unsignedTxBytes === null) {
            throw new RuntimeException('Not a valid PSBT: missing PSBT_GLOBAL_UNSIGNED_TX.');
        }
        $unsignedTx = TransactionFactory::fromBuffer(new Buffer($unsignedTxBytes));

        $inputs = [];
        for ($i = 0, $n = count($unsignedTx->getInputs()); $i < $n; $i++) {
            $inputs[] = $reader->readMap();
        }

        $outputs = [];
        for ($i = 0, $n = count($unsignedTx->getOutputs()); $i < $n; $i++) {
            $outputs[] = $reader->readMap();
        }

        if ($reader->offset !== strlen($reader->bytes)) {
            throw new RuntimeException('Not a valid PSBT: trailing data after the expected number of input/output maps.');
        }

        return ['unsignedTx' => $unsignedTx, 'global' => $global, 'inputs' => $inputs, 'outputs' => $outputs];
    }

    /**
     * Every PSBT_IN_PARTIAL_SIG (key type 0x02) entry present for one input
     * map, keyed by the signing pubkey (33-byte compressed, the key data
     * that follows the type byte).
     *
     * @param array<int,array{key:string,value:string}> $inputMap
     * @return array<string,string> pubkey (binary) => DER signature + sighash byte (binary)
     */
    public static function partialSignatures(array $inputMap): array
    {
        $sigs = [];
        foreach ($inputMap as $pair) {
            if ($pair['key'] !== '' && ord($pair['key'][0]) === 0x02) {
                $sigs[substr($pair['key'], 1)] = $pair['value'];
            }
        }

        return $sigs;
    }

    /**
     * Every PSBT_IN_BIP32_DERIVATION (key type 0x06) entry present for one
     * input map, keyed by the pubkey (33-byte compressed, binary) it
     * describes the origin of.
     *
     * @param array<int,array{key:string,value:string}> $inputMap
     * @return array<string,string> pubkey (binary) => fingerprint+path value (binary)
     */
    public static function bip32Pubkeys(array $inputMap): array
    {
        $entries = [];
        foreach ($inputMap as $pair) {
            if ($pair['key'] !== '' && ord($pair['key'][0]) === 0x06) {
                $entries[substr($pair['key'], 1)] = $pair['value'];
            }
        }

        return $entries;
    }

    private function readMagic(): void
    {
        if (substr($this->bytes, 0, 5) !== self::MAGIC) {
            throw new RuntimeException('Not a valid PSBT: missing magic bytes.');
        }
        $this->offset = 5;
    }

    /**
     * @return array<int,array{key:string,value:string}>
     */
    private function readMap(): array
    {
        $pairs = [];
        while (true) {
            $keyLen = $this->readCompactSize();
            if ($keyLen === 0) {
                break; // 0x00 map terminator
            }
            $key = $this->readBytes($keyLen);
            $valueLen = $this->readCompactSize();
            $value = $this->readBytes($valueLen);
            $pairs[] = ['key' => $key, 'value' => $value];
        }

        return $pairs;
    }

    private function readCompactSize(): int
    {
        $first = ord($this->readBytes(1));
        if ($first < 0xfd) {
            return $first;
        }
        if ($first === 0xfd) {
            return unpack('v', $this->readBytes(2))[1];
        }
        if ($first === 0xfe) {
            return unpack('V', $this->readBytes(4))[1];
        }

        return unpack('P', $this->readBytes(8))[1];
    }

    private function readBytes(int $n): string
    {
        if ($n < 0 || $this->offset + $n > strlen($this->bytes)) {
            throw new RuntimeException('Not a valid PSBT: unexpected end of data.');
        }
        $out = substr($this->bytes, $this->offset, $n);
        $this->offset += $n;

        return $out;
    }
}
