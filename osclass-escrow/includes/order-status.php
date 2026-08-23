<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Renders one order's permanent details page directly into the current
 * response. Shared by controllers/order-status.php (this order's own route)
 * and controllers/checkout.php, which renders this same view in place right
 * after creating an order or finding an existing one, rather than
 * redirecting there -- see includes/notice.php's docblock for why a
 * same-request render, not a redirect, is what actually works from within
 * one of this plugin's custom-route controllers on the real, currently
 * deployed platform.
 */
function elektron_escrow_render_order_status(array $order, int $userId, ?string $statusMessage = null, ?string $errorMessage = null): void
{
    $isBuyer = $userId === (int) $order['fk_i_buyer_id'];

    // Not re-derived from the item's *current* price: amount_lep was already
    // frozen on the order at checkout time (see controllers/checkout.php),
    // and an item's price can change after an order exists.
    $item = Item::newInstance()->findByPrimaryKey((int) $order['fk_i_item_id']);
    $amountElek = ((int) $order['amount_lep']) / 100000000;
    $requiredConfirmations = (int) osc_get_preference('required_confirmations', 'plugin-osclass-escrow');

    require osc_plugins_path() . 'osclass-escrow/views/order-status.php';
}
