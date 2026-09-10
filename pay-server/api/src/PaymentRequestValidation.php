<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;

/**
 * Validation for `POST /v1/payment-requests` (section 5, section 16).
 *
 * ELEK-only for this pass, deliberately: a fixed-amount request would
 * otherwise need its own frozen exchange rate distinct from
 * `orders.exchange_rate_used` (which freezes once per spawned order, not
 * once per reusable request) and `payment_requests` has no column for
 * that. Fiat-denominated payment requests are a natural follow-up once
 * that is actually needed, mirroring the terminal's currency picker
 * (OrderValidation/OrderCreationService) rather than duplicating it here
 * ahead of time.
 */
final class PaymentRequestValidation
{
    private const MAX_DESCRIPTION_LENGTH = 500;

    /**
     * Section 16 checklist: "Open-amount requests validate buyer-entered
     * amounts against sane bounds (not zero, not unreasonably large)" --
     * reused for the buyer-entered amount at pay time too (see
     * Http\Controllers\PaymentRequestController::pay()).
     */
    public const MAX_ELEK_AMOUNT = 1_000_000;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{amount_lep: ?int, currency: string, description: ?string, expires_at: ?string}
     * @throws ApiException on any validation failure
     */
    public static function validate(array $payload): array
    {
        $amountLep = null;
        if (isset($payload['amount']) && $payload['amount'] !== '' && $payload['amount'] !== null) {
            if (!is_numeric($payload['amount']) || (float) $payload['amount'] <= 0 || (float) $payload['amount'] > self::MAX_ELEK_AMOUNT) {
                throw ApiException::validationError(
                    'amount must be a positive number (or omitted entirely for an open-amount request).'
                );
            }
            $amountLep = (int) round(((float) $payload['amount']) * OrderRepository::LEP_PER_ELEK);
        }

        $description = null;
        if (isset($payload['description']) && $payload['description'] !== '') {
            if (!is_string($payload['description'])) {
                throw ApiException::validationError('description must be a string.');
            }
            $description = trim($payload['description']);
            if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
                throw ApiException::validationError('description must be at most ' . self::MAX_DESCRIPTION_LENGTH . ' characters.');
            }
        }

        $expiresAt = null;
        if (isset($payload['expires_at']) && $payload['expires_at'] !== '') {
            if (!is_string($payload['expires_at'])) {
                throw ApiException::validationError('expires_at must be a string.');
            }
            $timestamp = strtotime($payload['expires_at']);
            if ($timestamp === false) {
                throw ApiException::validationError('expires_at must be a valid date/time.');
            }
            if ($timestamp <= time()) {
                throw ApiException::validationError('expires_at must be in the future.');
            }
            $expiresAt = (new \DateTimeImmutable('@' . $timestamp))->format(DATE_ATOM);
        }

        return [
            'amount_lep' => $amountLep,
            'currency' => 'ELEK',
            'description' => $description,
            'expires_at' => $expiresAt,
        ];
    }
}
