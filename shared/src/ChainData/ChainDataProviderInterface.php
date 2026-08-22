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

    /**
     * Best-effort live fee-rate estimate, in lep/vByte, for a transaction
     * the caller wants confirmed at a moderate priority (not the cheapest
     * possible, not the fastest possible). Returns null when this provider
     * cannot supply one right now (endpoint unreachable, node not yet
     * synced, malformed response, ...).
     *
     * Implementations MUST NOT throw for "no estimate available" -- that is
     * an expected, normal outcome (see FeeRateResolver), not an error.
     */
    public function getFeeEstimateLepPerVByte(): ?int;
}
