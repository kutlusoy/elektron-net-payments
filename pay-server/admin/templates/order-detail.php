<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\Order $order
 * @var array<int, array{type: string, payload: array<string, mixed>, created_at: string}> $events
 * @var string $paymentUri
 */
use ElektronNet\Payments\PayServer\Bip21;
?>
<p><a href="/admin/orders">&larr; Back to orders</a></p>

<div class="order-detail-layout">
<dl class="detail-grid">
  <dt>Order id</dt><dd><code><?php echo htmlspecialchars($order->id, ENT_QUOTES, 'UTF-8'); ?></code></dd>
  <dt>Status</dt><dd><span class="status-pill status-pill--<?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($order->status, ENT_QUOTES, 'UTF-8'); ?></span></dd>
  <dt>Mode</dt><dd><?php echo htmlspecialchars($order->mode, ENT_QUOTES, 'UTF-8'); ?></dd>
  <dt>Amount</dt><dd><?php echo htmlspecialchars(Bip21::plainAmount($order->amountLep), ENT_QUOTES, 'UTF-8'); ?> ELEK</dd>
  <?php if ($order->fiatCurrency !== null): ?>
  <dt>Charged as</dt><dd><?php echo htmlspecialchars($order->fiatAmount, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($order->fiatCurrency, ENT_QUOTES, 'UTF-8'); ?> (rate frozen at creation: 1 ELEK = <?php echo htmlspecialchars($order->exchangeRateUsed, ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($order->fiatCurrency, ENT_QUOTES, 'UTF-8'); ?>)</dd>
  <?php endif; ?>
  <dt>Address</dt><dd><code><?php echo htmlspecialchars($order->address, ENT_QUOTES, 'UTF-8'); ?></code></dd>
  <dt>External reference</dt><dd><?php echo htmlspecialchars($order->externalReference ?? '-', ENT_QUOTES, 'UTF-8'); ?></dd>
  <dt>Expires at</dt><dd><?php echo htmlspecialchars($order->expiresAt, ENT_QUOTES, 'UTF-8'); ?></dd>
  <dt>Required confirmations</dt><dd><?php echo (int) $order->requiredConfirmations; ?></dd>
  <dt>Created at</dt><dd><?php echo htmlspecialchars($order->createdAt, ENT_QUOTES, 'UTF-8'); ?></dd>
  <dt>Checkout link</dt><dd><a href="/order/<?php echo urlencode($order->id); ?>">/order/<?php echo htmlspecialchars($order->id, ENT_QUOTES, 'UTF-8'); ?></a></dd>
</dl>

<?php if (in_array($order->status, ['new', 'processing'], true)): ?>
<div class="show-to-customer">
  <p class="form-hint">Show this to the buyer, or open the full checkout page on another screen:</p>
  <div id="admin-qrcode"></div>
  <a class="btn-link" href="/order/<?php echo urlencode($order->id); ?>" target="_blank" rel="noopener">Open checkout page &rarr;</a>
</div>
<script src="/assets/checkout/vendor/qrcode.js"></script>
<script>
  (function () {
    var qr = qrcode(0, 'M');
    qr.addData(<?php echo json_encode($paymentUri); ?>);
    qr.make();
    document.getElementById('admin-qrcode').innerHTML = qr.createSvgTag({ scalable: true, margin: 2 });
  })();
</script>
<?php endif; ?>
</div>

<h2>Event log</h2>
<?php if (empty($events)): ?>
  <p class="empty-state">No events recorded yet.</p>
<?php else: ?>
<table class="admin-table">
  <thead><tr><th>Time</th><th>Type</th><th>Payload</th></tr></thead>
  <tbody>
  <?php foreach ($events as $event): ?>
    <tr>
      <td><?php echo htmlspecialchars($event['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($event['type'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><code><?php echo htmlspecialchars(json_encode($event['payload']), ENT_QUOTES, 'UTF-8'); ?></code></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
