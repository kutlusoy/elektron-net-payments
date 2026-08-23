<?php

namespace ElektronNet\Payments\Core\Psbt;

use BitWasp\Bitcoin\Network\Network;
use ElektronNet\Payments\Core\Escrow\EscrowAddress;

/**
 * Builds the unsigned release PSBT (escrow UTXO -> seller's payout address)
 * once the buyer has confirmed receipt. See Bip174PsbtBuilder for the
 * reference (and, as of this interface's current shape, only) implementation
 * -- built on hand-rolled BIP174 encoding since neither bitwasp/bitcoin nor
 * any other PHP library implements PSBT at all (confirmed by exhaustive
 * source search, not assumed).
 *
 * The plugin only ever handles the resulting base64 blob (via PsbtRelay); it
 * never signs, inspects for validity beyond structural checks, or
 * broadcasts it -- see the README, "Trustless by design".
 */
interface ReleasePsbtBuilderInterface
{
    /**
     * @param string $fundingTxid txid of the confirmed payment into the escrow address
     * @param int $fundingVout output index paying the escrow address
     * @param int $fundingAmountLep amount held by the escrow UTXO, in lep
     * @param PsbtKeyOrigin $buyerKey buyer's half of the 2-of-2 script
     * @param PsbtKeyOrigin $sellerKey seller's half of the 2-of-2 script
     * @param string $sellerPayoutAddress payout address chosen by the seller's wallet
     * @param PsbtKeyOrigin $sellerPayoutKey the same address's own key origin,
     *     so the seller's wallet can recognize the output as its own
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
        PsbtKeyOrigin $buyerKey,
        PsbtKeyOrigin $sellerKey,
        string $sellerPayoutAddress,
        PsbtKeyOrigin $sellerPayoutKey,
        int $feeRateLepPerVByte,
        Network $network
    ): string;
}
