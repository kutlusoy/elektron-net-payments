<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Http\ApiException;

/**
 * Validation for `PUT /v1/merchants/{id}/settings` (section 5, sections
 * 12/13/17).
 */
final class SettingsValidation
{
    private const MAX_ORDER_EXPIRY_MINUTES = 10080; // one week; a sane upper bound, not named explicitly in the guideline
    private const MAX_REQUIRED_CONFIRMATIONS = 100;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @param bool $priceFeedConfigured whether a PriceFeedProviderInterface is bound server-wide
     * @return array{
     *     order_expiry_minutes: int,
     *     default_required_confirmations: int,
     *     underpayment_tolerance_percent: float,
     *     default_display_currency: ?string,
     *     success_url: ?string,
     *     cancel_url: ?string
     * }
     * @throws ApiException on any validation failure
     */
    public static function validate(array $payload, bool $priceFeedConfigured): array
    {
        $orderExpiryMinutes = self::requirePositiveInt(
            $payload['order_expiry_minutes'] ?? null,
            'order_expiry_minutes',
            self::MAX_ORDER_EXPIRY_MINUTES
        );

        if (!isset($payload['default_required_confirmations']) || !is_numeric($payload['default_required_confirmations'])) {
            throw ApiException::validationError('default_required_confirmations must be a number.');
        }
        $requiredConfirmations = (int) $payload['default_required_confirmations'];
        if ($requiredConfirmations < 0 || $requiredConfirmations > self::MAX_REQUIRED_CONFIRMATIONS) {
            throw ApiException::validationError(
                'default_required_confirmations must be between 0 and ' . self::MAX_REQUIRED_CONFIRMATIONS . '.'
            );
        }

        if (!isset($payload['underpayment_tolerance_percent']) || !is_numeric($payload['underpayment_tolerance_percent'])) {
            throw ApiException::validationError('underpayment_tolerance_percent must be a number.');
        }
        $tolerance = (float) $payload['underpayment_tolerance_percent'];
        if ($tolerance < 0 || $tolerance > 100) {
            throw ApiException::validationError('underpayment_tolerance_percent must be between 0 and 100.');
        }

        $displayCurrency = null;
        if (!empty($payload['default_display_currency'])) {
            if (!$priceFeedConfigured) {
                throw ApiException::validationError(
                    'default_display_currency cannot be set: no price feed is configured on this server yet (section 12).',
                    'price_feed_not_configured'
                );
            }
            $displayCurrency = strtoupper(trim((string) $payload['default_display_currency']));
            if (!preg_match('/^[A-Z]{3}$/', $displayCurrency)) {
                throw ApiException::validationError('default_display_currency must be a 3-letter ISO currency code.');
            }
        }

        return [
            'order_expiry_minutes' => $orderExpiryMinutes,
            'default_required_confirmations' => $requiredConfirmations,
            'underpayment_tolerance_percent' => $tolerance,
            'default_display_currency' => $displayCurrency,
            'success_url' => self::requireHttpsOrNull($payload['success_url'] ?? null, 'success_url'),
            'cancel_url' => self::requireHttpsOrNull($payload['cancel_url'] ?? null, 'cancel_url'),
        ];
    }

    private static function requirePositiveInt($value, string $field, int $max): int
    {
        if (!is_numeric($value)) {
            throw ApiException::validationError("{$field} must be a number.");
        }
        $intValue = (int) $value;
        if ($intValue < 1 || $intValue > $max) {
            throw ApiException::validationError("{$field} must be between 1 and {$max}.");
        }

        return $intValue;
    }

    /**
     * Section 17 checklist: "Redirect URLs (success_url/cancel_url)
     * restricted to https:// targets to avoid an open redirect via a
     * merchant-controlled field." Restricting the scheme narrows but does
     * not eliminate open-redirect risk (an https:// URL can still point
     * anywhere); this implements exactly the guideline's own checklist
     * item, not a claim of complete open-redirect protection.
     */
    private static function requireHttpsOrNull($value, string $field): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::validationError("{$field} must be a string.");
        }
        $trimmed = trim($value);
        if (mb_strlen($trimmed) > 2048) {
            throw ApiException::validationError("{$field} must be at most 2048 characters.");
        }
        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if ($scheme !== 'https' || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw ApiException::validationError("{$field} must be an https:// URL.");
        }

        return $trimmed;
    }
}
