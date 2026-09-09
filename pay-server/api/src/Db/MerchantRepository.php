<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;
use RuntimeException;

final class MerchantRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function find(string $merchantId): ?Merchant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM merchants WHERE id = :id');
        $stmt->execute(['id' => $merchantId]);
        $row = $stmt->fetch();

        return $row === false ? null : Merchant::fromRow($row);
    }

    /**
     * Claims the next child-derivation index for this merchant's
     * receiving_xpub and returns it, incrementing the stored counter in
     * the same transaction so the index is never handed out twice --
     * section 9 checklist: "strictly monotonic child-derivation index per
     * xpub, never reused even for a cancelled or expired order". Caller
     * MUST already be inside a transaction; this issues a row lock
     * (SELECT ... FOR UPDATE) to serialize concurrent order creations for
     * the same merchant.
     */
    public function claimNextReceivingIndex(string $merchantId): int
    {
        $stmt = $this->pdo->prepare('SELECT next_receiving_index FROM merchants WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $merchantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Merchant {$merchantId} not found while claiming a receiving index.");
        }

        $index = (int) $row['next_receiving_index'];

        $update = $this->pdo->prepare('UPDATE merchants SET next_receiving_index = :next WHERE id = :id');
        $update->execute(['next' => $index + 1, 'id' => $merchantId]);

        return $index;
    }

    /**
     * Section 17 / `PUT /v1/merchants/{id}/branding` (section 5).
     *
     * @param array{display_name: ?string, logo_url: ?string, theme_color: ?string, checkout_subdomain: ?string} $fields
     */
    public function updateBranding(string $merchantId, array $fields): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE merchants
             SET display_name = :display_name,
                 logo_url = :logo_url,
                 theme_color = :theme_color,
                 checkout_subdomain = :checkout_subdomain
             WHERE id = :id'
        );
        $stmt->execute([
            'display_name' => $fields['display_name'],
            'logo_url' => $fields['logo_url'],
            'theme_color' => $fields['theme_color'],
            'checkout_subdomain' => $fields['checkout_subdomain'],
            'id' => $merchantId,
        ]);
    }

    /**
     * Section 13/17 / `PUT /v1/merchants/{id}/settings` (section 5).
     *
     * @param array{
     *     order_expiry_minutes: int,
     *     default_required_confirmations: int,
     *     underpayment_tolerance_percent: float,
     *     default_display_currency: ?string,
     *     success_url: ?string,
     *     cancel_url: ?string,
     *     enabled_fiat_currencies: string[]
     * } $fields
     */
    public function updateSettings(string $merchantId, array $fields): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE merchants
             SET order_expiry_minutes = :order_expiry_minutes,
                 default_required_confirmations = :default_required_confirmations,
                 underpayment_tolerance_percent = :underpayment_tolerance_percent,
                 default_display_currency = :default_display_currency,
                 success_url = :success_url,
                 cancel_url = :cancel_url,
                 enabled_fiat_currencies = :enabled_fiat_currencies
             WHERE id = :id'
        );
        $stmt->execute([
            'order_expiry_minutes' => $fields['order_expiry_minutes'],
            'default_required_confirmations' => $fields['default_required_confirmations'],
            'underpayment_tolerance_percent' => $fields['underpayment_tolerance_percent'],
            'default_display_currency' => $fields['default_display_currency'],
            'success_url' => $fields['success_url'],
            'cancel_url' => $fields['cancel_url'],
            'enabled_fiat_currencies' => json_encode($fields['enabled_fiat_currencies'] ?? []),
            'id' => $merchantId,
        ]);
    }

    /**
     * Section 8: the merchant's own connected receiving wallet.
     * $normalizedXpub MUST already be validated and prefix-normalized via
     * XpubValidation::normalizeAndValidate() -- this method just persists
     * it.
     */
    public function updateReceivingXpub(string $merchantId, string $normalizedXpub): void
    {
        $stmt = $this->pdo->prepare('UPDATE merchants SET receiving_xpub = :xpub WHERE id = :id');
        $stmt->execute(['xpub' => $normalizedXpub, 'id' => $merchantId]);
    }
}
