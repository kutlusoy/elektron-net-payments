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
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> - pay-server admin</title>
<link rel="stylesheet" href="/assets/admin/style.css">
</head>
<body>
<div class="admin-shell">
  <aside class="admin-nav">
    <div class="admin-nav__brand">pay-server</div>
    <nav>
      <a href="/admin/orders" class="<?php echo $active === 'orders' ? 'is-active' : ''; ?>">Orders</a>
      <a href="/admin/api-keys" class="<?php echo $active === 'api-keys' ? 'is-active' : ''; ?>">API keys</a>
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
