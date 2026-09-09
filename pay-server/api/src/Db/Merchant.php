<?php

namespace ElektronNet\Payments\PayServer\Db;

/**
 * Read-only view of one `merchants` row (section 4). Not every column is
 * carried here yet -- text_overrides/chain_endpoints/success_url/
 * cancel_url/custom_domain and the settings-management endpoints they
 * belong to can extend this as they get implemented.
 */
final class Merchant
{
    public string $id;
    public string $name;
    public string $displayName;
    public ?string $logoUrl;
    public ?string $themeColor;
    public string $baseCurrency;
    public ?string $receivingXpub;
    public int $orderExpiryMinutes;
    public int $defaultRequiredConfirmations;
    public string $underpaymentTolerancePercent;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $merchant = new self();
        $merchant->id = (string) $row['id'];
        $merchant->name = (string) $row['name'];
        // Section 17: display_name falls back to name if unset.
        $merchant->displayName = $row['display_name'] !== null && $row['display_name'] !== ''
            ? (string) $row['display_name']
            : (string) $row['name'];
        $merchant->logoUrl = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
        $merchant->themeColor = $row['theme_color'] !== null ? (string) $row['theme_color'] : null;
        $merchant->baseCurrency = (string) $row['base_currency'];
        $merchant->receivingXpub = $row['receiving_xpub'] !== null ? (string) $row['receiving_xpub'] : null;
        $merchant->orderExpiryMinutes = (int) $row['order_expiry_minutes'];
        $merchant->defaultRequiredConfirmations = (int) $row['default_required_confirmations'];
        $merchant->underpaymentTolerancePercent = (string) $row['underpayment_tolerance_percent'];

        return $merchant;
    }
}
