<?php
/**
 * @var string $csrfToken
 * @var string|null $error
 * @var string $amount
 * @var string $externalReference
 */
?>
<p><a href="/admin/orders">&larr; Back to orders</a></p>

<?php if (!empty($error)): ?>
  <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<form method="post" action="/admin/orders" class="create-key-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

  <label for="amount">Amount (ELEK)</label>
  <input id="amount" name="amount" type="text" inputmode="decimal" required autofocus
         placeholder="e.g. 2.5" value="<?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?>">

  <label for="external_reference">Your own order/item id (optional)</label>
  <input id="external_reference" name="external_reference" type="text"
         placeholder="e.g. order #4711" value="<?php echo htmlspecialchars($externalReference, ENT_QUOTES, 'UTF-8'); ?>">

  <button type="submit">Create charge</button>
</form>
<p class="form-hint">
  This creates a direct-mode order (section 5's <code>POST /v1/orders</code>, the same logic a storefront integration would call via its own API key) and takes you straight to its checkout page/QR code to hand to the buyer.
</p>
