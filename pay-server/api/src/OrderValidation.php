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
    public static function validateCreateOrderPayload(
        array $payload,
        Merchant $merchant,
        bool $escrowEnabled,
        bool $priceFeedConfigured = false
    ): void {
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
        // Section 12: a non-ELEK currency (converted live via the feed and
        // frozen onto the order, see OrderCreationService) MUST be locked
        // out whenever no price-feed implementation is configured
        // server-wide, and even then only for a currency this specific
        // merchant has actually opted into (merchants.enabled_fiat_currencies,
        // set at /admin/settings) -- a merchant with no price feed, or one
        // who never enabled any currency, still gets exactly the old
        // ELEK-only behavior.
        if ($currency !== 'ELEK') {
            if (!$priceFeedConfigured) {
                throw ApiException::validationError(
                    'Only the ELEK currency is currently supported; no price feed is configured yet.',
                    'price_feed_not_configured'
                );
            }
            if (!in_array($currency, $merchant->enabledFiatCurrencies, true)) {
                throw ApiException::validationError(
                    "This merchant has not enabled {$currency} for fiat-priced orders.",
                    'currency_not_enabled'
                );
            }
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
