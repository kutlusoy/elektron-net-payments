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
    $options[] = array(
        'name' => __('My Elektron orders', ELEKTRON_ESCROW_DOMAIN),
        'url' => osc_route_url('elektron_escrow_orders'),
        'class' => 'opt_elektron_escrow_orders',
    );

    return $options;
});

/**
 * Hook is 'item_detail', not 'show_item'. 'show_item' fires from inside the
 * item controller's own doModel() (data-preparation) method -- confirmed in
 * both a real osclass-classifieds.com v8.3.1 checkout
 * (oc-includes/osclass/controller/item.php) and mindstellar/shopclass
 * (CWebItem::doModel()) -- which runs entirely before the theme starts
 * emitting any page markup at all. Echoing HTML from it does not place that
 * HTML anywhere on the page; it gets flushed to the response stream ahead
 * of the theme's own <html> output, which is exactly why this widget was
 * appearing above everything else, including the page header, on a real
 * install. 'item_detail' is the hook the *theme's own template* actually
 * calls (confirmed in the real, currently deployed theme,
 * oc-content/themes/sigma/item.php: `osc_run_hook('item_detail', osc_item())`
 * inside the "#item-content" block, right after the description/custom
 * fields and before the "Contact seller" buttons), so output from it lands
 * in the correct place in the actual rendered page. osc_item() returns the
 * exact same array previously passed as show_item's own $item argument
 * (both call `_exportVariableToView('item', $item)` immediately before
 * firing their hook), so the callback body did not need to change.
 */
osc_add_hook('item_detail', function ($item) {
    if (!osc_logged_user_id() || osc_logged_user_id() === osc_item_user_id()) {
        return;
    }

    $order = EscrowOrderDAO::newInstance()->findByItemAndBuyer((int) $item['pk_i_id'], (int) osc_logged_user_id());

    require osc_plugins_path() . 'osclass-escrow/views/item-widget.php';
});

/**
 * Payment watcher: the piece that actually moves an order from
 * awaiting_payment through confirming to funded. Nothing did this before --
 * an order's own escrow address was never checked against the chain at all,
 * so no order could ever leave awaiting_payment on its own no matter what a
 * buyer actually sent (see README, "Order lifecycle" and "Open Questions").
 * The actual check is elektron_escrow_check_payment()
 * (includes/payment-watcher.php), shared with the manual "Check payment
 * now" action on the order-status and "My Elektron orders" pages -- that
 * manual action exists because this hook alone is not reliable: it only
 * fires opportunistically at the end of a page load if Osclass's own
 * `auto_cron` admin setting is on, or from a real system cron the
 * marketplace operator has to configure separately, so a fully confirmed
 * payment can otherwise sit unnoticed until one of those actually runs.
 *
 * Hooked on 'cron_minutely' (throttled server-side to at most once every 5
 * minutes regardless of how often it fires, see oc-includes/osclass/
 * cron.php), the finest-grained cron hook Osclass has; running this on
 * 'cron_daily' like the reminder sweep below would leave a real payment
 * undetected for up to a day.
 */
osc_add_hook('cron_minutely', function () {
    foreach (EscrowOrderDAO::newInstance()->findAwaitingPaymentOrConfirming() as $order) {
        elektron_escrow_check_payment($order);
    }
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
