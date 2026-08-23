<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Order-lifecycle email notifications, sent to both the buyer and the
 * seller on the two events explicitly asked for: an order being created,
 * and an order being cancelled. Each recipient gets wording addressed to
 * their own role, matching how the rest of this plugin already treats
 * "funded" and other statuses as buyer/seller-specific text rather than one
 * shared message (see views/order-status.php).
 *
 * Uses Osclass's own osc_sendMail() directly (confirmed contract:
 * oc-includes/osclass/utils.php -- 'to'/'to_name'/'subject'/'body', always
 * sent as HTML), not the admin-editable Page/email-template system real
 * core notifications use (oc-includes/osclass/emails.php): that system
 * needs its own seeded Page rows and an admin UI this plugin does not have,
 * and every other piece of user-facing text in this plugin already goes
 * through its own gettext domain instead, which this keeps consistent
 * with.
 */

/**
 * @param array $order EscrowOrderDAO row (already inserted)
 * @param array $item Osclass item row
 * @param float $amountElek
 */
function elektron_escrow_send_order_created_emails(array $order, array $item, float $amountElek): void
{
    $buyer = User::newInstance()->findByPrimaryKey((int) $order['fk_i_buyer_id']);
    $seller = User::newInstance()->findByPrimaryKey((int) $order['fk_i_seller_id']);
    $orderUrl = osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]);
    $itemTitle = osc_esc_html($item['s_title']);
    $orderLink = '<a href="' . osc_esc_html($orderUrl) . '">' . osc_esc_html(__('View your Elektron order', ELEKTRON_ESCROW_DOMAIN)) . '</a>';

    if ($buyer !== false && trim((string) $buyer['s_email']) !== '') {
        $config = elektron_escrow_config();
        $body = '<p>' . sprintf(osc_esc_html(__('Hello %s,', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($buyer['s_name'])) . '</p>'
            . '<p>' . sprintf(osc_esc_html(__('You started an Elektron Net escrow purchase for "%s".', ELEKTRON_ESCROW_DOMAIN)), $itemTitle) . '</p>'
            . '<p>' . osc_esc_html(elektron_escrow_t('order.awaiting_payment', ['{amount}' => elektron_escrow_format_amount($amountElek)])) . '</p>'
            . '<p><code>' . osc_esc_html($order['s_address']) . '</code></p>'
            . '<p>' . sprintf(
                osc_esc_html(__('If you do not confirm receipt, the seller can automatically claim the funds %d days after your payment is confirmed.', ELEKTRON_ESCROW_DOMAIN)),
                $config->timeoutPolicy()->sellerReleaseDays()
            ) . '</p>'
            . '<p>' . sprintf(
                osc_esc_html(__('If the seller never responds, you can automatically reclaim the funds %d days after your payment is confirmed.', ELEKTRON_ESCROW_DOMAIN)),
                $config->timeoutPolicy()->buyerRefundDays()
            ) . '</p>'
            . '<p>' . $orderLink . '</p>';

        osc_sendMail([
            'to' => $buyer['s_email'],
            'to_name' => $buyer['s_name'],
            'subject' => sprintf(__('Your Elektron Net escrow order #%d', ELEKTRON_ESCROW_DOMAIN), (int) $order['pk_i_id']),
            'body' => $body,
        ], 'elektron_escrow_order_created');
    }

    if ($seller !== false && trim((string) $seller['s_email']) !== '') {
        $body = '<p>' . sprintf(osc_esc_html(__('Hello %s,', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($seller['s_name'])) . '</p>'
            . '<p>' . sprintf(osc_esc_html(__('A buyer started an Elektron Net escrow purchase for your listing "%s".', ELEKTRON_ESCROW_DOMAIN)), $itemTitle) . '</p>'
            . '<p>' . osc_esc_html(__('Amount', ELEKTRON_ESCROW_DOMAIN)) . ': ' . osc_esc_html(elektron_escrow_format_amount($amountElek)) . ' ELEK</p>'
            . '<p>' . osc_esc_html(__('You will be notified once the payment is confirmed.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '<p>' . $orderLink . '</p>';

        osc_sendMail([
            'to' => $seller['s_email'],
            'to_name' => $seller['s_name'],
            'subject' => __('New Elektron Net escrow order for your listing', ELEKTRON_ESCROW_DOMAIN),
            'body' => $body,
        ], 'elektron_escrow_order_created');
    }
}

/**
 * Sent once, the moment an order reaches `funded` (payment confirmed with
 * enough confirmations) -- see includes/payment-watcher.php. Until this
 * existed, both parties only found out by revisiting the order page
 * themselves (see the README, "Open Questions").
 *
 * @param array $order EscrowOrderDAO row (already updated to 'funded')
 * @param array $item Osclass item row
 * @param float $amountElek
 */
function elektron_escrow_send_order_funded_emails(array $order, array $item, float $amountElek): void
{
    $buyer = User::newInstance()->findByPrimaryKey((int) $order['fk_i_buyer_id']);
    $seller = User::newInstance()->findByPrimaryKey((int) $order['fk_i_seller_id']);
    $orderUrl = osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]);
    $itemTitle = osc_esc_html($item['s_title']);
    $orderLink = '<a href="' . osc_esc_html($orderUrl) . '">' . osc_esc_html(__('View your Elektron order', ELEKTRON_ESCROW_DOMAIN)) . '</a>';

    if ($buyer !== false && trim((string) $buyer['s_email']) !== '') {
        $body = '<p>' . sprintf(osc_esc_html(__('Hello %s,', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($buyer['s_name'])) . '</p>'
            . '<p>' . sprintf(osc_esc_html(__('Your payment of %s ELEK for "%s" is confirmed.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html(elektron_escrow_format_amount($amountElek)), $itemTitle) . '</p>'
            . '<p>' . osc_esc_html(__('Please confirm receipt on the order page once the item arrives.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '<p>' . $orderLink . '</p>';

        osc_sendMail([
            'to' => $buyer['s_email'],
            'to_name' => $buyer['s_name'],
            'subject' => sprintf(__('Payment confirmed for your Elektron Net escrow order #%d', ELEKTRON_ESCROW_DOMAIN), (int) $order['pk_i_id']),
            'body' => $body,
        ], 'elektron_escrow_order_funded');
    }

    if ($seller !== false && trim((string) $seller['s_email']) !== '') {
        $shippingBlock = '<p><strong>' . osc_esc_html(__('Ship to:', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
            . '<p>' . osc_esc_html($order['buyer_shipping_name']) . '<br>'
            . nl2br(osc_esc_html($order['buyer_shipping_address'])) . '</p>'
            . '<p>' . osc_esc_html(__('Phone number', ELEKTRON_ESCROW_DOMAIN)) . ': ' . osc_esc_html($order['buyer_shipping_phone']) . '</p>'
            . '<p>' . osc_esc_html(__('Contact email for this order', ELEKTRON_ESCROW_DOMAIN)) . ': ' . osc_esc_html($order['buyer_contact_email']) . '</p>';

        $body = '<p>' . sprintf(osc_esc_html(__('Hello %s,', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($seller['s_name'])) . '</p>'
            . '<p>' . sprintf(osc_esc_html(__('The buyer\'s payment of %s ELEK for "%s" is confirmed.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html(elektron_escrow_format_amount($amountElek)), $itemTitle) . '</p>'
            . $shippingBlock
            . '<p>' . osc_esc_html(__('If this listing is now sold, remember to deactivate it yourself -- this is not done automatically.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '<p>' . osc_esc_html(__('You will be notified once the buyer confirms receipt.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '<p>' . $orderLink . '</p>';

        osc_sendMail([
            'to' => $seller['s_email'],
            'to_name' => $seller['s_name'],
            'subject' => sprintf(__('Payment confirmed for your Elektron Net escrow order #%d', ELEKTRON_ESCROW_DOMAIN), (int) $order['pk_i_id']),
            'body' => $body,
        ], 'elektron_escrow_order_funded');
    }
}

/**
 * @param array $order EscrowOrderDAO row (about to be/already deleted)
 * @param array $item Osclass item row
 */
function elektron_escrow_send_order_cancelled_emails(array $order, array $item): void
{
    $buyer = User::newInstance()->findByPrimaryKey((int) $order['fk_i_buyer_id']);
    $seller = User::newInstance()->findByPrimaryKey((int) $order['fk_i_seller_id']);
    $itemTitle = osc_esc_html($item['s_title']);

    if ($buyer !== false && trim((string) $buyer['s_email']) !== '') {
        $body = '<p>' . sprintf(osc_esc_html(__('Hello %s,', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($buyer['s_name'])) . '</p>'
            . '<p>' . sprintf(osc_esc_html(__('Your escrow order for "%s" has been cancelled. No payment was made.', ELEKTRON_ESCROW_DOMAIN)), $itemTitle) . '</p>';

        osc_sendMail([
            'to' => $buyer['s_email'],
            'to_name' => $buyer['s_name'],
            'subject' => sprintf(__('Your Elektron Net escrow order #%d was cancelled', ELEKTRON_ESCROW_DOMAIN), (int) $order['pk_i_id']),
            'body' => $body,
        ], 'elektron_escrow_order_cancelled');
    }

    if ($seller !== false && trim((string) $seller['s_email']) !== '') {
        $body = '<p>' . sprintf(osc_esc_html(__('Hello %s,', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($seller['s_name'])) . '</p>'
            . '<p>' . sprintf(osc_esc_html(__('The buyer cancelled their escrow order for your listing "%s". No payment was made.', ELEKTRON_ESCROW_DOMAIN)), $itemTitle) . '</p>';

        osc_sendMail([
            'to' => $seller['s_email'],
            'to_name' => $seller['s_name'],
            'subject' => __('An Elektron Net escrow order for your listing was cancelled', ELEKTRON_ESCROW_DOMAIN),
            'body' => $body,
        ], 'elektron_escrow_order_cancelled');
    }
}
