<?php

namespace ElektronNet\Payments\PayServer\Db;

/**
 * Read-only view of one `merchants` row (section 4). Not every column is
 * carried here yet -- text_overrides/custom_domain and the
 * settings-management endpoints they belong to can extend this as they
 * get implemented.
 */
final class Merchant
{
    public string $id;
    public string $name;
    public string $displayName;
    /** Raw display_name column value, unlike displayName -- null means "not set", for edit forms. */
    public ?string $displayNameRaw;
    public ?string $logoUrl;
    public ?string $themeColor;
    public ?string $checkoutSubdomain;
    public ?string $successUrl;
    public ?string $cancelUrl;
    public string $baseCurrency;
    public ?string $defaultDisplayCurrency;
    public ?string $receivingXpub;
    public int $orderExpiryMinutes;
    public int $defaultRequiredConfirmations;
    public string $underpaymentTolerancePercent;
    /** @var string[] ISO currency codes accepted for live-converted order creation (section 12), e.g. at /admin/terminal */
    public array $enabledFiatCurrencies;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $merchant = new self();
        $merchant->id = (string) $row['id'];
        $merchant->name = (string) $row['name'];
        $merchant->displayNameRaw = $row['display_name'] !== null && $row['display_name'] !== ''
            ? (string) $row['display_name']
            : null;
        // Section 17: display_name falls back to name if unset.
        $merchant->displayName = $merchant->displayNameRaw ?? (string) $row['name'];
        $merchant->logoUrl = $row['logo_url'] !== null ? (string) $row['logo_url'] : null;
        $merchant->themeColor = $row['theme_color'] !== null ? (string) $row['theme_color'] : null;
        $merchant->checkoutSubdomain = ($row['checkout_subdomain'] ?? null) !== null
            ? (string) $row['checkout_subdomain']
            : null;
        $merchant->successUrl = ($row['success_url'] ?? null) !== null ? (string) $row['success_url'] : null;
        $merchant->cancelUrl = ($row['cancel_url'] ?? null) !== null ? (string) $row['cancel_url'] : null;
        $merchant->baseCurrency = (string) $row['base_currency'];
        $merchant->defaultDisplayCurrency = ($row['default_display_currency'] ?? null) !== null
            ? (string) $row['default_display_currency']
            : null;
        $merchant->receivingXpub = $row['receiving_xpub'] !== null ? (string) $row['receiving_xpub'] : null;
        $merchant->orderExpiryMinutes = (int) $row['order_expiry_minutes'];
        $merchant->defaultRequiredConfirmations = (int) $row['default_required_confirmations'];
        $merchant->underpaymentTolerancePercent = (string) $row['underpayment_tolerance_percent'];
        $decodedFiatCurrencies = isset($row['enabled_fiat_currencies']) ? json_decode((string) $row['enabled_fiat_currencies'], true) : [];
        $merchant->enabledFiatCurrencies = is_array($decodedFiatCurrencies) ? array_values($decodedFiatCurrencies) : [];

        return $merchant;
    }
}
