<?php

namespace ElektronNet\Payments\Core\Escrow;

use InvalidArgumentException;

/**
 * The suite-wide escrow timeout policy (see the repository README, section
 * "Escrow timeout policy"). Every platform adapter MUST build its admin
 * settings form on top of this class instead of accepting raw integers, so
 * the safety invariants below are enforced identically everywhere.
 *
 * - T1 (buyerRefundDays): from this many days after funding, the buyer alone
 *   can reclaim the escrow, without the seller's cooperation.
 * - T2 (sellerReleaseDays): from this many days after funding, the seller
 *   alone can claim the escrow, without the buyer's cooperation.
 *
 * These are marketplace-wide admin settings, never a per-order or per-user
 * choice; see the README for why that matters.
 */
final class TimeoutPolicy
{
    public const DEFAULT_BUYER_REFUND_DAYS = 30;
    public const DEFAULT_SELLER_RELEASE_DAYS = 60;

    /** T2 must be at least this many times T1. */
    public const MIN_RATIO = 1.5;

    public const MIN_BUYER_REFUND_DAYS = 3;
    public const MAX_SELLER_RELEASE_DAYS = 183; // roughly half a year

    /** @var int */
    private $buyerRefundDays;

    /** @var int */
    private $sellerReleaseDays;

    private function __construct(int $buyerRefundDays, int $sellerReleaseDays)
    {
        $this->buyerRefundDays = $buyerRefundDays;
        $this->sellerReleaseDays = $sellerReleaseDays;
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULT_BUYER_REFUND_DAYS, self::DEFAULT_SELLER_RELEASE_DAYS);
    }

    /**
     * @throws InvalidArgumentException if the pair violates a safety guardrail.
     */
    public static function fromDays(int $buyerRefundDays, int $sellerReleaseDays): self
    {
        if ($buyerRefundDays < self::MIN_BUYER_REFUND_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'Buyer refund threshold must be at least %d days.',
                self::MIN_BUYER_REFUND_DAYS
            ));
        }
        if ($sellerReleaseDays > self::MAX_SELLER_RELEASE_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'Seller release threshold must not exceed %d days.',
                self::MAX_SELLER_RELEASE_DAYS
            ));
        }
        if ($sellerReleaseDays < $buyerRefundDays * self::MIN_RATIO) {
            throw new InvalidArgumentException(sprintf(
                'Seller release threshold must be at least %.1fx the buyer refund threshold (got %d and %d days).',
                self::MIN_RATIO,
                $buyerRefundDays,
                $sellerReleaseDays
            ));
        }

        return new self($buyerRefundDays, $sellerReleaseDays);
    }

    public function buyerRefundDays(): int
    {
        return $this->buyerRefundDays;
    }

    public function sellerReleaseDays(): int
    {
        return $this->sellerReleaseDays;
    }

    /**
     * Absolute buyer-refund locktime (Unix timestamp), computed from the
     * moment the escrow address was funded. Timestamp-based, not a block
     * height, so a remaining-time countdown can be shown exactly.
     */
    public function buyerRefundLocktime(int $fundedAtUnixTime): int
    {
        return $fundedAtUnixTime + $this->buyerRefundDays * 86400;
    }

    public function sellerReleaseLocktime(int $fundedAtUnixTime): int
    {
        return $fundedAtUnixTime + $this->sellerReleaseDays * 86400;
    }
}
