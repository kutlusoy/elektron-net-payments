<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * "Buy with Elektron" widget on the item detail page. Only rendered for
 * a logged-in visitor who is not the item's own owner; buying your own
 * listing makes no sense and the escrow model requires two distinct wallets
 * anyway.
 *
 * Hook is 'show_item', not 'item_detail': Shopclass (the actively maintained
 * fork this plugin targets, see README "Reference implementation") renamed
 * it in CWebItem::doModel(), same $item array argument. Verified directly
 * against github.com/mindstellar/shopclass, not assumed.
 */
/**
 * Adds the wallet-connect page to the logged-in user's account menu. This
 * is the actual mechanism that makes it reachable at all: osc_add_route()'s
 * $user_menu = true flag (see index.php) does NOT add anything to that
 * menu by itself, confirmed by reading Rewrite::addRoute()/CWebCustom.php/
 * osc_private_user_menu() in Shopclass core -- it only sets a request-scoped
 * flag consumed by the route's own page chrome once you are already on it.
 * The real mechanism is this same 'user_menu_filter' hook Shopclass's own
 * billing feature uses for its "Credits"/"Buy credits" links (see
 * oc-includes/osclass/helpers/hBilling.php), which is what
 * osc_private_user_menu() actually reads.
 */
osc_add_hook('user_menu_filter', function (array $options) {
    $options[] = array(
        'name' => __('Elektron Net wallet', ELEKTRON_ESCROW_DOMAIN),
        'url' => osc_route_url('elektron_escrow_wallet'),
        'class' => 'opt_elektron_escrow_wallet',
    );

    return $options;
});

osc_add_hook('show_item', function ($item) {
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
