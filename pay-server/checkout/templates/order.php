<?php
/**
 * Checkout / order-status page (section 10, section 19: single-page view
 * per order). Included by Http\Controllers\CheckoutController::page()
 * with $order (Db\Order), $merchant (Db\Merchant), $paymentUri, and
 * $amountDisplay already resolved -- kept as a plain PHP template,
 * matching this repository's existing convention
 * (osclass-escrow/views/*.php) rather than adding a templating engine
 * dependency.
 *
 * @var \ElektronNet\Payments\PayServer\Db\Order $order
 * @var \ElektronNet\Payments\PayServer\Db\Merchant $merchant
 * @var string $paymentUri
 * @var string $amountDisplay
 * @var array{currency: string, amount: string}|null $fiatDisplay
 */

$statusLabels = [
    'new' => 'Waiting for payment',
    'processing' => 'Payment detected, confirming',
    'settled' => 'Paid',
    'expired' => 'Expired',
    'invalid' => 'Payment problem',
];
$title = htmlspecialchars($merchant->displayName, ENT_QUOTES, 'UTF-8') . ' - Order';
?>
<!doctype html>
<html lang="en" data-status="<?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $title; ?></title>
<link rel="stylesheet" href="/assets/checkout/style.css">
<?php if ($merchant->themeColor !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $merchant->themeColor)): ?>
<style>:root { --brand-color: <?php echo htmlspecialchars($merchant->themeColor, ENT_QUOTES, 'UTF-8'); ?>; }</style>
<?php endif; ?>
</head>
<body>
<div class="page">
  <header class="merchant-header">
    <?php if ($merchant->logoUrl !== null && $merchant->logoUrl !== ''): ?>
      <img class="merchant-logo" src="<?php echo htmlspecialchars($merchant->logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($merchant->displayName, ENT_QUOTES, 'UTF-8'); ?>">
    <?php else: ?>
      <div class="merchant-logo merchant-logo--placeholder" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($merchant->displayName, 0, 1), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <span class="merchant-name"><?php echo htmlspecialchars($merchant->displayName, ENT_QUOTES, 'UTF-8'); ?></span>
  </header>

  <main class="order-card">
    <p class="status-badge" id="status-badge" data-status="<?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?>">
      <?php echo htmlspecialchars($statusLabels[$order->status] ?? $order->status, ENT_QUOTES, 'UTF-8'); ?>
    </p>

    <p class="amount">
      <span id="amount-value"><?php echo htmlspecialchars($amountDisplay, ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="amount-currency">ELEK</span>
    </p>
    <?php if ($fiatDisplay !== null): ?>
      <!-- Section 12: purely informational, never authoritative, never
           what the buyer is expected to pay exactly -- amount_lep (and so
           the QR/address above) is unaffected by this readout. -->
      <p class="amount-fiat">
        &asymp; <?php echo htmlspecialchars($fiatDisplay['amount'], ENT_QUOTES, 'UTF-8'); ?>
        <?php echo htmlspecialchars($fiatDisplay['currency'], ENT_QUOTES, 'UTF-8'); ?>
        <span class="amount-fiat-note">(approx.)</span>
      </p>
    <?php endif; ?>

    <div class="pay-area" id="pay-area">
      <div class="qr-wrap" id="qr-wrap">
        <div id="qrcode" aria-label="Scan with your Elektron Net wallet"></div>
      </div>
      <a class="tap-to-pay" id="tap-to-pay" href="<?php echo htmlspecialchars($paymentUri, ENT_QUOTES, 'UTF-8'); ?>">
        Open in wallet
      </a>
    </div>

    <div class="field">
      <label for="address-field">Payment address</label>
      <div class="copy-row">
        <input id="address-field" type="text" readonly value="<?php echo htmlspecialchars($order->address, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="copy-btn" data-copy-target="address-field">Copy</button>
      </div>
    </div>

    <div class="field">
      <label for="uri-field">Payment URI (tap to select, then copy)</label>
      <div class="copy-row">
        <input id="uri-field" type="text" readonly value="<?php echo htmlspecialchars($paymentUri, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="copy-btn" data-copy-target="uri-field">Copy</button>
      </div>
    </div>

    <p class="countdown" id="countdown" data-expires-at="<?php echo htmlspecialchars($order->expiresAt, ENT_QUOTES, 'UTF-8'); ?>"></p>

    <p class="order-id">Order <?php echo htmlspecialchars($order->id, ENT_QUOTES, 'UTF-8'); ?></p>
  </main>

  <footer class="page-footer">
    <span>Powered by Elektron Net Payments</span>
  </footer>
</div>

<script src="/assets/checkout/vendor/qrcode.js"></script>
<script>
  window.ORDER_ID = <?php echo json_encode($order->id); ?>;
  window.ORDER_STATUS = <?php echo json_encode($order->status); ?>;
  window.PAYMENT_URI = <?php echo json_encode($paymentUri); ?>;
</script>
<script src="/assets/checkout/checkout.js"></script>
</body>
</html>
