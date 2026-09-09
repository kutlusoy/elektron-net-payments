<?php

namespace ElektronNet\Payments\Core\PriceFeed;

use Throwable;

/**
 * Tries each configured price-feed provider in order, moving to the next
 * one if the current one throws or has no rate for the requested
 * currency. Mirrors ChainData\FallbackChainDataProvider's own structure;
 * see that class's docblock for the reasoning this one shares.
 */
final class FallbackPriceFeedProvider implements PriceFeedProviderInterface
{
    /** @var PriceFeedProviderInterface[] in priority order */
    private array $providers;

    /**
     * @param PriceFeedProviderInterface[] $providers
     */
    public function __construct(array $providers)
    {
        $this->providers = array_values($providers);
    }

    /**
     * Unlike ChainData\FallbackChainDataProvider's own getAddressTransactions()
     * etc., every provider failing (or the list being empty) is not an
     * error here either: it means no rate is available anywhere right
     * now, which is section 12's own explicit "perfectly valid 'no
     * provider configured' state that is not an error".
     */
    public function getElekPriceInFiat(string $fiatCurrencyCode): ?float
    {
        foreach ($this->providers as $provider) {
            try {
                $rate = $provider->getElekPriceInFiat($fiatCurrencyCode);
            } catch (Throwable $e) {
                continue;
            }
            if ($rate !== null) {
                return $rate;
            }
        }

        return null;
    }
}
