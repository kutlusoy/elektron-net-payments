<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $item Osclass item row */
/** @var array|null $order this buyer's existing EscrowOrderDAO row for this item, if any */

// Escrow only ever applies to listings priced directly in ELEK: converting
// a fiat-priced listing would need a live exchange rate, and no reliable
// ELEK price feed exists yet (see elektron-net-mempool's README). The
// marketplace operator adds ELEK as a currency in Osclass's own currency
// settings; sellers who want escrow price their listing in it directly.
if (($item['fk_c_currency_code'] ?? '') !== 'ELEK') {
    return;
}
if (elektron_escrow_get_user_pubkey((int) $item['fk_i_user_id']) === null) {
    return; // seller has not connected a wallet yet, nothing to pay into
}
?>

<div class="elektron-escrow-widget">
    <?php if ($order === null) { ?>
        <a class="btn btn-primary" href="<?php echo osc_route_url('elektron_escrow_checkout', ['item' => $item['pk_i_id']]); ?>">
            <?php _e('Buy with Elektron (ELEK)', ELEKTRON_ESCROW_DOMAIN); ?>
        </a>
    <?php } else { ?>
        <a class="btn btn-default" href="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
            <?php _e('View your Elektron order', ELEKTRON_ESCROW_DOMAIN); ?>
        </a>
    <?php } ?>
</div>
