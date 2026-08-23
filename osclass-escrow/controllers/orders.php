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
    osc_redirect_to(osc_user_login_url());
    exit;
}

$userId = (int) osc_logged_user_id();
$orderDao = EscrowOrderDAO::newInstance();

$purchases = $orderDao->findByBuyer($userId);
$sales = $orderDao->findBySeller($userId);

require osc_plugins_path() . 'osclass-escrow/views/orders.php';
