<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Http\ApiException;

/**
 * Validation for the platform-admin-only price feed settings form
 * (section 12): `enabled` is the master toggle asked for so an operator
 * can switch the feed off again -- without losing the configured
 * endpoint(s) -- until ELEK actually has a real listing somewhere,
 * exactly like section 20 already does for the escrow flag.
 */
final class PlatformPriceFeedValidation
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{enabled: bool, endpoints: array<int, array<string, mixed>>}
     * @throws ApiException on any validation failure
     */
    public static function validate(array $payload): array
    {
        $enabled = !empty($payload['enabled']);

        $advancedJson = trim((string) ($payload['advanced_json'] ?? ''));
        if ($advancedJson !== '') {
            $decoded = json_decode($advancedJson, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw ApiException::validationError('Advanced endpoint list must be valid JSON (an array of endpoint objects).');
            }

            return ['enabled' => $enabled, 'endpoints' => array_values($decoded)];
        }

        $baseUrl = trim((string) ($payload['base_url'] ?? ''));
        $coinId = trim((string) ($payload['coin_id'] ?? ''));

        if ($baseUrl === '' && $coinId === '') {
            return ['enabled' => $enabled, 'endpoints' => []];
        }

        if ($baseUrl === '' || $coinId === '') {
            throw ApiException::validationError('Base URL and coin id are both required to configure a price feed.');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw ApiException::validationError('Base URL must be a valid URL.');
        }

        $apiKeyHeader = trim((string) ($payload['api_key_header'] ?? ''));
        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if (($apiKeyHeader === '') !== ($apiKey === '')) {
            throw ApiException::validationError('API key header and API key must both be set, or both left blank.');
        }

        $endpoint = [
            'type' => 'simple_price',
            'base_url' => $baseUrl,
            'coin_id' => $coinId,
            'price_path' => self::orDefault($payload['price_path'] ?? null, '/simple/price'),
            'ids_param' => self::orDefault($payload['ids_param'] ?? null, 'ids'),
            'vs_currencies_param' => self::orDefault($payload['vs_currencies_param'] ?? null, 'vs_currencies'),
        ];
        if ($apiKeyHeader !== '') {
            $endpoint['api_key_header'] = $apiKeyHeader;
            $endpoint['api_key'] = $apiKey;
        }

        return ['enabled' => $enabled, 'endpoints' => [$endpoint]];
    }

    private static function orDefault($value, string $default): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $default;
    }
}
