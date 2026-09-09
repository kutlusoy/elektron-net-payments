<?php
/**
 * @var string $csrfToken
 * @var string|null $error
 * @var string $amount
 * @var string $currency
 * @var string[] $enabledFiatCurrencies
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terminal - Elektron Pay Server admin</title>
<link rel="stylesheet" href="/assets/admin/style.css">
</head>
<body class="terminal-body">
<div class="terminal-shell">
  <div class="terminal-topbar">
    <span class="terminal-topbar__brand">Elektron Pay Server &middot; Terminal</span>
    <a href="/admin/orders" class="terminal-topbar__exit">Exit to dashboard</a>
  </div>

  <?php if (!empty($error)): ?>
    <p class="login-error terminal-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>

  <form method="post" action="/admin/terminal" class="terminal-form" id="terminal-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="amount" id="amount-value" value="<?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="currency" id="currency-value" value="<?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>">

    <?php if (!empty($enabledFiatCurrencies)): ?>
    <div class="terminal-currency-tabs" id="terminal-currency-tabs">
      <button type="button" class="terminal-currency-tab <?php echo $currency === 'ELEK' ? 'is-active' : ''; ?>" data-currency="ELEK">ELEK</button>
      <?php foreach ($enabledFiatCurrencies as $code): ?>
        <button type="button" class="terminal-currency-tab <?php echo $currency === $code ? 'is-active' : ''; ?>" data-currency="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="terminal-display" id="terminal-display" aria-live="polite">
      <span id="terminal-amount">0</span><span class="terminal-display__unit" id="terminal-unit"><?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php if (!empty($enabledFiatCurrencies)): ?>
      <p class="form-hint terminal-convert-hint" id="terminal-convert-hint" hidden>Converted to ELEK live at the moment you tap "Receive payment" - the rate is then frozen onto the order.</p>
    <?php endif; ?>

    <div class="terminal-keypad">
      <button type="button" class="terminal-key" data-key="1">1</button>
      <button type="button" class="terminal-key" data-key="2">2</button>
      <button type="button" class="terminal-key" data-key="3">3</button>
      <button type="button" class="terminal-key" data-key="4">4</button>
      <button type="button" class="terminal-key" data-key="5">5</button>
      <button type="button" class="terminal-key" data-key="6">6</button>
      <button type="button" class="terminal-key" data-key="7">7</button>
      <button type="button" class="terminal-key" data-key="8">8</button>
      <button type="button" class="terminal-key" data-key="9">9</button>
      <button type="button" class="terminal-key" data-key=".">.</button>
      <button type="button" class="terminal-key" data-key="0">0</button>
      <button type="button" class="terminal-key terminal-key--clear" data-action="backspace">&larr;</button>
    </div>

    <button type="button" class="terminal-key terminal-key--wide" data-action="clear">Clear</button>
    <button type="submit" class="terminal-charge-btn" id="terminal-charge-btn" disabled>Receive payment</button>
  </form>
</div>

<script>
  (function () {
    var display = document.getElementById('terminal-amount');
    var hidden = document.getElementById('amount-value');
    var chargeBtn = document.getElementById('terminal-charge-btn');
    var unitLabel = document.getElementById('terminal-unit');
    var currencyHidden = document.getElementById('currency-value');
    var convertHint = document.getElementById('terminal-convert-hint');
    var value = hidden.value && hidden.value !== '0' ? hidden.value : '';

    function render() {
      display.textContent = value === '' ? '0' : value;
      hidden.value = value;
      chargeBtn.disabled = !(parseFloat(value) > 0);
    }

    document.querySelectorAll('.terminal-currency-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        document.querySelectorAll('.terminal-currency-tab').forEach(function (t) {
          t.classList.remove('is-active');
        });
        tab.classList.add('is-active');
        var code = tab.getAttribute('data-currency');
        currencyHidden.value = code;
        unitLabel.textContent = code;
        if (convertHint) {
          convertHint.hidden = code === 'ELEK';
        }
      });
    });

    document.querySelectorAll('.terminal-key[data-key]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-key');
        if (key === '.' && value.indexOf('.') !== -1) {
          return;
        }
        if (value === '0' && key !== '.') {
          value = key;
        } else {
          value += key;
        }
        render();
      });
    });

    document.querySelector('[data-action="backspace"]').addEventListener('click', function () {
      value = value.slice(0, -1);
      render();
    });
    document.querySelector('[data-action="clear"]').addEventListener('click', function () {
      value = '';
      render();
    });

    if (convertHint) {
      convertHint.hidden = currencyHidden.value === 'ELEK';
    }
    render();
  })();
</script>
</body>
</html>
