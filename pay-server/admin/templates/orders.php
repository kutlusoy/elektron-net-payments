<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\Order[] $orders
 * @var int $total
 */
use ElektronNet\Payments\PayServer\Bip21;
?>
<p class="admin-sub-row">
  <span><?php echo (int) $total; ?> order<?php echo $total === 1 ? '' : 's'; ?> total</span>
  <a class="btn-link" href="/admin/orders/new">+ New order</a>
</p>

<?php if (empty($orders)): ?>
  <p class="empty-state">No orders yet. Create one above, or via <code>POST /v1/orders</code> from a storefront integration.</p>
<?php else: ?>
<table class="admin-table">
  <thead>
    <tr>
      <th>Created</th>
      <th>Status</th>
      <th>Mode</th>
      <th>Amount</th>
      <th>External ref</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($orders as $order): ?>
    <tr>
      <td><?php echo htmlspecialchars($order->createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><span class="status-pill status-pill--<?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?></span></td>
      <td><?php echo htmlspecialchars($order->mode, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars(Bip21::plainAmount($order->amountLep), ENT_QUOTES, 'UTF-8'); ?> ELEK</td>
      <td><?php echo htmlspecialchars($order->externalReference ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
      <td><a href="/admin/orders/<?php echo urlencode($order->id); ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
