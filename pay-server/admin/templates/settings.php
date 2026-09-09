<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\Merchant $merchant
 * @var string $csrfToken
 * @var string|null $error
 * @var bool $justSaved
 * @var bool $priceFeedConfigured
 */
?>
<?php if ($justSaved): ?>
  <div class="new-key-banner"><p><strong>Settings saved.</strong></p></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
  <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<form method="post" action="/admin/settings" class="create-key-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

  <label>Base currency</label>
  <input type="text" value="<?php echo htmlspecialchars($merchant->baseCurrency, ENT_QUOTES, 'UTF-8'); ?>" disabled>
  <p class="form-hint form-hint--tight">Section 12: locked to ELEK until a real price feed is configured server-wide (none is, yet - ELEK is not currently listed on any exchange).</p>

  <label for="order_expiry_minutes">Order expiry (minutes)</label>
  <input id="order_expiry_minutes" name="order_expiry_minutes" type="number" min="1" max="10080" required
         value="<?php echo (int) $merchant->orderExpiryMinutes; ?>">

  <label for="default_required_confirmations">Required confirmations</label>
  <input id="default_required_confirmations" name="default_required_confirmations" type="number" min="0" max="100" required
         value="<?php echo (int) $merchant->defaultRequiredConfirmations; ?>">
  <?php if ($merchant->defaultRequiredConfirmations < 1): ?>
    <div class="wallet-warning">Currently set to <?php echo (int) $merchant->defaultRequiredConfirmations; ?>: a payment settles on sight in the mempool, before any confirmation - fastest, but exposed to a double-spend.</div>
  <?php else: ?>
    <p class="form-hint form-hint--tight">Setting this to 0 accepts a payment as settled on sight in the mempool - fastest, but exposed to a double-spend.</p>
  <?php endif; ?>

  <label for="underpayment_tolerance_percent">Underpayment tolerance (%)</label>
  <input id="underpayment_tolerance_percent" name="underpayment_tolerance_percent" type="number" min="0" max="100" step="0.01" required
         value="<?php echo htmlspecialchars($merchant->underpaymentTolerancePercent, ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">A payment landing up to this percent short of the requested amount still settles the order (e.g. a buyer's wallet slightly under-estimated its own fee). 0 disables this.</p>

  <?php if ($priceFeedConfigured): ?>
  <label for="default_display_currency">Optional fiat display currency</label>
  <input id="default_display_currency" name="default_display_currency" type="text" maxlength="3" placeholder="USD"
         value="<?php echo htmlspecialchars($merchant->defaultDisplayCurrency ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Purely informational "&asymp; X USD" line under the ELEK amount on the checkout page. Never authoritative, never affects the actual charge.</p>
  <?php else: ?>
  <p class="form-hint">Optional fiat display currency: hidden until a price feed is configured server-wide (section 12) - nothing to set yet.</p>
  <?php endif; ?>

  <label for="success_url">Success redirect URL</label>
  <input id="success_url" name="success_url" type="text" maxlength="2048" placeholder="https://your-shop.example/thank-you"
         value="<?php echo htmlspecialchars($merchant->successUrl ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <label for="cancel_url">Cancel redirect URL</label>
  <input id="cancel_url" name="cancel_url" type="text" maxlength="2048" placeholder="https://your-shop.example/cart"
         value="<?php echo htmlspecialchars($merchant->cancelUrl ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">https:// only. The checkout page sends the buyer here a few seconds after an order settles / expires or fails, when set. Leave blank to stay on the checkout page instead.</p>

  <button type="submit">Save settings</button>
</form>
