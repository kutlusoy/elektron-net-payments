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

    /**
     * Every specific output across every known transaction that pays
     * $address directly. Needed to build a release/refund PSBT's input,
     * which references one exact (txid, vout) outpoint -- unlike
     * getAddressTransactions(), which only totals value per transaction and
     * does not identify which output(s) it came from.
     *
     * @return FundingOutput[]
     */
    public function getFundingOutputs(string $address): array;

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
