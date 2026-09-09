<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Db\Merchant;
use ElektronNet\Payments\PayServer\Http\ApiException;

/**
 * Pure validation for `POST /v1/orders` (section 5), split out from
 * Http\Controllers\OrdersController so the rules are independently
 * testable without a database.
 */
final class OrderValidation
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException on any validation failure, with a typed error code
     */
    public static function validateCreateOrderPayload(array $payload, Merchant $merchant, bool $escrowEnabled): void
    {
        $mode = $payload['mode'] ?? OrderMode::DIRECT;
        if (!is_string($mode) || !in_array($mode, [OrderMode::DIRECT, OrderMode::ESCROW], true)) {
            throw ApiException::validationError('mode must be "direct" or "escrow".');
        }

        // Section 20: `POST /v1/orders` MUST reject `mode: "escrow"` with a
        // clear, typed error while the flag is off, never silently
        // accepting it or 500-ing.
        if ($mode === OrderMode::ESCROW && !$escrowEnabled) {
            throw ApiException::escrowDisabled();
        }

        if ($mode === OrderMode::ESCROW) {
            // Escrow order creation (buyer/seller pubkey wiring, T1/T2
            // freezing via core's TimeoutPolicy) is not yet implemented on
            // this path -- see doc-elektron/guideline-standalone-payment-server.md
            // section 25's own ordering ("direct mode first ... then escrow
            // mode"). Reaching here means the flag was turned on ahead of
            // that follow-up work landing.
            throw ApiException::notImplemented('Escrow order creation is not yet implemented.');
        }

        if (!isset($payload['amount']) || !is_numeric($payload['amount']) || (float) $payload['amount'] <= 0) {
            throw ApiException::validationError('amount must be a positive number.');
        }

        $currency = $payload['currency'] ?? $merchant->baseCurrency;
        if (!is_string($currency) || $currency === '') {
            throw ApiException::validationError('currency must be a non-empty string.');
        }
        // Section 12: base_currency (and so any order priced against it)
        // MUST be locked to ELEK whenever no price-feed implementation is
        // configured server-wide -- true for every deployment today, since
        // PriceFeedProviderInterface has no implementation yet.
        if ($currency !== 'ELEK') {
            throw ApiException::validationError(
                'Only the ELEK currency is currently supported; no price feed is configured yet.'
            );
        }

        if ($merchant->receivingXpub === null || $merchant->receivingXpub === '') {
            throw ApiException::validationError(
                'This merchant has not connected a receiving wallet yet.',
                'receiving_wallet_not_connected'
            );
        }

        if (isset($payload['external_reference']) && !is_string($payload['external_reference'])) {
            throw ApiException::validationError('external_reference must be a string.');
        }
    }
}
