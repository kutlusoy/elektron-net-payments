<?php

namespace ElektronNet\Payments\Core\Escrow;

/**
 * Immutable result of generating a fresh escrow address for one order.
 * A platform adapter persists these fields on its own order record; the
 * redeem script and the two locktimes MUST be stored as given here and
 * never recomputed later, since they are frozen into the address forever
 * (see the README, "Values are frozen per order").
 */
final class EscrowAddress
{
    /** @var string bech32 address, e.g. be1q... */
    private $address;

    /** @var string hex-encoded witness/redeem script */
    private $redeemScriptHex;

    /** @var int unix timestamp; buyer-refund path becomes valid at/after this */
    private $buyerRefundLocktime;

    /** @var int unix timestamp; seller-release path becomes valid at/after this */
    private $sellerReleaseLocktime;

    public function __construct(
        string $address,
        string $redeemScriptHex,
        int $buyerRefundLocktime,
        int $sellerReleaseLocktime
    ) {
        $this->address = $address;
        $this->redeemScriptHex = $redeemScriptHex;
        $this->buyerRefundLocktime = $buyerRefundLocktime;
        $this->sellerReleaseLocktime = $sellerReleaseLocktime;
    }

    public function address(): string
    {
        return $this->address;
    }

    public function redeemScriptHex(): string
    {
        return $this->redeemScriptHex;
    }

    public function buyerRefundLocktime(): int
    {
        return $this->buyerRefundLocktime;
    }

    public function sellerReleaseLocktime(): int
    {
        return $this->sellerReleaseLocktime;
    }
}
