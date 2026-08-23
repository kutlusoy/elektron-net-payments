<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\BitwaspEscrowScriptBuilder;
use ElektronNet\Payments\Core\Escrow\EscrowAddress;
use ElektronNet\Payments\Core\Escrow\OrderStatus;

/**
 * Route: elektron-escrow/checkout?item=<id>
 * GET shows a preview of the purchase (item, price, timeout policy) without
 * creating anything -- visiting this URL, or a crawler following the "Buy"
 * link, must never by itself commit to a real order. Only a POST with the
 * 'confirm_checkout' marker (and a valid CSRF token) actually creates the
 * escrow order, which is then rendered directly in this same response (see
 * includes/order-status.php's docblock for why this never redirects there
 * instead). See this plugin's README, "Routes" and "Order lifecycle", for
 * the full spec.
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to buy with Elektron.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$itemId = (int) Params::getParam('item');
$item = Item::newInstance()->findByPrimaryKey($itemId);
$buyerId = (int) osc_logged_user_id();

if (!$item) {
    elektron_escrow_render_notice(
        __('This listing does not exist.', ELEKTRON_ESCROW_DOMAIN),
        osc_base_url(),
        __('Back to homepage', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}
if ($item['fk_i_user_id'] == $buyerId) {
    elektron_escrow_render_notice(
        __('You cannot buy your own listing.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}
// Defense in depth: views/item-widget.php already hides the "Buy" link for
// an ineligible currency, but this route is reachable directly by URL too.
if (!in_array(strtoupper((string) ($item['fk_c_currency_code'] ?? '')), elektron_escrow_currency_codes(), true)) {
    elektron_escrow_render_notice(
        __('This listing is not priced in a currency accepted for escrow.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$amountElek = elektron_escrow_item_price_elek($item);
if ($amountElek === null) {
    elektron_escrow_render_notice(
        __('This listing has no fixed price, so it cannot be bought with escrow.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$buyerPubKey = elektron_escrow_get_user_pubkey($buyerId);
$sellerPubKey = elektron_escrow_get_user_pubkey((int) $item['fk_i_user_id']);

if ($buyerPubKey === null) {
    elektron_escrow_render_notice(
        __('Connect an Elektron Net wallet to your account first, then come back to this listing.', ELEKTRON_ESCROW_DOMAIN),
        osc_route_url('elektron_escrow_wallet'),
        __('Connect your wallet', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}
if ($sellerPubKey === null) {
    elektron_escrow_render_notice(
        __('The seller has not connected an Elektron Net wallet yet, so this listing cannot be bought with escrow right now.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$orderDao = EscrowOrderDAO::newInstance();
$existingOrder = $orderDao->findByItemAndBuyer($itemId, $buyerId);

// Already has a live order for this item: nothing left to confirm, so show
// its permanent details page directly instead of the preview again.
if ($existingOrder !== null) {
    elektron_escrow_render_order_status($existingOrder, $buyerId);
    exit;
}

$config = elektron_escrow_config();

if (Params::getParam('confirm_checkout') === '1') {
    osc_csrf_check();

    $fundedAt = time();
    // Unique per order, so this order's escrow address is unique even if
    // this same buyer and seller transact more than once (possibly within
    // the same second) -- see EscrowScriptBuilderInterface's docblock.
    $orderNonceHex = bin2hex(random_bytes(16));

    // See EscrowScriptBuilderInterface's docblock: reference implementation,
    // not yet verified on a live Elektron Net node.
    $scriptBuilder = new BitwaspEscrowScriptBuilder(elektron_escrow_network());
    $escrowAddress = $scriptBuilder->build($buyerPubKey, $sellerPubKey, $orderNonceHex, $config->timeoutPolicy(), $fundedAt);

    $amountLep = (int) round($amountElek * 100000000);

    $orderDao->insert([
        'fk_i_item_id' => $itemId,
        'fk_i_buyer_id' => $buyerId,
        'fk_i_seller_id' => (int) $item['fk_i_user_id'],
        'buyer_pubkey' => $buyerPubKey,
        'seller_pubkey' => $sellerPubKey,
        's_address' => $escrowAddress->address(),
        'redeem_script_hex' => $escrowAddress->redeemScriptHex(),
        'buyer_refund_locktime' => $escrowAddress->buyerRefundLocktime(),
        'seller_release_locktime' => $escrowAddress->sellerReleaseLocktime(),
        'amount_lep' => $amountLep,
        'status' => OrderStatus::AWAITING_PAYMENT,
        'psbt_signature_count' => 0,
        'dt_pub_date' => date('Y-m-d H:i:s', $fundedAt),
    ]);

    $order = $orderDao->findByItemAndBuyer($itemId, $buyerId);

    elektron_escrow_send_order_created_emails($order, $item, $amountElek);
    elektron_escrow_render_order_status($order, $buyerId);
    exit;
}

// GET, no existing order, not confirmed yet: show the preview only. No
// order, no escrow address, and no other side effect is created here --
// see this file's own docblock for why that matters.
$timeoutPolicy = $config->timeoutPolicy();

require osc_plugins_path() . 'osclass-escrow/views/checkout.php';
