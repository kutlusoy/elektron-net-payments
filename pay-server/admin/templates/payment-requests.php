<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\PaymentRequest[] $requests
 */
use ElektronNet\Payments\PayServer\Bip21;
?>
<p class="admin-sub-row">
  <span><?php echo count($requests); ?> payment request<?php echo count($requests) === 1 ? '' : 's'; ?></span>
  <a class="btn-link" href="/admin/payment-requests/new">+ New payment request</a>
</p>
<p class="form-hint">
  Section 16: a reusable link (donation button, "pay what you owe", tip jar) - every payment through it still spawns its own fresh order with its own never-reused address; the request itself never carries one.
</p>

<?php if (empty($requests)): ?>
  <p class="empty-state">No payment requests yet.</p>
<?php else: ?>
<table class="admin-table">
  <thead>
    <tr>
      <th>Created</th>
      <th>Amount</th>
      <th>Description</th>
      <th>Status</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($requests as $paymentRequest): ?>
    <tr>
      <td><?php echo htmlspecialchars($paymentRequest->createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo $paymentRequest->isOpenAmount() ? 'Open amount' : htmlspecialchars(Bip21::plainAmount($paymentRequest->amountLep), ENT_QUOTES, 'UTF-8') . ' ELEK'; ?></td>
      <td><?php echo htmlspecialchars($paymentRequest->description ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
      <td>
        <?php if ($paymentRequest->isArchived()): ?>
          <span class="status-pill status-pill--expired">archived</span>
        <?php elseif ($paymentRequest->isExpired()): ?>
          <span class="status-pill status-pill--expired">expired</span>
        <?php else: ?>
          <span class="status-pill status-pill--new">active</span>
        <?php endif; ?>
      </td>
      <td><a href="/admin/payment-requests/<?php echo urlencode($paymentRequest->id); ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
