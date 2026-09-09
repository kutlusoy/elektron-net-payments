<?php

namespace ElektronNet\Payments\Core\Escrow\Network;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptType;

/**
 * Elektron Net testnet/testnet4/signet parameters for bitwasp/bitcoin. See
 * ElektronMainnetNetwork's docblock for why this is a Network subclass
 * rather than a runtime-configured instance.
 *
 * Verified directly against elektron-net's own src/kernel/chainparams.cpp:
 * testnet, testnet4, and signet all share these same values there.
 *
 *   base58Prefixes[PUBKEY_ADDRESS] = 0x6F       -- identical to Bitcoin testnet
 *   base58Prefixes[SCRIPT_ADDRESS] = 0xC4       -- identical to Bitcoin testnet
 *   base58Prefixes[SECRET_KEY]     = 0xEF       -- identical to Bitcoin testnet
 *   base58Prefixes[EXT_PUBLIC_KEY] = 043587CF   -- identical to Bitcoin testnet
 *   base58Prefixes[EXT_SECRET_KEY] = 04358394   -- identical to Bitcoin testnet
 *   bech32_hrp                     = "tb"       -- also identical to Bitcoin testnet
 */
final class ElektronTestnetNetwork extends Network
{
    /** {@inheritdoc} */
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '6f',
        self::BASE58_ADDRESS_P2SH => 'c4',
        self::BASE58_WIF => 'ef',
    ];

    /** {@inheritdoc} */
    protected $bech32PrefixMap = [
        self::BECH32_PREFIX_SEGWIT => 'tb',
    ];

    /** {@inheritdoc} */
    protected $bip32PrefixMap = [
        self::BIP32_PREFIX_XPUB => '043587cf',
        self::BIP32_PREFIX_XPRV => '04358394',
    ];

    /** {@inheritdoc} */
    protected $bip32ScriptTypeMap = [
        self::BIP32_PREFIX_XPUB => ScriptType::P2PKH,
        self::BIP32_PREFIX_XPRV => ScriptType::P2PKH,
    ];
}
