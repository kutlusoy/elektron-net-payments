<?php

namespace ElektronNet\Payments\PayWatcher;

use ElektronNet\Payments\Core\ChainData\ChainDataProviderInterface;
use ElektronNet\Payments\Core\ChainData\EsploraChainDataProvider;
use ElektronNet\Payments\Core\ChainData\FallbackChainDataProvider;

/**
 * Section 7: "A merchant MAY override this list entirely in their own
 * chain_endpoints column; if null, the server-wide default above
 * applies." Builds (and caches, since most merchants share the same
 * server-wide default) a FallbackChainDataProvider per distinct endpoint
 * list, so the watcher polls each order against the right set of
 * endpoints instead of always the server-wide default.
 */
final class ChainDataProviderFactory
{
    /** @var array<int, array<string, mixed>> */
    private array $defaultEndpoints;

    /** @var array<string, ChainDataProviderInterface> keyed by a hash of the resolved endpoint list */
    private array $cache = [];

    /**
     * @param array<int, array<string, mixed>> $defaultEndpoints
     */
    public function __construct(array $defaultEndpoints)
    {
        $this->defaultEndpoints = $defaultEndpoints;
    }

    /**
     * @param string|null $merchantChainEndpointsJson raw `merchants.chain_endpoints` JSONB value, or null
     */
    public function forMerchant(?string $merchantChainEndpointsJson): ChainDataProviderInterface
    {
        $endpoints = $this->defaultEndpoints;

        if ($merchantChainEndpointsJson !== null) {
            $decoded = json_decode($merchantChainEndpointsJson, true);
            if (is_array($decoded) && $decoded !== []) {
                $endpoints = $decoded;
            }
        }

        $cacheKey = md5(json_encode($endpoints));
        if (!isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = $this->build($endpoints);
        }

        return $this->cache[$cacheKey];
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     */
    private function build(array $endpoints): ChainDataProviderInterface
    {
        $providers = [];
        foreach ($endpoints as $endpoint) {
            if (($endpoint['type'] ?? null) === 'esplora' && isset($endpoint['base_url'])) {
                $providers[] = new EsploraChainDataProvider((string) $endpoint['base_url']);
            }
            // 'electrum' endpoints (section 7's future tier) need
            // ElectrumChainDataProvider, which does not exist yet; entries
            // of that type are skipped, matching watcher/bin/watch.php's
            // own existing handling of the server-wide default list.
        }

        if ($providers === []) {
            // A merchant override that resolved to nothing usable (e.g.
            // electrum-only, or malformed) falls back to the server-wide
            // default rather than leaving the order with no chain-data
            // access at all.
            foreach ($this->defaultEndpoints as $endpoint) {
                if (($endpoint['type'] ?? null) === 'esplora' && isset($endpoint['base_url'])) {
                    $providers[] = new EsploraChainDataProvider((string) $endpoint['base_url']);
                }
            }
        }

        return new FallbackChainDataProvider($providers);
    }
}
