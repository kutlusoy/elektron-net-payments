<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array[] $purchases EscrowOrderDAO rows where the logged-in user is the buyer */
/** @var array[] $sales EscrowOrderDAO rows where the logged-in user is the seller */

elektron_escrow_table_style();
?>

<div class="elektron-escrow-orders">
    <h1><?php _e('My Elektron orders', ELEKTRON_ESCROW_DOMAIN); ?></h1>

    <h2><?php _e('Purchases', ELEKTRON_ESCROW_DOMAIN); ?></h2>
    <?php if (empty($purchases)) { ?>
        <p><?php _e('You have not bought anything with Elektron escrow yet.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } else { ?>
        <table class="elektron-escrow-table">
            <tr>
                <th><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></th>
                <th><?php _e('Amount', ELEKTRON_ESCROW_DOMAIN); ?></th>
                <th><?php _e('Status', ELEKTRON_ESCROW_DOMAIN); ?></th>
                <th></th>
            </tr>
            <?php foreach ($purchases as $order) { ?>
                <?php $orderItem = Item::newInstance()->findByPrimaryKey((int) $order['fk_i_item_id']); ?>
                <tr>
                    <td><?php echo $orderItem !== false ? osc_esc_html($orderItem['s_title']) : osc_esc_html(__('(listing removed)', ELEKTRON_ESCROW_DOMAIN)); ?></td>
                    <td><?php echo osc_esc_html(elektron_escrow_format_amount(((int) $order['amount_lep']) / 100000000)); ?> ELEK</td>
                    <td class="elektron-escrow-status" data-status="<?php echo osc_esc_html($order['status']); ?>"><?php echo osc_esc_html(elektron_escrow_status_label($order['status'])); ?></td>
                    <td><a href="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>"><?php _e('View', ELEKTRON_ESCROW_DOMAIN); ?></a></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>

    <h2><?php _e('Sales', ELEKTRON_ESCROW_DOMAIN); ?></h2>
    <?php if (empty($sales)) { ?>
        <p><?php _e('You have not sold anything with Elektron escrow yet.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } else { ?>
        <table class="elektron-escrow-table">
            <tr>
                <th><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></th>
                <th><?php _e('Amount', ELEKTRON_ESCROW_DOMAIN); ?></th>
                <th><?php _e('Status', ELEKTRON_ESCROW_DOMAIN); ?></th>
                <th></th>
            </tr>
            <?php foreach ($sales as $order) { ?>
                <?php $orderItem = Item::newInstance()->findByPrimaryKey((int) $order['fk_i_item_id']); ?>
                <tr>
                    <td><?php echo $orderItem !== false ? osc_esc_html($orderItem['s_title']) : osc_esc_html(__('(listing removed)', ELEKTRON_ESCROW_DOMAIN)); ?></td>
                    <td><?php echo osc_esc_html(elektron_escrow_format_amount(((int) $order['amount_lep']) / 100000000)); ?> ELEK</td>
                    <td class="elektron-escrow-status" data-status="<?php echo osc_esc_html($order['status']); ?>"><?php echo osc_esc_html(elektron_escrow_status_label($order['status'])); ?></td>
                    <td><a href="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>"><?php _e('View', ELEKTRON_ESCROW_DOMAIN); ?></a></td>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
</div>
