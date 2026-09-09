<?php

namespace ElektronNet\Payments\Core\Escrow;

use BitWasp\Bitcoin\Network\Network;
use ElektronNet\Payments\Core\Escrow\Network\ElektronMainnetNetwork;
use ElektronNet\Payments\Core\Escrow\Network\ElektronRegtestNetwork;
use ElektronNet\Payments\Core\Escrow\Network\ElektronTestnetNetwork;

/**
 * Builds a bitwasp/bitcoin Network configured for Elektron Net rather than
 * Bitcoin. Bitcoin's base58/xpub/xprv parameters are reused as-is (Elektron
 * Net is a parameter fork, not a full protocol fork); only the segwit
 * bech32 prefix differs. See the three classes in Network/ for the actual
 * parameter values and why each is its own Network subclass rather than a
 * runtime-configured Bitcoin instance -- in short, bitwasp/bitcoin has no
 * public API to change an already-built Network's prefixes, confirmed by
 * reading its actual source (Bit-Wasp/bitcoin-php), not assumed. An earlier
 * version of this factory called a `setSegwitBech32Prefix()` method that
 * does not exist in that library and would have thrown on every call; this
 * version only ever instantiates the dedicated subclasses, so there is no
 * such method-existence check left to get wrong.
 *
 * STATUS: verified directly against elektron-net's own
 * src/kernel/chainparams.cpp (not against secondary docs), which
 * doc-elektron/guideline-wallet-integration.md §2.1 itself names as the
 * ground truth to check against. See Network/ElektronMainnetNetwork.php,
 * Network/ElektronTestnetNetwork.php, Network/ElektronRegtestNetwork.php
 * for the exact values and per-network notes.
 *
 * SLIP-44 coin type 1370 (see doc-elektron/CHANGELOG-slip44-coin-type.md)
 * is a wallet-side HD *derivation path* convention (m/../1370'/..); it has
 * no bearing on this class or on the escrow script/address this package
 * builds. This package never derives keys itself -- it only ever receives
 * an already-derived compressed public key, as raw hex, from whichever
 * wallet the buyer or seller used (see EscrowScriptBuilderInterface). A
 * pubkey derived under the legacy coin type `0'` and one derived under
 * `1370'` are equally valid input here; neither this class nor the
 * multisig/timeout script cares which path produced the key.
 */
final class ElektronNetworkFactory
{
    private function __construct()
    {
    }

    public static function mainnet(): Network
    {
        return new ElektronMainnetNetwork();
    }

    public static function testnet(): Network
    {
        return new ElektronTestnetNetwork();
    }

    public static function regtest(): Network
    {
        return new ElektronRegtestNetwork();
    }
}
