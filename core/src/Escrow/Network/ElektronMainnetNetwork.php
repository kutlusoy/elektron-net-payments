<?php

namespace ElektronNet\Payments\Core\Escrow\Network;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptType;

/**
 * Elektron Net mainnet parameters for bitwasp/bitcoin.
 *
 * bitwasp/bitcoin (package `bitwasp/bitcoin`, repository Bit-Wasp/bitcoin-php)
 * has no public method to change an already-built Network's prefixes: its
 * base58/bech32/bip32 prefix maps are `protected` properties, populated only
 * by a subclass's own property declarations, exactly the way
 * BitWasp\Bitcoin\Network\Networks\Bitcoin itself is written. A dedicated
 * subclass, following that same pattern, is therefore the only correct way
 * to represent a parameter-fork network with this library; an earlier
 * version of this code (ElektronNetworkFactory) tried to call a
 * `setSegwitBech32Prefix()` setter that does not exist in this library and
 * would always have thrown at runtime. Confirmed directly by cloning
 * Bit-Wasp/bitcoin-php and reading src/Network/Network.php and
 * src/Network/Networks/Bitcoin.php.
 *
 * Every value below is verified directly against elektron-net's own
 * src/kernel/chainparams.cpp (the ground truth per
 * doc-elektron/guideline-wallet-integration.md §2.1), not against secondary
 * documentation:
 *
 *   base58Prefixes[PUBKEY_ADDRESS] = 0x00       -- identical to Bitcoin mainnet
 *   base58Prefixes[SCRIPT_ADDRESS] = 0x05       -- identical to Bitcoin mainnet
 *   base58Prefixes[SECRET_KEY]     = 0x80       -- identical to Bitcoin mainnet
 *   base58Prefixes[EXT_PUBLIC_KEY] = 0488B21E   -- identical to Bitcoin mainnet
 *   base58Prefixes[EXT_SECRET_KEY] = 0488ADE4   -- identical to Bitcoin mainnet
 *   bech32_hrp                     = "be"       -- the one value that differs
 *                                                   from Bitcoin mainnet's "bc"
 *
 * `signedMessagePrefix` and `p2pMagic` are deliberately left unset: neither
 * is used anywhere in this package's escrow-script/address code (verified:
 * only BitWasp\Bitcoin\MessageSigner\MessageSigner reads
 * getSignedMessageMagic(), which this package never calls), and guessing
 * bitwasp's exact byte-order convention for either just to fill them in
 * would violate the same "do not guess" standard the values above were
 * held to. Leaving them unset is valid: Network's constructor only
 * validates p2pMagic "if not null", and getSignedMessageMagic() already
 * has its own null fallback.
 */
final class ElektronMainnetNetwork extends Network
{
    /** {@inheritdoc} */
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '00',
        self::BASE58_ADDRESS_P2SH => '05',
        self::BASE58_WIF => '80',
    ];

    /** {@inheritdoc} */
    protected $bech32PrefixMap = [
        self::BECH32_PREFIX_SEGWIT => 'be',
    ];

    /** {@inheritdoc} */
    protected $bip32PrefixMap = [
        self::BIP32_PREFIX_XPUB => '0488b21e',
        self::BIP32_PREFIX_XPRV => '0488ade4',
    ];

    /** {@inheritdoc} */
    protected $bip32ScriptTypeMap = [
        self::BIP32_PREFIX_XPUB => ScriptType::P2PKH,
        self::BIP32_PREFIX_XPRV => ScriptType::P2PKH,
    ];
}
