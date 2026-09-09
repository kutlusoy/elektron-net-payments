<?php

namespace ElektronNet\Payments\PayServer\Auth;

use ElektronNet\Payments\PayServer\Http\ApiException;
use PDO;

/**
 * Authenticates `Authorization: Bearer <api key>` (section 5) and enforces
 * its fixed scope list (section 14). This is the "single shared middleware
 * check" section 5's checklist asks for -- every endpoint that needs a
 * scope calls Http\Controllers\OrdersController's requireScope() helper,
 * which in turn is built on this class, rather than re-implementing the
 * lookup per route.
 *
 * Keys are stored as a SHA-256 hash only (section 14, section 24), never
 * plaintext -- the same treatment merchants.webhook_secret_hash gets.
 */
final class ApiKeyAuthenticator
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @throws ApiException 401 if the key is missing, unknown, or revoked
     */
    public function authenticate(?string $rawKey): AuthenticatedKey
    {
        if ($rawKey === null || $rawKey === '') {
            throw ApiException::unauthorized();
        }

        $hash = hash('sha256', $rawKey);

        $stmt = $this->pdo->prepare(
            'SELECT merchant_id, scopes FROM merchant_api_keys WHERE key_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw ApiException::unauthorized();
        }

        $update = $this->pdo->prepare('UPDATE merchant_api_keys SET last_used_at = now() WHERE key_hash = :hash');
        $update->execute(['hash' => $hash]);

        $scopes = json_decode((string) $row['scopes'], true);

        return new AuthenticatedKey((string) $row['merchant_id'], is_array($scopes) ? $scopes : []);
    }

    /**
     * @throws ApiException 403 if the key does not carry $scope
     */
    public function requireScope(AuthenticatedKey $key, string $scope): void
    {
        if (!$key->hasScope($scope)) {
            throw ApiException::forbiddenScope($scope);
        }
    }
}
