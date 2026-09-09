<?php
/**
 * @var string $csrfToken
 * @var string|null $error
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in - Elektron Pay Server admin</title>
<link rel="stylesheet" href="/assets/admin/style.css">
</head>
<body class="login-body">
<form class="login-card" method="post" action="/admin/login">
  <h1>Elektron Pay Server</h1>
  <p class="login-sub">Merchant admin</p>
  <?php if (!empty($error)): ?>
    <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
  <label for="email">Email</label>
  <input id="email" name="email" type="email" required autofocus>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" required>
  <button type="submit">Log in</button>
</form>
</body>
</html>
