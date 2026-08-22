<?php

namespace ElektronNet\Payments\Core\Escrow;

use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Network\Network;
use RuntimeException;

/**
 * Builds a bitwasp/bitcoin Network configured for Elektron Net rather than
 * Bitcoin. Bitcoin's base58/RPC-port parameters are reused as-is (Elektron
 * Net is a parameter fork, not a full protocol fork); only the segwit
 * bech32 prefix differs.
 *
 * STATUS: draft, not yet verified.
 * - Mainnet HRP `be` is confirmed against elektron-net-electrs's own notes
 *   (doc/elektron.md, §3.3, "mainnet bech32 HRP `be`").
 * - The exact bitwasp/bitcoin call for overriding a Network's bech32 prefix
 *   depends on the installed bitwasp/bitcoin version (older releases only
 *   allow this via a Network subclass, not a setter) and has not been
 *   checked against composer.json's pinned version; treat the call below as
 *   a placeholder to fix once that dependency is actually installed.
 * - Elektron Net's testnet/regtest bech32 HRPs are not documented anywhere
 *   in this repository yet, so no factory method for them is provided.
 *   Add one only once that value is confirmed from elektron-net's own
 *   chainparams.cpp, do not guess it.
 */
final class ElektronNetworkFactory
{
    private const MAINNET_BECH32_HRP = 'be';

    private function __construct()
    {
    }

    public static function mainnet(): Network
    {
        $network = NetworkFactory::bitcoin();

        if (!method_exists($network, 'setSegwitBech32Prefix')) {
            throw new RuntimeException(
                'This bitwasp/bitcoin version has no setSegwitBech32Prefix() setter; ' .
                'define a Network subclass overriding the bech32 HRP instead. See this class\'s docblock.'
            );
        }

        return $network->setSegwitBech32Prefix(self::MAINNET_BECH32_HRP);
    }
}
