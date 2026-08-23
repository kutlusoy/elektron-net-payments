<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\ChainData\FeeRateResolver;
use ElektronNet\Payments\Core\ChainData\FundingOutput;
use ElektronNet\Payments\Core\Escrow\EscrowAddress;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;
use ElektronNet\Payments\Core\Psbt\Bip174PsbtBuilder;
use ElektronNet\Payments\Core\Psbt\PsbtKeyOrigin;
use ElektronNet\Payments\Core\Psbt\PsbtRelay;

/**
 * Builds the release PSBT for an order that just entered
 * RELEASE_PENDING_SELLER_SIGNATURE, from data already on the order row plus
 * a live chain-data lookup for the exact funding outpoint (never stored on
 * the order row itself -- see FundingOutput's docblock for why the plain
 * "funded" check does not already have it).
 *
 * @param array $order EscrowOrderDAO row
 * @return PsbtRelay the unsigned PSBT, wrapped exactly as the shared core
 *     expects it to travel between platform storage and the two parties
 *     (see the shared README, "Wiring a new platform" and PsbtRelay's own
 *     docblock) -- store $relay->base64() and $relay->signatureCount() (0
 *     at this point) on the order row
 * @throws RuntimeException if either party's xpub is gone (should not
 *     happen: EscrowOrderDAO::hasActiveOrderForUser() blocks changing a
 *     connected xpub while an order still needs it), or if the escrow
 *     address's on-chain funding does not resolve to exactly one output
 *     covering the order amount (e.g. the buyer paid in more than one
 *     transaction -- see the README, "Open Questions", "no partial-payment
 *     handling"). Either case leaves the order in
 *     RELEASE_PENDING_SELLER_SIGNATURE with no PSBT yet; the order-status
 *     view falls back to showing the raw redeem script for the two parties
 *     to cooperate manually outside the platform in that rare case.
 */
function elektron_escrow_build_release_psbt(array $order): PsbtRelay
{
    $network = elektron_escrow_network();
    $config = elektron_escrow_config();

    $buyerXpub = elektron_escrow_get_user_xpub((int) $order['fk_i_buyer_id']);
    $sellerXpub = elektron_escrow_get_user_xpub((int) $order['fk_i_seller_id']);
    if ($buyerXpub === null || $sellerXpub === null) {
        throw new RuntimeException("elektron_escrow_build_release_psbt(): order {$order['pk_i_id']} is missing a connected xpub for one of its parties.");
    }

    $chainData = elektron_escrow_chain_data();
    $fundingOutputs = array_values(array_filter(
        $chainData->getFundingOutputs($order['s_address']),
        function (FundingOutput $output) use ($order) {
            return $output->amountLep() >= (int) $order['amount_lep'];
        }
    ));
    if (count($fundingOutputs) !== 1) {
        throw new RuntimeException(
            "elektron_escrow_build_release_psbt(): order {$order['pk_i_id']} does not have exactly one funding "
            . 'output covering its amount (found ' . count($fundingOutputs) . '); cannot build the release PSBT automatically.'
        );
    }
    $fundingOutput = $fundingOutputs[0];

    $feeRateLepPerVByte = FeeRateResolver::resolve($chainData, $config->feeRateLepPerVByte());

    $escrowAddress = new EscrowAddress(
        $order['s_address'],
        $order['redeem_script_hex'],
        (int) $order['buyer_refund_locktime'],
        (int) $order['seller_release_locktime']
    );

    $sellerPayoutPubKeyHex = (new XpubChildKeyDeriver())->deriveChildPubKeyHex(
        $sellerXpub,
        (int) $order['seller_payout_index'],
        $network
    );

    $psbtBase64 = (new Bip174PsbtBuilder())->buildReleasePsbt(
        $escrowAddress,
        $fundingOutput->txid(),
        $fundingOutput->vout(),
        $fundingOutput->amountLep(),
        new PsbtKeyOrigin($order['buyer_pubkey'], $buyerXpub, (int) $order['buyer_pubkey_index']),
        new PsbtKeyOrigin($order['seller_pubkey'], $sellerXpub, (int) $order['seller_pubkey_index']),
        $order['seller_payout_address'],
        new PsbtKeyOrigin($sellerPayoutPubKeyHex, $sellerXpub, (int) $order['seller_payout_index']),
        $feeRateLepPerVByte,
        $network
    );

    return new PsbtRelay($psbtBase64, 0);
}
