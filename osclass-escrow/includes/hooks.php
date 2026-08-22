<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * "Buy with Elektron" widget on the item detail page. Only rendered for
 * a logged-in visitor who is not the item's own owner; buying your own
 * listing makes no sense and the escrow model requires two distinct wallets
 * anyway.
 */
osc_add_hook('item_detail', function ($item) {
    if (!osc_logged_user_id() || osc_logged_user_id() === osc_item_user_id()) {
        return;
    }

    $order = EscrowOrderDAO::newInstance()->findByItemAndBuyer((int) $item['pk_i_id'], (int) osc_logged_user_id());

    require osc_plugins_path() . 'osclass-escrow/views/item-widget.php';
});

/**
 * Daily sweep: send the T1/T2 reminder notifications described in the
 * README ("Countdown display"), using the shared core's ReminderScheduler
 * to decide whether "now" falls inside a reminder window.
 *
 * TODO: actual delivery (email, in-portal message) is not implemented yet;
 * see this plugin's README, "Open Questions". This hook currently only logs
 * which orders are due, so the delivery mechanism can be added in one place
 * once decided, without having to re-derive which orders qualify.
 */
osc_add_hook('cron_daily', function () {
    $dao = EscrowOrderDAO::newInstance();
    $scheduler = new \ElektronNet\Payments\Core\Notifications\ReminderScheduler();
    $now = time();

    foreach ($dao->findFunded() as $order) {
        if ($scheduler->isReminderDue((int) $order['buyer_refund_locktime'], $now)) {
            osc_run_hook('elektron_escrow_buyer_refund_reminder_due', $order);
        }
    }

    foreach ($dao->findOpenForSellerRelease() as $order) {
        if ($scheduler->isReminderDue((int) $order['seller_release_locktime'], $now)) {
            osc_run_hook('elektron_escrow_seller_release_reminder_due', $order);
        }
    }
});
