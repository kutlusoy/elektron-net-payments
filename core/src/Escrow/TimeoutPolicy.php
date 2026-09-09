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

    /**
     * Minimum time before T1 required to safely start a cooperative
     * release. "Confirm receipt" only ever changes this plugin's own
     * database status; it has no on-chain effect at all. The buyer's
     * unilateral refund path is spendable by the buyer alone from T1
     * onward regardless of what the database says, so if a release is
     * started this close to T1, an honest delay on the seller's side
     * (their signing device is offline, they are asleep, ...) is enough
     * for the buyer to end up able to reclaim funds the seller was told
     * were being released to them. Below this margin, starting a release
     * MUST be refused outright, not merely warned about.
     */
    public const MIN_CONFIRM_RECEIPT_SAFETY_MARGIN_DAYS = 1;

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

    /**
     * False once too little time remains before $buyerRefundLocktime (the
     * value already frozen on the order, see EscrowAddress::buyerRefundLocktime())
     * for the seller to realistically countersign and broadcast the
     * cooperative release before the buyer regains the unilateral ability
     * to reclaim the funds instead. A platform adapter's "confirm receipt"
     * action MUST check this and refuse outright when it is false; see
     * MIN_CONFIRM_RECEIPT_SAFETY_MARGIN_DAYS.
     *
     * Static and not tied to a particular TimeoutPolicy instance
     * deliberately: the order's own already-frozen locktime is what
     * matters here, not the marketplace's *current* admin setting, which
     * may have changed since this order was created.
     */
    public static function canSafelyStartRelease(int $buyerRefundLocktime, int $nowUnixTime): bool
    {
        return $nowUnixTime < $buyerRefundLocktime - self::MIN_CONFIRM_RECEIPT_SAFETY_MARGIN_DAYS * 86400;
    }
}
