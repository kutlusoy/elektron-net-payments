<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\Merchant $merchant
 * @var string|null $previewAddress
 * @var string $csrfToken
 * @var string|null $error
 * @var bool $justSaved
 */
$hasXpub = $merchant->receivingXpub !== null && $merchant->receivingXpub !== '';
?>
<p class="form-hint">
  Section 8: a direct-mode order needs a fresh receiving address, derived from your own wallet's extended public key (xpub). Only ever a public key - the private key never leaves your own wallet software, and this server never generates or holds one itself.
</p>

<?php if (!$hasXpub): ?>
  <div class="wallet-warning">
    <strong>No wallet connected yet.</strong> Orders cannot be created until you connect one below - <code>POST /v1/orders</code> and the admin "New order" form will both fail with <code>receiving_wallet_not_connected</code> until then.
  </div>
<?php endif; ?>

<?php if ($justSaved): ?>
  <div class="new-key-banner">
    <p><strong>Wallet connected.</strong> New orders will now derive their receiving address from this xpub.</p>
  </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
  <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php if ($hasXpub): ?>
<dl class="detail-grid detail-grid--spaced">
  <dt>Connected xpub</dt>
  <dd><code><?php echo htmlspecialchars($merchant->receivingXpub, ENT_QUOTES, 'UTF-8'); ?></code></dd>
  <dt>Preview address</dt>
  <dd>
    <?php if ($previewAddress !== null): ?>
      <code><?php echo htmlspecialchars($previewAddress, ENT_QUOTES, 'UTF-8'); ?></code>
      <p class="form-hint form-hint--tight">This is the first address this xpub would derive. Check it against your own wallet software before trusting it for real orders.</p>
    <?php else: ?>
      <span class="empty-state">Could not derive a preview address from this key.</span>
    <?php endif; ?>
  </dd>
</dl>
<?php endif; ?>

<form method="post" action="/admin/wallet" class="create-key-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
  <label for="xpub"><?php echo $hasXpub ? 'Replace wallet (paste a new xpub)' : 'Connect your wallet (paste an xpub)'; ?></label>
  <input id="xpub" name="xpub" type="text" required placeholder="xpub6... / ypub... / zpub...">
  <button type="submit"><?php echo $hasXpub ? 'Save new wallet' : 'Connect wallet'; ?></button>
</form>
<p class="form-hint">
  Accepts a plain <code>xpub</code> or the SLIP-132 <code>ypub</code>/<code>zpub</code> variants some wallets show instead - cryptographically the same key either way. Never asks which SLIP-44 coin type (legacy <code>0'</code> or Elektron Net's own <code>1370'</code>) derived it: both are equally valid and this server has no reason to know or care which one your wallet used.
  <?php if ($hasXpub): ?>Saving a new key only affects orders created afterward; orders already created keep the address they were given.<?php endif; ?>
</p>

<details class="help-details">
  <summary>How do I get my xpub?</summary>

  <p class="form-hint">Every wallet is different, but the shape is always the same: your wallet already holds the private key, and you are asking it to show you the corresponding <em>public</em> key. Never a seed phrase, never a private key - only the wallet itself should ever need those.</p>

  <p class="form-hint"><strong>Elektron Electrum Wallet</strong> ships a <code>getmpk</code> command for exactly this. Same command in any shell, since it is just running a program:</p>

  <pre class="code-block">$ electrum getmpk -w /path/to/your/wallet
xpub6D4BDPcP2GT577Vvch3R8wDkScZWzQzMMUm3PWbmWvVJrZwQY4VUNgqFJPMM3No2dFDFGTsxxpG5uJh7n7epu4trkrX7x7DogT5Uv6fcLW5</pre>
  <p class="form-hint form-hint--tight">bash / zsh / macOS Terminal - the <code>$</code> is just the prompt, not something you type.</p>

  <pre class="code-block">PS C:\Users\you&gt; electrum.exe getmpk -w C:\Users\you\AppData\Roaming\Electrum\wallets\default_wallet</pre>
  <p class="form-hint form-hint--tight">Windows PowerShell - identical command, just how Windows names the same program.</p>

  <pre class="code-block">$ ./run_electrum getmpk -w /path/to/your/wallet</pre>
  <p class="form-hint form-hint--tight">Running from a source checkout instead of an installed copy (see the wallet's own <code>README.md</code>, "Running from source").</p>

  <p class="form-hint">No CLI installed, or using a different wallet entirely? Every BIP32 wallet has a "Master Public Key" / "Extended Public Key" / "xpub" view somewhere in its own UI - in Elektron Electrum's graphical app it is under <strong>Wallet &rarr; Information</strong>. Look there instead of running anything on the command line.</p>
</details>
