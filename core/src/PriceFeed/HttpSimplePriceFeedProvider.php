<?php

namespace ElektronNet\Payments\Core\PriceFeed;

use Throwable;

/**
 * Section 12: a real PriceFeedProviderInterface implementation, shaped
 * after CoinGecko's "Simple Price" endpoint
 * (https://docs.coingecko.com/demo/reference/simple-price):
 * `GET {baseUrl}{pricePath}?{idsParam}={coinId}&{vsCurrenciesParam}={currency}`
 * returning `{"<coinId>": {"<currency>": <number>, ...}}`.
 *
 * Nothing here is CoinGecko-specific except the default parameter names --
 * several other price-feed platforms expose the same "simple price" shape
 * (a coin-id lookup against one or more fiat codes in a single call), so
 * one instance of this class per configured endpoint, aggregated through
 * FallbackPriceFeedProvider, is how multiple platforms are supported at
 * once (see PriceFeedProviderFactory). The base URL, coin id, and even the
 * query parameter names are all constructor arguments rather than
 * hardcoded, since ELEK is not listed on any of these platforms today --
 * an operator configures whichever URL/coin-id actually serves it (a
 * self-hosted mirror, a private index, or a public one once ELEK is
 * listed) via PAY_SERVER_PRICE_FEED_ENDPOINTS.
 */
final class HttpSimplePriceFeedProvider implements PriceFeedProviderInterface
{
    private string $baseUrl;
    private string $coinId;
    private string $pricePath;
    private string $idsParam;
    private string $vsCurrenciesParam;
    private ?string $apiKeyHeader;
    private ?string $apiKeyValue;
    private int $timeoutSeconds;

    public function __construct(
        string $baseUrl,
        string $coinId,
        string $pricePath = '/simple/price',
        string $idsParam = 'ids',
        string $vsCurrenciesParam = 'vs_currencies',
        ?string $apiKeyHeader = null,
        ?string $apiKeyValue = null,
        int $timeoutSeconds = 5
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->coinId = $coinId;
        $this->pricePath = '/' . ltrim($pricePath, '/');
        $this->idsParam = $idsParam;
        $this->vsCurrenciesParam = $vsCurrenciesParam;
        $this->apiKeyHeader = $apiKeyHeader;
        $this->apiKeyValue = $apiKeyValue;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Never throws: a request failure, an HTTP error, invalid JSON, or a
     * response missing this currency are all "no rate available right
     * now" per the interface contract, not an error worth surfacing to
     * the caller.
     */
    public function getElekPriceInFiat(string $fiatCurrencyCode): ?float
    {
        $currency = strtolower($fiatCurrencyCode);
        $url = $this->baseUrl . $this->pricePath . '?' . http_build_query([
            $this->idsParam => $this->coinId,
            $this->vsCurrenciesParam => $currency,
        ]);

        try {
            $decoded = $this->getJson($url);
        } catch (Throwable $e) {
            return null;
        }

        $rate = $decoded[$this->coinId][$currency] ?? null;
        if (!is_numeric($rate)) {
            return null;
        }

        return (float) $rate;
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $url): array
    {
        $headers = ['Accept: application/json'];
        if ($this->apiKeyHeader !== null && $this->apiKeyValue !== null) {
            $headers[] = $this->apiKeyHeader . ': ' . $this->apiKeyValue;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("Price-feed request failed ({$url}): curl error {$errno}.");
        }
        if ($httpCode >= 400) {
            throw new \RuntimeException("Price-feed request failed ({$url}): HTTP {$httpCode}.");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Price-feed request returned invalid JSON ({$url}).");
        }

        return $decoded;
    }
}
