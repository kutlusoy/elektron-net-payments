<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $order */
/** @var array $item */

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$amountElek = $order['amount_lep'] / 100000000;

// Encodes the bare address, not a payment URI: no BIP21-style URI scheme
// for Elektron Net is documented anywhere in this project yet. Once one
// exists, encode that instead so wallets can prefill the amount.
$qrCode = new QrCode($order['s_address']);
$qrPngBase64 = base64_encode((new PngWriter())->write($qrCode)->getString());
?>

<div class="elektron-escrow-checkout">
    <h1><?php echo osc_esc_html($item['s_title']); ?></h1>

    <p><?php echo osc_esc_html(elektron_escrow_t('order.awaiting_payment', ['{amount}' => $amountElek])); ?></p>

    <img src="data:image/png;base64,<?php echo $qrPngBase64; ?>" alt="<?php echo osc_esc_html($order['s_address']); ?>" />

    <p class="elektron-escrow-address"><code><?php echo osc_esc_html($order['s_address']); ?></code></p>

    <?php
    // 'funded' has a buyer/seller-specific variant in the catalog; this
    // view is always the buyer's own, so it always resolves to '.buyer'.
    $statusKey = $order['status'] === 'funded' ? 'order.funded.buyer' : 'order.' . $order['status'];
    ?>
    <p class="elektron-escrow-status" data-status="<?php echo osc_esc_html($order['status']); ?>">
        <?php echo osc_esc_html(elektron_escrow_t($statusKey)); ?>
    </p>

    <a href="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
        <?php _e('Go to this order\'s status page', ELEKTRON_ESCROW_DOMAIN); ?>
    </a>
</div>
