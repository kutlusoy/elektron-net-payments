<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\PaymentRequest $paymentRequest
 * @var \ElektronNet\Payments\PayServer\Db\Order[] $orders
 * @var string $csrfToken
 */
use ElektronNet\Payments\PayServer\Bip21;
?>
<p><a href="/admin/payment-requests">&larr; Back to payment requests</a></p>

<dl class="detail-grid detail-grid--spaced">
  <dt>Payment request id</dt><dd><code><?php echo htmlspecialchars($paymentRequest->id, ENT_QUOTES, 'UTF-8'); ?></code></dd>
  <dt>Amount</dt><dd><?php echo $paymentRequest->isOpenAmount() ? 'Open - buyer enters their own amount' : htmlspecialchars(Bip21::plainAmount($paymentRequest->amountLep), ENT_QUOTES, 'UTF-8') . ' ELEK'; ?></dd>
  <dt>Description</dt><dd><?php echo htmlspecialchars($paymentRequest->description ?? '-', ENT_QUOTES, 'UTF-8'); ?></dd>
  <dt>Expires at</dt><dd><?php echo htmlspecialchars($paymentRequest->expiresAt ?? 'Never', ENT_QUOTES, 'UTF-8'); ?></dd>
  <dt>Created at</dt><dd><?php echo htmlspecialchars($paymentRequest->createdAt, ENT_QUOTES, 'UTF-8'); ?></dd>
  <dt>Status</dt>
  <dd>
    <?php if ($paymentRequest->isArchived()): ?>
      <span class="status-pill status-pill--expired">archived</span>
    <?php elseif ($paymentRequest->isExpired()): ?>
      <span class="status-pill status-pill--expired">expired</span>
    <?php else: ?>
      <span class="status-pill status-pill--new">active</span>
    <?php endif; ?>
  </dd>
  <dt>Public link</dt><dd><a href="/pay/<?php echo urlencode($paymentRequest->id); ?>" target="_blank" rel="noopener">/pay/<?php echo htmlspecialchars($paymentRequest->id, ENT_QUOTES, 'UTF-8'); ?></a></dd>
</dl>

<?php if (!$paymentRequest->isArchived()): ?>
<form method="post" action="/admin/payment-requests/<?php echo urlencode($paymentRequest->id); ?>/archive">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
  <button type="submit" class="btn-danger">Archive this request</button>
</form>
<p class="form-hint form-hint--tight">Archiving stops new payments through this link; orders already spawned from it are unaffected.</p>
<?php endif; ?>

<h2>Orders spawned from this request</h2>
<?php if (empty($orders)): ?>
  <p class="empty-state">No payments through this link yet.</p>
<?php else: ?>
<table class="admin-table">
  <thead><tr><th>Created</th><th>Status</th><th>Amount</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($orders as $order): ?>
    <tr>
      <td><?php echo htmlspecialchars($order->createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><span class="status-pill status-pill--<?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?></span></td>
      <td><?php echo htmlspecialchars(Bip21::plainAmount($order->amountLep), ENT_QUOTES, 'UTF-8'); ?> ELEK</td>
      <td><a href="/admin/orders/<?php echo urlencode($order->id); ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
