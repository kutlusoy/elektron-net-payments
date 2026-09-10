<?php

namespace ElektronNet\Payments\PayServer\Db;

/**
 * Section 16: "a template, not a payable object itself" - carries an
 * optional fixed amount (null = buyer enters their own) and an optional
 * expiry, never an address of its own. Every actual payment spawns a
 * fresh `orders` row linked back via `orders.payment_request_id`.
 */
final class PaymentRequest
{
    public string $id;
    public string $merchantId;
    public ?int $amountLep;
    public string $currency;
    public ?string $description;
    public ?string $expiresAt;
    public ?string $archivedAt;
    public string $createdAt;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $request = new self();
        $request->id = (string) $row['id'];
        $request->merchantId = (string) $row['merchant_id'];
        $request->amountLep = $row['amount_lep'] !== null ? (int) $row['amount_lep'] : null;
        $request->currency = (string) $row['currency'];
        $request->description = $row['description'] !== null ? (string) $row['description'] : null;
        $request->expiresAt = $row['expires_at'] !== null ? (string) $row['expires_at'] : null;
        $request->archivedAt = $row['archived_at'] !== null ? (string) $row['archived_at'] : null;
        $request->createdAt = (string) $row['created_at'];

        return $request;
    }

    public function isOpenAmount(): bool
    {
        return $this->amountLep === null;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && strtotime($this->expiresAt) < time();
    }

    /**
     * Section 16 checklist: "never itself payable" -- true whenever this
     * request should stop offering the "pay" flow (archived, expired), so
     * callers have one place to check instead of reimplementing the
     * condition.
     */
    public function isPayable(): bool
    {
        return !$this->isArchived() && !$this->isExpired();
    }
}
