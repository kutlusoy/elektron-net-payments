<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Route: elektron-escrow/orders
 * Lists every escrow order the logged-in user is a party to, as buyer or as
 * seller, each linking to that order's own permanent
 * elektron_escrow_order_status page. Nothing here ever mutates an order;
 * this is purely the shop-style "My orders" overview a buyer/seller lands
 * on from the account menu (see includes/hooks.php's 'user_menu_filter'
 * hook), for a user who navigates here directly instead of finding an order
 * again through its listing.
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to view your Elektron orders.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$userId = (int) osc_logged_user_id();
$orderDao = EscrowOrderDAO::newInstance();
$statusMessage = null;

// Same manual check as the order-status page's own "Check payment now"
// button (see elektron_escrow_check_payment()'s docblock), reachable
// straight from this list so a party does not have to open an order first
// just to ask "has this been paid yet?". Re-checks that the order actually
// belongs to this user (as buyer or seller) rather than trusting the
// posted id, even though checking an order that is not this user's own
// would not itself leak anything this list does not already show them.
if (Params::getParam('check_payment') === '1') {
    osc_csrf_check();

    $checkedOrderId = (int) Params::getParam('order');
    $checkedOrder = $orderDao->findByPrimaryKey($checkedOrderId);
    if ($checkedOrder !== false && ($userId === (int) $checkedOrder['fk_i_buyer_id'] || $userId === (int) $checkedOrder['fk_i_seller_id'])) {
        elektron_escrow_check_payment($checkedOrder);
    }

    $statusMessage = __('Checked for new payments.', ELEKTRON_ESCROW_DOMAIN);
}

$purchases = $orderDao->findByBuyer($userId);
$sales = $orderDao->findBySeller($userId);

require osc_plugins_path() . 'osclass-escrow/views/orders.php';
