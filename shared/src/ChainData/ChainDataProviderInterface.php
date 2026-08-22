<?php

namespace ElektronNet\Payments\Core\ChainData;

/**
 * Read-only chain-data access needed by the watcher. Deliberately has no
 * broadcast method: broadcasting is always done by the wallet that adds the
 * second signature, never by the plugin (see the README, "Chain data
 * access").
 */
interface ChainDataProviderInterface
{
    /**
     * @return AddressTransaction[] every known transaction touching $address
     */
    public function getAddressTransactions(string $address): array;

    /** Current confirmation count for a given transaction, 0 if unconfirmed/unknown. */
    public function getConfirmations(string $txid): int;
}
