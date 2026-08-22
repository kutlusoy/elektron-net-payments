<?php

namespace ElektronNet\Payments\Core\Psbt;

use ElektronNet\Payments\Core\Escrow\EscrowAddress;

/**
 * Builds the unsigned release PSBT (escrow UTXO -> seller address) once the
 * buyer has confirmed receipt. Like EscrowScriptBuilderInterface, a concrete
 * implementation should wrap bitwasp/bitcoin's PSBT support rather than
 * assembling PSBT bytes by hand; no reference implementation ships yet
 * (tracked as a TODO, see the osclass-escrow README's Open Questions).
 *
 * The plugin only ever handles the resulting base64 blob (via PsbtRelay); it
 * never signs it.
 */
interface ReleasePsbtBuilderInterface
{
    /**
     * @param string $fundingTxid txid of the confirmed payment into the escrow address
     * @param int $fundingVout output index paying the escrow address
     * @param int $fundingAmountLep amount held by the escrow UTXO, in lep
     * @param string $sellerAddress payout address chosen by the seller's wallet
     * @param int $feeRateLepPerVByte the rate to use, in lep/vByte -- the
     *     caller SHOULD produce this via ChainData\FeeRateResolver::resolve()
     *     (live mempool estimate, falling back to
     *     PaymentsConfig::feeRateLepPerVByte() only when no estimate is
     *     available), not by passing PaymentsConfig's fixed value directly
     * @return string base64-encoded, unsigned PSBT
     */
    public function buildReleasePsbt(
        EscrowAddress $escrowAddress,
        string $fundingTxid,
        int $fundingVout,
        int $fundingAmountLep,
        string $sellerAddress,
        int $feeRateLepPerVByte
    ): string;
}
