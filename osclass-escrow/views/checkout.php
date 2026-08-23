<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $item Osclass item row */
/** @var float $amountElek */
/** @var \ElektronNet\Payments\Core\Escrow\TimeoutPolicy $timeoutPolicy */

// Preview only: no order, address, or QR code exists yet at this point (see
// controllers/checkout.php's docblock). Once an order is created, its own
// permanent elektron_escrow_order_status page is what shows those.
?>

<div class="elektron-escrow-checkout-preview">
    <h1><?php echo osc_esc_html($item['s_title']); ?></h1>

    <p><?php _e('You are about to start an Elektron Net escrow purchase for this listing.', ELEKTRON_ESCROW_DOMAIN); ?></p>

    <table class="table">
        <tr>
            <td><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td><a href="<?php echo osc_esc_html(osc_item_url_from_item($item)); ?>"><?php echo osc_esc_html($item['s_title']); ?></a></td>
        </tr>
        <tr>
            <td><?php _e('Price', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td><?php echo osc_esc_html(elektron_escrow_format_amount($amountElek)); ?> ELEK</td>
        </tr>
    </table>

    <p><?php _e('A unique payment address is generated only after you confirm below. Funds sent there are held in a 2-of-2 escrow between you and the seller.', ELEKTRON_ESCROW_DOMAIN); ?></p>

    <p><?php printf(
        __('If you do not confirm receipt, the seller can automatically claim the funds %d days after your payment is confirmed.', ELEKTRON_ESCROW_DOMAIN),
        $timeoutPolicy->sellerReleaseDays()
    ); ?></p>
    <p><?php printf(
        __('If the seller never responds, you can automatically reclaim the funds %d days after your payment is confirmed.', ELEKTRON_ESCROW_DOMAIN),
        $timeoutPolicy->buyerRefundDays()
    ); ?></p>

    <form method="post" action="<?php echo osc_route_url('elektron_escrow_checkout', ['item' => $item['pk_i_id']]); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="confirm_checkout" value="1" />
        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Confirm and generate payment address', ELEKTRON_ESCROW_DOMAIN)); ?>" />
        <a class="btn btn-default" href="<?php echo osc_esc_html(osc_item_url_from_item($item)); ?>"><?php _e('Cancel', ELEKTRON_ESCROW_DOMAIN); ?></a>
    </form>
</div>
