<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Http\ApiException;

/**
 * Validation for `POST /v1/orders/{id}/refund-address` (section 5, section
 * 15): "format-validated only, exactly like any other address field in
 * this design, never treated as anything more sensitive" -- this
 * deliberately does not attempt real base58/bech32 checksum validation
 * (which would need bitwasp/bitcoin, like XpubValidation), only basic
 * hygiene, matching the guideline's own framing.
 */
final class OrderRefundValidation
{
    /** Matches orders.refund_address VARCHAR(100) (migrations/0001_init.sql). */
    private const MAX_ADDRESS_LENGTH = 100;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException on any validation failure
     */
    public static function validateAddress(array $payload): string
    {
        if (!isset($payload['refund_address']) || !is_string($payload['refund_address'])) {
            throw ApiException::validationError('refund_address must be a non-empty string.');
        }
        $address = trim($payload['refund_address']);
        if ($address === '') {
            throw ApiException::validationError('refund_address must be a non-empty string.');
        }
        if (preg_match('/\s/', $address)) {
            throw ApiException::validationError('refund_address must not contain whitespace.');
        }
        if (mb_strlen($address) > self::MAX_ADDRESS_LENGTH) {
            throw ApiException::validationError('refund_address must be at most ' . self::MAX_ADDRESS_LENGTH . ' characters.');
        }

        return $address;
    }
}
