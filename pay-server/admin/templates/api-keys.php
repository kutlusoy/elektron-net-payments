<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\ApiKey[] $keys
 * @var string[] $validScopes
 * @var string|null $newRawKey
 * @var string $csrfToken
 */
?>
<?php if (!empty($newRawKey)): ?>
  <div class="new-key-banner">
    <p><strong>New API key created.</strong> Copy it now, it will not be shown again:</p>
    <code class="new-key-value"><?php echo htmlspecialchars($newRawKey, ENT_QUOTES, 'UTF-8'); ?></code>
  </div>
<?php endif; ?>

<?php if (empty($keys)): ?>
  <p class="empty-state">No API keys yet.</p>
<?php else: ?>
<table class="admin-table">
  <thead><tr><th>Label</th><th>Key</th><th>Scopes</th><th>Created</th><th>Last used</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($keys as $key): ?>
    <tr>
      <td><?php echo htmlspecialchars($key->label, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><code>pk_live_&hellip;<?php echo htmlspecialchars($key->keySuffix, ENT_QUOTES, 'UTF-8'); ?></code></td>
      <td><?php echo htmlspecialchars(implode(', ', $key->scopes), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($key->createdAt, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($key->lastUsedAt ?? 'never', ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo $key->isActive() ? '<span class="status-pill status-pill--new">active</span>' : '<span class="status-pill status-pill--expired">revoked</span>'; ?></td>
      <td>
        <?php if ($key->isActive()): ?>
        <form method="post" action="/admin/api-keys/<?php echo urlencode($key->id); ?>/revoke" onsubmit="return confirm('Revoke this key? Anything using it will stop working immediately.');">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <button type="submit" class="btn-danger">Revoke</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Create a new key</h2>
<form method="post" action="/admin/api-keys" class="create-key-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
  <label for="label">Label</label>
  <input id="label" name="label" type="text" required placeholder="e.g. Storefront plugin">
  <fieldset>
    <legend>Scopes</legend>
    <?php foreach ($validScopes as $scope): ?>
      <label class="scope-checkbox">
        <input type="checkbox" name="scopes[]" value="<?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($scope, ENT_QUOTES, 'UTF-8'); ?>
      </label>
    <?php endforeach; ?>
  </fieldset>
  <button type="submit">Create key</button>
</form>
