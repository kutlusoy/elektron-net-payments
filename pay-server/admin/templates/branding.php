<?php
/**
 * @var \ElektronNet\Payments\PayServer\Db\Merchant $merchant
 * @var string $csrfToken
 * @var string|null $error
 * @var bool $justSaved
 */
?>
<p class="form-hint">
  Section 17: shown on the checkout page, the order-status view, and (once implemented) notification emails - never a hardcoded default.
</p>

<?php if ($justSaved): ?>
  <div class="new-key-banner"><p><strong>Branding saved.</strong></p></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
  <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<form method="post" action="/admin/branding" class="create-key-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

  <label for="display_name">Display name</label>
  <input id="display_name" name="display_name" type="text" maxlength="255"
         placeholder="<?php echo htmlspecialchars($merchant->name, ENT_QUOTES, 'UTF-8'); ?>"
         value="<?php echo htmlspecialchars($merchant->displayNameRaw ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Shown on the checkout page header. Falls back to "<?php echo htmlspecialchars($merchant->name, ENT_QUOTES, 'UTF-8'); ?>" (your account name) if left blank.</p>

  <label for="logo_url">Logo URL</label>
  <input id="logo_url" name="logo_url" type="text" maxlength="2048" placeholder="https://..."
         value="<?php echo htmlspecialchars($merchant->logoUrl ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Leave blank to show a plain initial mark instead. The checkout page handles no-logo, very wide, and very tall images.</p>

  <label for="theme_color">Theme color</label>
  <div class="color-field-row">
    <input id="theme_color_picker" type="color" class="color-swatch-input"
           value="<?php echo htmlspecialchars($merchant->themeColor ?? '#2f6fed', ENT_QUOTES, 'UTF-8'); ?>">
    <input id="theme_color" name="theme_color" type="text" maxlength="7" placeholder="#1a1a1a"
           value="<?php echo htmlspecialchars($merchant->themeColor ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  </div>
  <div class="theme-presets" id="theme-presets">
    <button type="button" class="theme-preset" data-color="#1a1a1a" style="--swatch:#1a1a1a">Charcoal (Dark)</button>
    <button type="button" class="theme-preset" data-color="#334155" style="--swatch:#334155">Slate (Modern)</button>
    <button type="button" class="theme-preset" data-color="#2f6fed" style="--swatch:#2f6fed">Ocean Blue</button>
    <button type="button" class="theme-preset" data-color="#0a7d3a" style="--swatch:#0a7d3a">Forest Green</button>
    <button type="button" class="theme-preset" data-color="#0f766e" style="--swatch:#0f766e">Teal</button>
    <button type="button" class="theme-preset" data-color="#7c3aed" style="--swatch:#7c3aed">Royal Purple</button>
    <button type="button" class="theme-preset" data-color="#be123c" style="--swatch:#be123c">Rose</button>
    <button type="button" class="theme-preset" data-color="#c2410c" style="--swatch:#c2410c">Sunset Orange</button>
  </div>
  <p class="form-hint form-hint--tight">6-digit hex. Applies to buttons and accents only, never to text-on-background - a minimum 3:1 contrast against white is enforced server-side (WCAG 1.4.11), so every preset above already clears it and a picked/typed color still gets checked again on save.</p>

  <label for="checkout_subdomain">Checkout subdomain</label>
  <input id="checkout_subdomain" name="checkout_subdomain" type="text" maxlength="63" placeholder="acme"
         value="<?php echo htmlspecialchars($merchant->checkoutSubdomain ?? '', ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Format-validated and stored only for now - subdomain routing (<code>&lt;subdomain&gt;.pay.elektron-net.org</code>) is a separate, not-yet-built infrastructure piece (see the full implementation plan).</p>

  <button type="submit">Save branding</button>
</form>

<script>
  (function () {
    var textInput = document.getElementById('theme_color');
    var pickerInput = document.getElementById('theme_color_picker');

    function isValidHex(value) {
      return /^#[0-9a-fA-F]{6}$/.test(value);
    }

    textInput.addEventListener('input', function () {
      if (isValidHex(textInput.value)) {
        pickerInput.value = textInput.value;
      }
    });
    pickerInput.addEventListener('input', function () {
      textInput.value = pickerInput.value;
    });

    document.querySelectorAll('.theme-preset').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var color = btn.getAttribute('data-color');
        textInput.value = color;
        pickerInput.value = color;
      });
    });
  })();
</script>
