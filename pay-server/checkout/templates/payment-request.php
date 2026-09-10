<?php
/**
 * Section 16: "a thin 'confirm or enter an amount, then pay' screen."
 * Included by Http\Controllers\PaymentRequestController::page()/pay()
 * with $paymentRequest (Db\PaymentRequest), $merchant (Db\Merchant),
 * $error, and $amount (the value to prefill/redisplay) already resolved.
 * Reuses the checkout page's exact chrome (merchant header, order-card,
 * footer) rather than a separate visual language for what is, underneath,
 * the same product.
 *
 * @var \ElektronNet\Payments\PayServer\Db\PaymentRequest $paymentRequest
 * @var \ElektronNet\Payments\PayServer\Db\Merchant $merchant
 * @var string|null $error
 * @var string $amount
 */

use ElektronNet\Payments\PayServer\Bip21;

$title = htmlspecialchars($merchant->displayName, ENT_QUOTES, 'UTF-8') . ' - Payment request';
?>
<!doctype html>
<html lang="en">
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
    <?php if (!empty($paymentRequest->description)): ?>
      <p class="payment-request-description"><?php echo htmlspecialchars($paymentRequest->description, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <p class="payment-request-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form method="post" action="/pay/<?php echo urlencode($paymentRequest->id); ?>" class="payment-request-form">
      <?php if ($paymentRequest->isOpenAmount()): ?>
        <div class="field">
          <label for="amount-input">Amount (ELEK)</label>
          <input id="amount-input" name="amount" type="number" step="0.00000001" min="0.00000001" required
                 value="<?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?>" autofocus>
        </div>
      <?php else: ?>
        <p class="amount">
          <span><?php echo htmlspecialchars(Bip21::plainAmount($paymentRequest->amountLep), ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="amount-currency">ELEK</span>
        </p>
      <?php endif; ?>
      <button type="submit" class="tap-to-pay payment-request-submit">Continue to payment</button>
    </form>
  </main>

  <footer class="page-footer">
    <span>Powered by Elektron Net Payments</span>
  </footer>
</div>
</body>
</html>
