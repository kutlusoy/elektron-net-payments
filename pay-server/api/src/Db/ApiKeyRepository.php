<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;

final class ApiKeyRepository
{
    private const VALID_SCOPES = [
        'orders:create',
        'orders:read',
        'orders:messages',
        'payment_requests:manage',
        'branding:write',
        'settings:write',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return string[]
     */
    public static function validScopes(): array
    {
        return self::VALID_SCOPES;
    }

    /**
     * @param string[] $scopes
     * @return array{id: string, rawKey: string} the raw key is returned
     *     exactly once (section 14) -- callers MUST show it to the
     *     merchant immediately and never persist it themselves
     */
    public function create(string $merchantId, string $label, array $scopes): array
    {
        $scopes = array_values(array_intersect($scopes, self::VALID_SCOPES));
        $rawKey = 'pk_live_' . bin2hex(random_bytes(24));
        $id = $this->generateUuid();

        $stmt = $this->pdo->prepare(
            'INSERT INTO merchant_api_keys (id, merchant_id, label, key_hash, key_suffix, scopes)
             VALUES (:id, :merchant_id, :label, :hash, :suffix, :scopes)'
        );
        $stmt->execute([
            'id' => $id,
            'merchant_id' => $merchantId,
            'label' => $label,
            'hash' => hash('sha256', $rawKey),
            'suffix' => substr($rawKey, -4),
            'scopes' => json_encode($scopes),
        ]);

        return ['id' => $id, 'rawKey' => $rawKey];
    }

    /**
     * @return ApiKey[]
     */
    public function listForMerchant(string $merchantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM merchant_api_keys WHERE merchant_id = :merchant_id ORDER BY created_at DESC'
        );
        $stmt->execute(['merchant_id' => $merchantId]);

        return array_map(fn (array $row) => ApiKey::fromRow($row), $stmt->fetchAll());
    }

    public function revoke(string $id, string $merchantId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE merchant_api_keys SET revoked_at = now() WHERE id = :id AND merchant_id = :merchant_id'
        );
        $stmt->execute(['id' => $id, 'merchant_id' => $merchantId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
