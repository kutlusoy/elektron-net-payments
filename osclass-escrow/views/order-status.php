<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $order */
/** @var bool $isBuyer */

use ElektronNet\Payments\Core\Escrow\OrderStatus;

$now = time();
$pastT1 = $now >= (int) $order['buyer_refund_locktime'];
?>

<div class="elektron-escrow-order">
    <h1><?php _e('Elektron Net order', ELEKTRON_ESCROW_DOMAIN); ?> #<?php echo (int) $order['pk_i_id']; ?></h1>

    <?php if ($order['status'] === OrderStatus::AWAITING_PAYMENT || $order['status'] === OrderStatus::CONFIRMING) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.' . $order['status'])); ?></p>

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
