<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\Core\PriceFeed\FallbackPriceFeedProvider;
use ElektronNet\Payments\Core\PriceFeed\HttpSimplePriceFeedProvider;
use ElektronNet\Payments\Core\PriceFeed\PriceFeedProviderInterface;

/**
 * Builds a PriceFeedProviderInterface from PAY_SERVER_PRICE_FEED_ENDPOINTS
 * (section 12), mirroring how ChainDataProviderFactory turns
 * PAY_SERVER_CHAIN_ENDPOINTS into a FallbackChainDataProvider: a JSON
 * array of endpoint objects, one HttpSimplePriceFeedProvider per entry,
 * tried in order via FallbackPriceFeedProvider. Unlike chain endpoints
 * there is no per-merchant override column (section 12 treats the feed as
 * server-wide) and no hardcoded default -- ELEK is not listed on any
 * platform today, so an empty list is the correct out-of-the-box state,
 * not a fallback to guess at.
 */
final class PriceFeedProviderFactory
{
    private function __construct()
    {
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     */
    public static function build(array $endpoints): PriceFeedProviderInterface
    {
        $providers = [];
        foreach ($endpoints as $endpoint) {
            if (($endpoint['type'] ?? null) !== 'simple_price') {
                continue;
            }
            if (!isset($endpoint['base_url'], $endpoint['coin_id'])) {
                continue;
            }

            $providers[] = new HttpSimplePriceFeedProvider(
                (string) $endpoint['base_url'],
                (string) $endpoint['coin_id'],
                isset($endpoint['price_path']) ? (string) $endpoint['price_path'] : '/simple/price',
                isset($endpoint['ids_param']) ? (string) $endpoint['ids_param'] : 'ids',
                isset($endpoint['vs_currencies_param']) ? (string) $endpoint['vs_currencies_param'] : 'vs_currencies',
                isset($endpoint['api_key_header']) ? (string) $endpoint['api_key_header'] : null,
                isset($endpoint['api_key']) ? (string) $endpoint['api_key'] : null
            );
        }

        return new FallbackPriceFeedProvider($providers);
    }
}
