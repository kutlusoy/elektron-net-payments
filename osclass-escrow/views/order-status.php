<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $order */
/** @var array|false $item Osclass item row, or false if the listing was since removed (see DAO::findByPrimaryKey()) */
/** @var float $amountElek */
/** @var int $requiredConfirmations */
/** @var bool $isBuyer */
/** @var string|null $statusMessage */
/** @var string|null $errorMessage */

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use ElektronNet\Payments\Core\Escrow\OrderStatus;

$now = time();
$pastT1 = $now >= (int) $order['buyer_refund_locktime'];
$showPaymentDetails = $order['status'] === OrderStatus::AWAITING_PAYMENT || $order['status'] === OrderStatus::CONFIRMING;
?>

<div class="elektron-escrow-order">
    <h1><?php _e('Elektron order', ELEKTRON_ESCROW_DOMAIN); ?> #<?php echo (int) $order['pk_i_id']; ?></h1>

    <table class="table">
        <tr>
            <td><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td>
                <?php if ($item !== false) { ?>
                    <a href="<?php echo osc_esc_html(osc_item_url_from_item($item)); ?>"><?php echo osc_esc_html($item['s_title']); ?></a>
                <?php } else { ?>
                    <?php _e('(listing removed)', ELEKTRON_ESCROW_DOMAIN); ?>
                <?php } ?>
            </td>
        </tr>
        <tr>
            <td><?php _e('Amount', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td><?php echo osc_esc_html(elektron_escrow_format_amount($amountElek)); ?> ELEK</td>
        </tr>
        <tr>
            <td><?php _e('Status', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td class="elektron-escrow-status" data-status="<?php echo osc_esc_html($order['status']); ?>"><?php echo osc_esc_html(elektron_escrow_status_label($order['status'])); ?></td>
        </tr>
    </table>

    <?php if ($statusMessage !== null) { ?>
        <p class="elektron-escrow-success"><?php echo osc_esc_html($statusMessage); ?></p>
    <?php } ?>

    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <?php if ($showPaymentDetails) { ?>
        <?php
        // Encodes the bare address, not a payment URI: no BIP21-style URI
        // scheme for Elektron Net is documented anywhere in this project
        // yet. Once one exists, encode that instead so wallets can prefill
        // the amount.
        $qrCode = new QrCode($order['s_address']);
        $qrPngBase64 = base64_encode((new PngWriter())->write($qrCode)->getString());
        ?>
        <img src="data:image/png;base64,<?php echo $qrPngBase64; ?>" alt="<?php echo osc_esc_html($order['s_address']); ?>" />

        <p class="elektron-escrow-address"><code><?php echo osc_esc_html($order['s_address']); ?></code></p>
    <?php } ?>

    <?php if ($order['status'] === OrderStatus::AWAITING_PAYMENT) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.awaiting_payment', [
            '{amount}' => elektron_escrow_format_amount($amountElek),
        ])); ?></p>

    <?php } elseif ($order['status'] === OrderStatus::CONFIRMING) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.confirming', [
            '{confirmations}' => (string) $requiredConfirmations,
        ])); ?></p>

    <?php } elseif ($order['status'] === OrderStatus::FUNDED) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.funded.' . ($isBuyer ? 'buyer' : 'seller'))); ?></p>

        <?php if (!$pastT1) { ?>
            <p class="elektron-escrow-countdown" data-locktime="<?php echo (int) $order['buyer_refund_locktime']; ?>">
                <?php echo osc_esc_html(elektron_escrow_t('order.countdown.buyer_refund', [
                    '{date}' => date('Y-m-d', (int) $order['buyer_refund_locktime']),
                ])); ?>
            </p>
        <?php } else { ?>
            <p class="elektron-escrow-countdown" data-locktime="<?php echo (int) $order['seller_release_locktime']; ?>">
                <?php echo osc_esc_html(elektron_escrow_t('order.countdown.seller_release', [
                    '{date}' => date('Y-m-d', (int) $order['seller_release_locktime']),
                ])); ?>
            </p>
        <?php } ?>

        <?php if ($isBuyer) { ?>
            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="confirm_receipt" value="1" />
                <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Confirm receipt', ELEKTRON_ESCROW_DOMAIN)); ?>" />
            </form>
        <?php } ?>

    <?php } elseif ($order['status'] === OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.release_pending_seller_signature')); ?></p>
        <p class="text-muted"><?php _e('Release PSBT construction is not implemented yet in this draft; nothing has actually moved on chain.', ELEKTRON_ESCROW_DOMAIN); ?></p>

    <?php } else { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.' . $order['status'])); ?></p>
    <?php } ?>
</div>
