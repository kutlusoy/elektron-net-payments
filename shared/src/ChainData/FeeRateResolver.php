<?php

namespace ElektronNet\Payments\Core\ChainData;

/**
 * Resolves the fee rate a platform adapter should use for the release PSBT
 * (see Psbt\ReleasePsbtBuilderInterface): a live estimate from the
 * configured chain-data endpoints when one is available, falling back to
 * the marketplace's own fixed admin setting
 * (Config\PaymentsConfig::feeRateLepPerVByte()) otherwise.
 *
 * A live estimate keeps the release transaction from being underpriced
 * (stuck unconfirmed indefinitely) or overpriced (needlessly expensive) as
 * real network conditions drift away from whatever static value an admin
 * set once and may never revisit. The static value still matters: it is
 * what keeps a release from being blocked entirely just because every
 * configured chain-data endpoint happens to be unreachable at that moment.
 */
final class FeeRateResolver
{
    private function __construct()
    {
    }

    public static function resolve(ChainDataProviderInterface $chainData, int $staticFallbackLepPerVByte): int
    {
        $liveEstimate = $chainData->getFeeEstimateLepPerVByte();

        return $liveEstimate ?? $staticFallbackLepPerVByte;
    }
}
