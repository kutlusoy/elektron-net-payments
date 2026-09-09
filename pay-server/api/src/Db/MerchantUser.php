<?php

namespace ElektronNet\Payments\PayServer\Db;

/**
 * One `merchant_users` row (section 4/21): a human login distinct from a
 * merchant's API key -- "the API key authenticates server-to-server
 * calls, but a human sitting down at a dashboard needs a normal login".
 */
final class MerchantUser
{
    public string $id;
    public string $merchantId;
    public string $email;
    public ?string $passwordHash;
    public bool $isPlatformAdmin;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $user = new self();
        $user->id = (string) $row['id'];
        $user->merchantId = (string) $row['merchant_id'];
        $user->email = (string) $row['email'];
        $user->passwordHash = $row['password_hash'] !== null ? (string) $row['password_hash'] : null;
        $user->isPlatformAdmin = (bool) ($row['is_platform_admin'] ?? false);

        return $user;
    }
}
