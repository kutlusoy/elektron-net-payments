<?php

namespace ElektronNet\Payments\PayServer\Db;

/**
 * Read-only view of one `merchants` row (section 4). Not every column is
 * carried here yet -- only what the order-creation path (section 5/8/9)
 * currently needs; branding/text-override/settings endpoints can extend
 * this as they get implemented.
 */
final class Merchant
{
    public string $id;
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
        $merchant->baseCurrency = (string) $row['base_currency'];
        $merchant->receivingXpub = $row['receiving_xpub'] !== null ? (string) $row['receiving_xpub'] : null;
        $merchant->orderExpiryMinutes = (int) $row['order_expiry_minutes'];
        $merchant->defaultRequiredConfirmations = (int) $row['default_required_confirmations'];
        $merchant->underpaymentTolerancePercent = (string) $row['underpayment_tolerance_percent'];

        return $merchant;
    }
}
