<?php

namespace ElektronNet\Payments\PayServer\Http;

use Exception;

/**
 * Every error this API returns is one of these: an HTTP status plus a
 * stable, typed `error` code the response body carries (section 20:
 * `mode: "escrow"` while disabled MUST come back as a clear, typed error,
 * e.g. `escrow_disabled`, not a 500 or a silently-accepted request -- the
 * same discipline is applied to every other error this layer can raise).
 */
final class ApiException extends Exception
{
    private int $statusCode;
    private string $errorCode;

    public function __construct(int $statusCode, string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
    }

    public static function validationError(string $message, string $errorCode = 'validation_error'): self
    {
        return new self(422, $errorCode, $message);
    }

    public static function unauthorized(string $message = 'Missing or invalid API key.'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbiddenScope(string $requiredScope): self
    {
        return new self(403, 'forbidden', "API key is missing the required scope '{$requiredScope}'.");
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function escrowDisabled(): self
    {
        return new self(422, 'escrow_disabled', 'Escrow mode is disabled on this server.');
    }

    public static function notImplemented(string $message): self
    {
        return new self(501, 'not_implemented', $message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
