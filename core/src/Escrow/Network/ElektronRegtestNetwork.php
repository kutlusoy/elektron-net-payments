<?php

namespace ElektronNet\Payments\Core\Escrow\Network;

use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptType;

/**
 * Elektron Net regtest parameters for bitwasp/bitcoin. See
 * ElektronMainnetNetwork's docblock for why this is a Network subclass
 * rather than a runtime-configured instance.
 *
 * Base58/BIP32 prefixes verified directly against elektron-net's own
 * src/kernel/chainparams.cpp: identical to testnet's (as they are for
 * Bitcoin itself). The bech32 HRP is not: chainparams.cpp sets
 * `bech32_hrp = "bcrt"` specifically for regtest, distinct from testnet's
 * "tb" -- deliberately NOT modeled here as "extends ElektronTestnetNetwork"
 * the way Bit-Wasp\Bitcoin\Network\Networks\BitcoinRegtest extends
 * BitcoinTestnet, because that upstream class inherits "tb" for regtest
 * too (a known staleness in that library relative to current Bitcoin Core,
 * where regtest's HRP is "bcrt") -- copying that inheritance here would
 * have copied the same mistake for Elektron Net's own confirmed-different
 * regtest HRP.
 */
final class ElektronRegtestNetwork extends Network
{
    /** {@inheritdoc} */
    protected $base58PrefixMap = [
        self::BASE58_ADDRESS_P2PKH => '6f',
        self::BASE58_ADDRESS_P2SH => 'c4',
        self::BASE58_WIF => 'ef',
    ];

    /** {@inheritdoc} */
    protected $bech32PrefixMap = [
        self::BECH32_PREFIX_SEGWIT => 'bcrt',
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
