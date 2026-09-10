<?php
/**
 * @var string $csrfToken
 * @var string|null $error
 * @var string $amount
 * @var string $description
 * @var string $expiresAt
 */
?>
<p><a href="/admin/payment-requests">&larr; Back to payment requests</a></p>

<?php if (!empty($error)): ?>
  <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<form method="post" action="/admin/payment-requests" class="create-key-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

  <label for="amount">Fixed amount (ELEK, optional)</label>
  <input id="amount" name="amount" type="text" inputmode="decimal"
         placeholder="Leave blank for the buyer to enter their own amount"
         value="<?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?>">

  <label for="description">Description (optional)</label>
  <input id="description" name="description" type="text" maxlength="500"
         placeholder="e.g. Support our project" value="<?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?>">

  <label for="expires_at">Expires at (optional)</label>
  <input id="expires_at" name="expires_at" type="datetime-local"
         value="<?php echo htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Leave blank for a link that never expires.</p>

  <button type="submit">Create payment request</button>
</form>
