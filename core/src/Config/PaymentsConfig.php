<?php

namespace ElektronNet\Payments\Core\Config;

use ElektronNet\Payments\Core\Escrow\TimeoutPolicy;
use InvalidArgumentException;

/**
 * Validated, platform-independent configuration for one marketplace installation.
 *
 * A platform adapter (osclass-escrow, a future WooCommerce adapter, etc.) is
 * responsible for loading these values from its own admin-settings storage
 * (Osclass Preference rows, WordPress options, ...) and handing them to the
 * core as a single PaymentsConfig instance. The core never reads platform
 * storage directly, which is what keeps it reusable across platforms.
 */
final class PaymentsConfig
{
    /** @var string one of 'mainnet', 'testnet', 'regtest' */
    private $network;

    /** @var string[] ordered list of chain-data endpoint URIs, first = primary */
    private $chainDataEndpoints;

    /** @var int confirmations required before an order is considered funded */
    private $requiredConfirmations;

    /** @var TimeoutPolicy */
    private $timeoutPolicy;

    /**
     * @var int fee rate in lep/vByte, used as the fallback when
     *     constructing the release PSBT if ChainData\FeeRateResolver
     *     could not get a live estimate from any configured endpoint
     */
    private $feeRateLepPerVByte;

    public function __construct(
        string $network,
        array $chainDataEndpoints,
        int $requiredConfirmations,
        TimeoutPolicy $timeoutPolicy,
        int $feeRateLepPerVByte
    ) {
        if (!in_array($network, ['mainnet', 'testnet', 'regtest'], true)) {
            throw new InvalidArgumentException("Unknown network '{$network}'.");
        }
        if (count($chainDataEndpoints) < 1) {
            throw new InvalidArgumentException('At least one chain-data endpoint is required.');
        }
        if ($requiredConfirmations < 1) {
            throw new InvalidArgumentException('requiredConfirmations must be at least 1.');
        }
        if ($feeRateLepPerVByte < 1) {
            throw new InvalidArgumentException('feeRateLepPerVByte must be at least 1.');
        }

        $this->network = $network;
        $this->chainDataEndpoints = array_values($chainDataEndpoints);
        $this->requiredConfirmations = $requiredConfirmations;
        $this->timeoutPolicy = $timeoutPolicy;
        $this->feeRateLepPerVByte = $feeRateLepPerVByte;
    }

    public function network(): string
    {
        return $this->network;
    }

    /** @return string[] */
    public function chainDataEndpoints(): array
    {
        return $this->chainDataEndpoints;
    }

    public function requiredConfirmations(): int
    {
        return $this->requiredConfirmations;
    }

    public function timeoutPolicy(): TimeoutPolicy
    {
        return $this->timeoutPolicy;
    }

    /**
     * The fixed fallback rate, in lep/vByte. Callers building a release
     * PSBT should not use this directly -- pass it as the
     * $staticFallbackLepPerVByte argument to
     * ChainData\FeeRateResolver::resolve() instead, so a live estimate is
     * preferred when one is available.
     */
    public function feeRateLepPerVByte(): int
    {
        return $this->feeRateLepPerVByte;
    }
}
