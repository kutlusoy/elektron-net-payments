<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\OrderStateMachine;
use ElektronNet\Payments\Core\Escrow\OrderStatus;
use ElektronNet\Payments\Core\Escrow\TimeoutPolicy;

/**
 * Route: elektron-escrow/order?order=<id>
 * The permanent details page for one order: item, amount, and (per status)
 * the payment address + QR code, countdown, or "confirm receipt" action, to
 * whichever of the two parties is looking at it. This is the one page an
 * order's details stay reachable from after checkout ends -- see this
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
$statusMessage = null;
$errorMessage = null;

// Not re-derived from the item's *current* price: amount_lep was already
// frozen on the order at checkout time (see controllers/checkout.php), and
// an item's price can change after an order exists.
$item = Item::newInstance()->findByPrimaryKey((int) $order['fk_i_item_id']);
$amountElek = ((int) $order['amount_lep']) / 100000000;
$requiredConfirmations = (int) osc_get_preference('required_confirmations', 'plugin-osclass-escrow');

// The 'confirm_receipt' marker alone decides whether this is a
// submission; the CSRF token itself is deliberately NOT inspected before
// calling osc_csrf_check(). See controllers/wallet.php for why: an
// earlier version also required Params::getParam('CSRFName') !== '',
// which hardcoded Shopclass's own token field name as an assumption about
// a different real, deployed platform's internals, confirmed wrong live
// (that platform's actual field is a single 'octoken' input). Renders
// directly into this same response rather than osc_add_flash_*_message()
// + a redirect, since a flash message is only shown by whatever the
// active theme's custom.php/user-custom.php template does with it.
if ($isBuyer && Params::getParam('confirm_receipt') === '1') {
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
        $errorMessage = elektron_escrow_t('order.confirm_receipt.too_close_to_refund');
    } else {
        $orderDao->updateByPrimaryKey(
            ['status' => OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE, 'dt_mod_date' => date('Y-m-d H:i:s')],
            $orderId
        );
        // Reflects the just-applied change locally so the view below
        // renders the new status without a second database round trip.
        $order['status'] = OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE;
        // TODO: build the actual release PSBT here once a
        // ReleasePsbtBuilderInterface implementation exists (see
        // shared/README.md, "Open items") and store it via PsbtRelay. Until
        // then the status change is recorded but no PSBT is generated yet,
        // which the status view below says explicitly.
        $statusMessage = __('Receipt confirmed.', ELEKTRON_ESCROW_DOMAIN);
    }
}

require osc_plugins_path() . 'osclass-escrow/views/order-status.php';
