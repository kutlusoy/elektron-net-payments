<?php
/**
 * @var string $title
 * @var string $active
 * @var string $content raw, already-rendered HTML of the page body
 * @var string $merchantName
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> - Elektron Pay Server admin</title>
<link rel="stylesheet" href="/assets/admin/style.css">
</head>
<body>
<div class="admin-shell">
  <aside class="admin-nav">
    <div class="admin-nav__brand">Elektron Pay Server</div>
    <nav>
      <a href="/admin/orders" class="<?php echo $active === 'orders' ? 'is-active' : ''; ?>">Orders</a>
      <a href="/admin/terminal">Terminal</a>
      <a href="/admin/payment-requests" class="<?php echo $active === 'payment-requests' ? 'is-active' : ''; ?>">Payment requests</a>
      <a href="/admin/wallet" class="<?php echo $active === 'wallet' ? 'is-active' : ''; ?>">Wallet</a>
      <a href="/admin/branding" class="<?php echo $active === 'branding' ? 'is-active' : ''; ?>">Branding</a>
      <a href="/admin/settings" class="<?php echo $active === 'settings' ? 'is-active' : ''; ?>">Settings</a>
      <a href="/admin/api-keys" class="<?php echo $active === 'api-keys' ? 'is-active' : ''; ?>">API keys</a>
      <?php if (!empty($_SESSION['is_platform_admin'])): ?>
        <a href="/admin/platform/price-feed" class="admin-nav__platform-link <?php echo $active === 'platform-price-feed' ? 'is-active' : ''; ?>">Platform: Price feed</a>
      <?php endif; ?>
    </nav>
    <form method="post" action="/admin/logout" class="admin-nav__logout">
      <span class="admin-nav__merchant"><?php echo htmlspecialchars($merchantName, ENT_QUOTES, 'UTF-8'); ?></span>
      <button type="submit">Log out</button>
    </form>
  </aside>
  <main class="admin-content">
    <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php echo $content; ?>
  </main>
</div>
</body>
</html>
