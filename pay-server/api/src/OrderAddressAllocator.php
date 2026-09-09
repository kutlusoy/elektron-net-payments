<?php

namespace ElektronNet\Payments\PayServer;

use BitWasp\Bitcoin\Network\Network;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;

/**
 * Direct-mode address derivation (section 8/9): every order gets a fresh
 * child address derived from the merchant's own connected `receiving_xpub`
 * via core's XpubChildKeyDeriver, exactly the deriver escrow mode already
 * uses -- never a private key `pay-server` generates or holds itself, and
 * never the same address handed out twice. The actual monotonic index
 * comes from Db\MerchantRepository::claimNextReceivingIndex(); this class
 * only turns (xpub, index) into an address.
 *
 * Deliberately uses deriveChildAddress() (a plain P2WPKH address) rather
 * than an escrow P2WSH script: direct mode has no counterparty and no
 * multisig, so the merchant's own single derived key is the whole payment
 * destination.
 */
final class OrderAddressAllocator
{
    private XpubChildKeyDeriver $deriver;
    private Network $network;

    public function __construct(XpubChildKeyDeriver $deriver, Network $network)
    {
        $this->deriver = $deriver;
        $this->network = $network;
    }

    public function deriveDirectAddress(string $xpubBase58, int $childIndex): string
    {
        return $this->deriver->deriveChildAddress($xpubBase58, $childIndex, $this->network);
    }
}
