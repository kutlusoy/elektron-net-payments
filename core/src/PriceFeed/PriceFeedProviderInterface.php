<?php

namespace ElektronNet\Payments\Core\PriceFeed;

/**
 * Best-effort ELEK/fiat exchange rate access (see
 * doc-elektron/guideline-standalone-payment-server.md section 12).
 * Mirrors ChainData\ChainDataProviderInterface's own pattern exactly: an
 * interface, a fallback-capable aggregator (FallbackPriceFeedProvider) if
 * more than one source ever exists, and "no rate available right now" as
 * a normal, expected outcome, never an error.
 *
 * No implementation ships with core/ yet -- ELEK is not currently listed
 * on any exchange, so there is nothing to query. The interface exists so
 * that plugging one in later (once a real feed exists) is a config
 * change, not a redesign: a `pay-server` deployment with no provider
 * configured simply never shows an optional fiat readout, which is the
 * correct behavior per section 12, not a degraded one.
 */
interface PriceFeedProviderInterface
{
    /**
     * How many units of $fiatCurrencyCode (an ISO 4217 code, e.g. 'USD')
     * one whole ELEK is worth right now, or null if this provider cannot
     * supply a rate at the moment (no data for that currency, endpoint
     * unreachable, not yet synced, ...).
     *
     * Implementations MUST NOT throw for "no rate available" -- that is
     * an expected, normal outcome (see FallbackPriceFeedProvider), not an
     * error.
     */
    public function getElekPriceInFiat(string $fiatCurrencyCode): ?float;
}
