<?php

namespace ElektronNet\Payments\Core\Psbt;

/**
 * Everything Bip174PsbtBuilder needs to describe one signing key's origin
 * for a PSBT_IN_BIP32_DERIVATION (or PSBT_OUT_BIP32_DERIVATION) field: the
 * actual pubkey used in the script/output, which xpub it was derived from,
 * and at which XpubChildKeyDeriver index (always `<xpub>/0/<index>`, see
 * that class's docblock).
 *
 * The PSBT fingerprint field is computed from $xpub itself (the account-
 * level extended key each party connected), not from any seed-level BIP32
 * root the platform has no way to know -- confirmed against
 * elektron-net-electrum's own real source
 * (electrum/bip32.py:calc_fingerprint_of_this_node(),
 * electrum/keystore.py:get_pubkey_derivation()'s "try fp against our
 * intermediate fingerprint" branch): a keystore backed by nothing but an
 * imported master public key resolves a PSBT's BIP32 derivation by matching
 * the fingerprint of that xpub node itself against a *full* (not
 * prefix-stripped) derivation path, which is exactly the shape this class
 * produces (path always exactly two components: 0, $index).
 */
final class PsbtKeyOrigin
{
    /** @var string 33-byte compressed pubkey, hex */
    private $pubKeyHex;

    /** @var string base58 xpub (already normalized to this network's own version bytes) */
    private $xpub;

    /** @var int XpubChildKeyDeriver index used to derive $pubKeyHex from $xpub */
    private $index;

    public function __construct(string $pubKeyHex, string $xpub, int $index)
    {
        $this->pubKeyHex = $pubKeyHex;
        $this->xpub = $xpub;
        $this->index = $index;
    }

    public function pubKeyHex(): string
    {
        return $this->pubKeyHex;
    }

    public function xpub(): string
    {
        return $this->xpub;
    }

    public function index(): int
    {
        return $this->index;
    }
}
