<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\Order $order
 * @var array<int, array{type: string, payload: array<string, mixed>, created_at: string}> $events
 * @var \ElektronNet\Payments\PayServer\Db\OrderMessage[] $messages
 * @var string|null $messageError
 * @var int|null $overpaymentLep
 * @var bool $isMarkedRefunded
 * @var string $paymentUri
 * @var string $csrfToken
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
  <dt>Refund address</dt><dd><?php echo $order->refundAddress !== null ? '<code>' . htmlspecialchars($order->refundAddress, ENT_QUOTES, 'UTF-8') . '</code>' : '<span class="empty-state">Not provided by buyer</span>'; ?></dd>
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

<?php if ($overpaymentLep !== null): ?>
<div class="wallet-warning" id="refund">
  This order was overpaid by <?php echo htmlspecialchars(Bip21::plainAmount($overpaymentLep), ENT_QUOTES, 'UTF-8'); ?> ELEK. Section 15's refund flow is the natural next step - see the refund address below, if the buyer provided one.
</div>
<?php endif; ?>

<div class="admin-sub-row">
  <h2>Refund</h2>
</div>
<?php if ($isMarkedRefunded): ?>
  <p class="empty-state">Marked as refunded (self-reported - not verified on-chain by this server).</p>
<?php else: ?>
  <p class="form-hint form-hint--tight">Section 15: this server is non-custodial and never sends funds itself. Once you have sent a refund from your own wallet to the address above, self-report it here so the order record reflects reality - this is not verified on-chain.</p>
  <form method="post" action="/admin/orders/<?php echo urlencode($order->id); ?>/mark-refunded">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn-danger">Mark as refunded (self-reported)</button>
  </form>
<?php endif; ?>

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

<div class="admin-sub-row" id="messages">
  <h2>Messages</h2>
  <button type="button" class="btn-link" onclick="window.print()">Print / export log</button>
</div>
<p class="form-hint form-hint--tight print-hidden">Section 11: an append-only, timestamped record both sides can see - the buyer sees this exact thread on their own order-status page. Never a live chat feature; nothing here can be edited or removed once sent.</p>

<?php if (empty($messages)): ?>
  <p class="empty-state">No messages yet.</p>
<?php else: ?>
<ul class="admin-message-list">
  <?php foreach ($messages as $message): ?>
    <li class="admin-message admin-message--<?php echo htmlspecialchars($message->sender, ENT_QUOTES, 'UTF-8'); ?>">
      <span class="admin-message-meta"><?php echo $message->sender === 'merchant' ? 'You' : 'Buyer'; ?> &middot; <?php echo htmlspecialchars($message->createdAt, ENT_QUOTES, 'UTF-8'); ?></span>
      <p class="admin-message-body"><?php echo nl2br(htmlspecialchars($message->body, ENT_QUOTES, 'UTF-8')); ?></p>
    </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if (!empty($messageError)): ?>
  <p class="login-error print-hidden"><?php echo htmlspecialchars($messageError, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<form method="post" action="/admin/orders/<?php echo urlencode($order->id); ?>/messages" class="admin-message-form print-hidden">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
  <textarea name="body" rows="2" maxlength="2000" placeholder="Write a message to the buyer&hellip;" required></textarea>
  <button type="submit">Send</button>
</form>
