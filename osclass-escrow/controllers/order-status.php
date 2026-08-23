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
    elektron_escrow_render_notice(
        __('Please log in to view this order.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$orderId = (int) Params::getParam('order');
$orderDao = EscrowOrderDAO::newInstance();
$order = $orderDao->findByPrimaryKey($orderId);
$userId = (int) osc_logged_user_id();

if (!$order || ($userId !== (int) $order['fk_i_buyer_id'] && $userId !== (int) $order['fk_i_seller_id'])) {
    elektron_escrow_render_notice(
        __('Order not found.', ELEKTRON_ESCROW_DOMAIN),
        osc_base_url(),
        __('Back to homepage', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$isBuyer = $userId === (int) $order['fk_i_buyer_id'];
$statusMessage = null;
$errorMessage = null;

// Either party can trigger this: it only ever reads the chain and, if
// justified, advances this order's own status -- there is nothing
// buyer/seller-specific about asking "has this been paid yet?". See
// elektron_escrow_check_payment()'s docblock (includes/payment-watcher.php)
// for why this manual action exists alongside the 'cron_minutely' watcher.
if (Params::getParam('check_payment') === '1') {
    osc_csrf_check();

    $statusBefore = $order['status'];
    $order = elektron_escrow_check_payment($order);
    $statusMessage = $order['status'] === $statusBefore
        ? __('No new payment detected yet.', ELEKTRON_ESCROW_DOMAIN)
        : __('Payment status updated.', ELEKTRON_ESCROW_DOMAIN);
}

// Buyer-only, and only while still awaiting_payment: cancelling deletes the
// order outright rather than moving it to some new "cancelled" status (see
// EscrowOrderDAO::cancelByBuyer()'s docblock), so there is nothing left to
// render as an order-status page afterward -- a notice is shown instead.
// The buyer/seller emails are sent with the item and order data fetched
// *before* the row is gone, since the DAO method itself only ever returns
// whether the delete happened, not the row it deleted.
if ($isBuyer && Params::getParam('cancel_order') === '1') {
    osc_csrf_check();

    if ($orderDao->cancelByBuyer($orderId, $userId)) {
        $item = Item::newInstance()->findByPrimaryKey((int) $order['fk_i_item_id']);
        if ($item !== false) {
            elektron_escrow_send_order_cancelled_emails($order, $item);
        }

        elektron_escrow_render_notice(
            __('This order has been cancelled.', ELEKTRON_ESCROW_DOMAIN),
            osc_route_url('elektron_escrow_orders'),
            __('Back to your orders', ELEKTRON_ESCROW_DOMAIN)
        );
        exit;
    }

    // Did not delete anything. Re-check what actually happened rather than
    // assume why: either the order moved past awaiting_payment (e.g. the
    // payment watcher, includes/hooks.php's 'cron_minutely' hook, saw a
    // payment arrive between this page loading and the cancel button being
    // pressed), or it is already gone (a double-submitted cancel: this same
    // buyer's own first request already deleted it a moment earlier).
    $order = $orderDao->findByPrimaryKey($orderId);
    if ($order === false) {
        elektron_escrow_render_notice(
            __('This order has been cancelled.', ELEKTRON_ESCROW_DOMAIN),
            osc_route_url('elektron_escrow_orders'),
            __('Back to your orders', ELEKTRON_ESCROW_DOMAIN)
        );
        exit;
    }
    $errorMessage = __('This order can no longer be cancelled.', ELEKTRON_ESCROW_DOMAIN);
}

// The 'confirm_receipt' marker alone decides whether this is a
// submission; the CSRF token itself is deliberately NOT inspected before
// calling osc_csrf_check(). See controllers/wallet.php for why: an
// earlier version also required Params::getParam('CSRFName') !== '',
// which hardcoded Shopclass's own token field name as an assumption about
// a different real, deployed platform's internals, confirmed wrong live
// (that platform's actual field is a single 'octoken' input). Renders
// directly into this same response rather than osc_add_flash_*_message()
// + a redirect: see includes/notice.php's docblock for why a same-request
// render, not a redirect, is what actually works here.
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

elektron_escrow_render_order_status($order, $userId, $statusMessage, $errorMessage);
