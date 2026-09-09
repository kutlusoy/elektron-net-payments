<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Http\ApiException;

/**
 * Validation for `PUT /v1/merchants/{id}/branding` (section 5, section 17).
 */
final class BrandingValidation
{
    /**
     * WCAG 2.1 SC 1.4.11 (Non-text Contrast) minimum for UI components
     * (buttons, borders) against their background -- 3:1. Section 17:
     * "enforce a minimum contrast ratio server-side rather than trusting
     * whatever hex a merchant enters." The checkout page only ever uses
     * the theme color for accents on a light (near-white) background
     * (checkout/assets/style.css's --bg), so the check is against white.
     */
    private const MIN_CONTRAST_RATIO = 3.0;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{display_name: ?string, logo_url: ?string, theme_color: ?string, checkout_subdomain: ?string}
     * @throws ApiException on any validation failure
     */
    public static function validate(array $payload): array
    {
        $displayName = self::normalizeString($payload['display_name'] ?? null, 255, 'display_name');
        $logoUrl = self::normalizeString($payload['logo_url'] ?? null, 2048, 'logo_url');
        if ($logoUrl !== null && filter_var($logoUrl, FILTER_VALIDATE_URL) === false) {
            throw ApiException::validationError('logo_url must be a valid URL.');
        }

        $themeColor = self::normalizeString($payload['theme_color'] ?? null, 7, 'theme_color');
        if ($themeColor !== null) {
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
                throw ApiException::validationError('theme_color must be a 6-digit hex color, e.g. #1a1a1a.');
            }
            if (self::contrastRatioAgainstWhite($themeColor) < self::MIN_CONTRAST_RATIO) {
                throw ApiException::validationError(
                    'theme_color is too close to white to stay legible as a checkout-page accent color; pick a darker shade.'
                );
            }
        }

        $checkoutSubdomain = self::normalizeString($payload['checkout_subdomain'] ?? null, 63, 'checkout_subdomain');
        if ($checkoutSubdomain !== null && !preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $checkoutSubdomain)) {
            throw ApiException::validationError(
                'checkout_subdomain must be lowercase letters, digits, and hyphens, and cannot start or end with a hyphen.'
            );
        }

        return [
            'display_name' => $displayName,
            'logo_url' => $logoUrl,
            'theme_color' => $themeColor,
            'checkout_subdomain' => $checkoutSubdomain,
        ];
    }

    private static function normalizeString($value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::validationError("{$field} must be a string.");
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > $maxLength) {
            throw ApiException::validationError("{$field} must be at most {$maxLength} characters.");
        }

        return $trimmed;
    }

    private static function contrastRatioAgainstWhite(string $hexColor): float
    {
        [$r, $g, $b] = sscanf($hexColor, '#%02x%02x%02x');
        $luminance = self::relativeLuminance($r) * 0.2126
            + self::relativeLuminance($g) * 0.7152
            + self::relativeLuminance($b) * 0.0722;

        // White's own relative luminance is 1.0; WCAG's contrast formula
        // is (L_lighter + 0.05) / (L_darker + 0.05).
        return (1.0 + 0.05) / ($luminance + 0.05);
    }

    private static function relativeLuminance(int $channel8Bit): float
    {
        $c = $channel8Bit / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
}
