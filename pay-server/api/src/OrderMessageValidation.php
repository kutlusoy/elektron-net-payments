<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Http\ApiException;

/**
 * Validation for `POST /v1/orders/{id}/messages` (section 5, section 11).
 */
final class OrderMessageValidation
{
    private const MAX_BODY_LENGTH = 2000;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException on any validation failure
     */
    public static function validateBody(array $payload): string
    {
        if (!isset($payload['body']) || !is_string($payload['body'])) {
            throw ApiException::validationError('body must be a non-empty string.');
        }
        $body = trim($payload['body']);
        if ($body === '') {
            throw ApiException::validationError('body must be a non-empty string.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ApiException::validationError('body must be at most ' . self::MAX_BODY_LENGTH . ' characters.');
        }

        return $body;
    }
}
