<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\OrderStateMachine;
use ElektronNet\Payments\Core\Escrow\OrderStatus;
use ElektronNet\Payments\Core\Escrow\TimeoutPolicy;

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

    if (!OrderStateMachine::canTransition($order['status'], OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE)) {
        // Nothing to do: wrong status for this action (e.g. a stale page,
        // double submit, or a terminal order). No error shown, matching
        // this action's existing silent-no-op behavior for that case.
    } elseif (!TimeoutPolicy::canSafelyStartRelease((int) $order['buyer_refund_locktime'], time())) {
        // See TimeoutPolicy::canSafelyStartRelease's docblock: refused, not
        // merely warned about, because "confirm receipt" has no on-chain
        // effect -- the buyer's own refund path stays spendable by the
        // buyer alone from T1 onward no matter what this plugin's database
        // says, so starting a release this close to T1 could tell the
        // seller funds are coming while the buyer can still reclaim them.
        osc_add_flash_error_message(elektron_escrow_t('order.confirm_receipt.too_close_to_refund'));
    } else {
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
