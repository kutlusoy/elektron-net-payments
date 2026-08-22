<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\BitwaspEscrowScriptBuilder;
use ElektronNet\Payments\Core\Escrow\EscrowAddress;
use ElektronNet\Payments\Core\Escrow\OrderStatus;

/**
 * Route: elektron-escrow/checkout?item=<id>
 * Creates (or resumes) this buyer's escrow order for one item and shows the
 * payment address + QR code + countdown. See this plugin's README, "Routes"
 * and "Order lifecycle", for the full spec.
 */

if (!osc_is_web_user_logged_in()) {
    osc_add_flash_error_message(__('Please log in to buy with Elektron.', ELEKTRON_ESCROW_DOMAIN));
    osc_redirect_to(osc_user_login_url());
    exit;
}

$itemId = (int) Params::getParam('item');
$item = Item::newInstance()->findByPrimaryKey($itemId);
$buyerId = (int) osc_logged_user_id();

if (!$item) {
    osc_add_flash_error_message(__('This listing does not exist.', ELEKTRON_ESCROW_DOMAIN));
    osc_redirect_to(osc_base_url());
    exit;
}
if ($item['fk_i_user_id'] == $buyerId) {
    osc_add_flash_error_message(__('You cannot buy your own listing.', ELEKTRON_ESCROW_DOMAIN));
    osc_redirect_to(osc_item_url_from_item($item));
    exit;
}
// Defense in depth: views/item-widget.php already hides the "Buy" link for
// an ineligible currency, but this route is reachable directly by URL too.
if (!in_array(strtoupper((string) ($item['fk_c_currency_code'] ?? '')), elektron_escrow_currency_codes(), true)) {
    osc_add_flash_error_message(__('This listing is not priced in a currency accepted for escrow.', ELEKTRON_ESCROW_DOMAIN));
    osc_redirect_to(osc_item_url_from_item($item));
    exit;
}

$buyerPubKey = elektron_escrow_get_user_pubkey($buyerId);
$sellerPubKey = elektron_escrow_get_user_pubkey((int) $item['fk_i_user_id']);

if ($buyerPubKey === null) {
    osc_add_flash_error_message(__('Connect an Elektron Net wallet to your account first, then come back to this listing.', ELEKTRON_ESCROW_DOMAIN));
    osc_redirect_to(osc_route_url('elektron_escrow_wallet'));
    exit;
}
if ($sellerPubKey === null) {
    osc_add_flash_error_message(__('The seller has not connected an Elektron Net wallet yet, so this listing cannot be bought with escrow right now.', ELEKTRON_ESCROW_DOMAIN));
    osc_redirect_to(osc_item_url_from_item($item));
    exit;
}

$orderDao = EscrowOrderDAO::newInstance();
$order = $orderDao->findByItemAndBuyer($itemId, $buyerId);

if ($order === null) {
    $config = elektron_escrow_config();
    $fundedAt = time();
    // Unique per order, so this order's escrow address is unique even if
    // this same buyer and seller transact more than once (possibly within
    // the same second) -- see EscrowScriptBuilderInterface's docblock.
    $orderNonceHex = bin2hex(random_bytes(16));

    // See EscrowScriptBuilderInterface's docblock: reference implementation,
    // not yet verified on a live Elektron Net node.
    $scriptBuilder = new BitwaspEscrowScriptBuilder(elektron_escrow_network());
    $escrowAddress = $scriptBuilder->build($buyerPubKey, $sellerPubKey, $orderNonceHex, $config->timeoutPolicy(), $fundedAt);

    $amountLep = (int) round(((float) $item['f_price']) * 100000000);

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
}

require osc_plugins_path() . 'osclass-escrow/views/checkout.php';
