<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;

/**
 * A small server-wide key/value store (`platform_settings`), for settings
 * that belong to the whole deployment rather than to any one merchant --
 * the price feed (section 12) being the first of these. Kept deliberately
 * generic (key + JSONB value) rather than one dedicated column per
 * setting, since this is expected to grow.
 */
final class PlatformSettingsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed>|null null if no row exists for $key
     */
    public function get(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT value FROM platform_settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $decoded = json_decode((string) $row['value'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO platform_settings (key, value, updated_at) VALUES (:key, :value, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()'
        );
        $stmt->execute([
            'key' => $key,
            'value' => json_encode($value),
        ]);
    }
}
