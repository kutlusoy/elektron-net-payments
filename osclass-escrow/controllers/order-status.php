<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\OrderStateMachine;
use ElektronNet\Payments\Core\Escrow\OrderStatus;

/**
 * Route: elektron-escrow/order?order=<id>
 * Shows one order's status/countdown to whichever of the two parties is
 * looking at it, and (buyer only) the "confirm receipt" action. See this
 * plugin's README, "Routes" and "Order lifecycle".
 */

if (!osc_is_web_user_logged_in()) {
    osc_redirect_to(osc_user_login_url());
    exit;
}

$orderId = (int) Params::getParam('order');
$orderDao = EscrowOrderDAO::newInstance();
$order = $orderDao->findByPrimaryKey($orderId);
$userId = (int) osc_logged_user_id();

if (!$order || ($userId !== (int) $order['fk_i_buyer_id'] && $userId !== (int) $order['fk_i_seller_id'])) {
    osc_add_flash_error_message(__('Order not found.', ELEKTRON_ESCROW_DOMAIN));
    osc_redirect_to(osc_base_url());
    exit;
}

$isBuyer = $userId === (int) $order['fk_i_buyer_id'];

if ($isBuyer && Params::getParam('confirm_receipt') === '1' && Params::getParam('CSRFName') !== '') {
    osc_csrf_check();

    if (OrderStateMachine::canTransition($order['status'], OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE)) {
        $orderDao->updateByPrimaryKey(
            ['status' => OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE, 'dt_mod_date' => date('Y-m-d H:i:s')],
            $orderId
        );
        // TODO: build the actual release PSBT here once a
        // ReleasePsbtBuilderInterface implementation exists (see
        // shared/README.md, "Open items") and store it via PsbtRelay. Until
        // then the status change is recorded but no PSBT is generated yet,
        // which the status view below says explicitly.
        osc_add_flash_ok_message(__('Receipt confirmed.', ELEKTRON_ESCROW_DOMAIN));
    }

    osc_redirect_to(osc_route_url('elektron_escrow_order_status', ['order' => $orderId]));
    exit;
}

require osc_plugins_path() . 'osclass-escrow/views/order-status.php';
