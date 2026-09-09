<?php

namespace ElektronNet\Payments\PayServer;

/**
 * Server-wide configuration, loaded from environment variables (see
 * pay-server/docker/pay-api.env.example). Per-merchant overrides
 * (chain_endpoints, T1/T2 defaults, ...) live in the merchants table
 * instead, per doc-elektron/guideline-standalone-payment-server.md section 4.
 */
final class Config
{
    private string $network;
    private string $dbDsn;
    private string $dbUser;
    private string $dbPassword;
    private bool $escrowEnabled;
    private string $checkoutBaseUrl;
    /** @var array<int, array<string, mixed>> */
    private array $defaultChainEndpoints;
    /** @var array<int, array<string, mixed>> */
    private array $priceFeedEndpoints;

    /**
     * @param array<int, array<string, mixed>> $defaultChainEndpoints
     * @param array<int, array<string, mixed>> $priceFeedEndpoints
     */
    public function __construct(
        string $network,
        string $dbDsn,
        string $dbUser,
        string $dbPassword,
        bool $escrowEnabled,
        string $checkoutBaseUrl,
        array $defaultChainEndpoints,
        array $priceFeedEndpoints = []
    ) {
        $this->network = $network;
        $this->dbDsn = $dbDsn;
        $this->dbUser = $dbUser;
        $this->dbPassword = $dbPassword;
        $this->escrowEnabled = $escrowEnabled;
        $this->checkoutBaseUrl = rtrim($checkoutBaseUrl, '/');
        $this->defaultChainEndpoints = $defaultChainEndpoints;
        $this->priceFeedEndpoints = $priceFeedEndpoints;
    }

    public static function fromEnv(): self
    {
        $rawEndpoints = getenv('PAY_SERVER_CHAIN_ENDPOINTS');
        if ($rawEndpoints === false || trim($rawEndpoints) === '') {
            // Section 7's server-wide default: the three Esplora-compatible
            // mempool forks. Base path assumed as `/api`; see section 7's
            // open checklist item to confirm this against the deployed forks.
            $endpoints = [
                ['type' => 'esplora', 'base_url' => 'https://mempool.elektron-net.org/api'],
                ['type' => 'esplora', 'base_url' => 'https://mempool2.elektron-net.org/api'],
                ['type' => 'esplora', 'base_url' => 'https://mempool3.elektron-net.org/api'],
            ];
        } else {
            $decoded = json_decode($rawEndpoints, true);
            $endpoints = is_array($decoded) ? $decoded : [];
        }

        // Section 12: no hardcoded default -- ELEK is not listed on any
        // platform today, so an unset env var means "no price feed
        // configured" (an empty list), not a guess at a URL that would
        // 404. An operator opts in by pointing this at a real endpoint,
        // e.g. `[{"type":"simple_price","base_url":"https://api.coingecko.com/api/v3","coin_id":"elektron-net"}]`.
        $rawPriceFeedEndpoints = getenv('PAY_SERVER_PRICE_FEED_ENDPOINTS');
        $priceFeedEndpoints = [];
        if ($rawPriceFeedEndpoints !== false && trim($rawPriceFeedEndpoints) !== '') {
            $decodedPriceFeed = json_decode($rawPriceFeedEndpoints, true);
            $priceFeedEndpoints = is_array($decodedPriceFeed) ? $decodedPriceFeed : [];
        }

        return new self(
            (string) (getenv('PAY_SERVER_NETWORK') ?: 'mainnet'),
            (string) (getenv('PAY_SERVER_DB_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=pay_server'),
            (string) (getenv('PAY_SERVER_DB_USER') ?: 'pay_server'),
            (string) (getenv('PAY_SERVER_DB_PASSWORD') ?: ''),
            filter_var(getenv('PAY_SERVER_ESCROW_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            (string) (getenv('PAY_SERVER_CHECKOUT_BASE_URL') ?: 'https://pay.elektron-net.org'),
            $endpoints,
            $priceFeedEndpoints
        );
    }

    public function network(): string
    {
        return $this->network;
    }

    public function dbDsn(): string
    {
        return $this->dbDsn;
    }

    public function dbUser(): string
    {
        return $this->dbUser;
    }

    public function dbPassword(): string
    {
        return $this->dbPassword;
    }

    /**
     * Section 20: single server-wide flag, default false. Launch scope is
     * direct payments only; this is the one source of truth the API,
     * admin UI, and checkout UI all gate on.
     */
    public function escrowEnabled(): bool
    {
        return $this->escrowEnabled;
    }

    public function checkoutBaseUrl(): string
    {
        return $this->checkoutBaseUrl;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultChainEndpoints(): array
    {
        return $this->defaultChainEndpoints;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function priceFeedEndpoints(): array
    {
        return $this->priceFeedEndpoints;
    }
}
