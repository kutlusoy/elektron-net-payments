<?php

namespace ElektronNet\Payments\Core\Psbt;

/**
 * Pure data holder for the release PSBT as it travels between the buyer's
 * and the seller's wallet. The plugin never inspects, signs, or broadcasts
 * it; it only stores the base64 blob it was handed and hands it to whoever
 * asks next. See the README, "Trustless by design".
 */
final class PsbtRelay
{
    /** @var string base64-encoded PSBT */
    private $base64;

    /** @var int how many of the two required signatures this blob carries */
    private $signatureCount;

    public function __construct(string $base64, int $signatureCount)
    {
        $this->base64 = $base64;
        $this->signatureCount = $signatureCount;
    }

    public function base64(): string
    {
        return $this->base64;
    }

    public function signatureCount(): int
    {
        return $this->signatureCount;
    }

    public function isFullySigned(): bool
    {
        return $this->signatureCount >= 2;
    }
}
