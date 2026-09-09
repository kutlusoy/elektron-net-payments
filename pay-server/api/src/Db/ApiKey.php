<?php

namespace ElektronNet\Payments\PayServer\Db;

/**
 * One `merchant_api_keys` row (section 14) for the admin UI's key list.
 * Never carries the raw key -- only its hash is ever stored (section 14,
 * section 24), and the admin list only shows the last 4 characters kept
 * alongside the hash at creation time for the operator's own recognition.
 */
final class ApiKey
{
    public string $id;
    public string $label;
    /** @var string[] */
    public array $scopes;
    public string $createdAt;
    public ?string $lastUsedAt;
    public ?string $revokedAt;
    public string $keySuffix;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $key = new self();
        $key->id = (string) $row['id'];
        $key->label = (string) $row['label'];
        $scopes = json_decode((string) $row['scopes'], true);
        $key->scopes = is_array($scopes) ? $scopes : [];
        $key->createdAt = (string) $row['created_at'];
        $key->lastUsedAt = $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null;
        $key->revokedAt = $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null;
        $key->keySuffix = (string) $row['key_suffix'];

        return $key;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }
}
