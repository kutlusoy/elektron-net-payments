<?php
/**
 * @var array{enabled: bool, endpoints: array<int, array<string, mixed>>, advanced_json?: string} $current
 * @var bool $usingEnvDefault
 * @var string $csrfToken
 * @var string|null $error
 * @var bool $justSaved
 */
$primary = $current['endpoints'][0] ?? [];
?>
<p class="form-hint">
  Section 12: server-wide, not per-merchant - this affects every merchant's optional fiat readout at once. ELEK is not listed on any platform today, so this stays off until there is somewhere real to point it at.
</p>

<?php if ($usingEnvDefault): ?>
  <div class="wallet-warning">Currently using the PAY_SERVER_PRICE_FEED_ENDPOINTS environment-variable default (no override saved here yet). Saving below takes over and stops using the environment variable.</div>
<?php endif; ?>

<?php if ($justSaved): ?>
  <div class="new-key-banner"><p><strong>Price feed settings saved.</strong></p></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
  <p class="login-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<form method="post" action="/admin/platform/price-feed" class="create-key-form">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

  <label class="scope-checkbox">
    <input type="checkbox" id="enabled" name="enabled" <?php echo $current['enabled'] ? 'checked' : ''; ?>>
    Price feed enabled
  </label>
  <p class="form-hint form-hint--tight">Off (default): no fiat readout anywhere, <code>default_display_currency</code> stays hidden in every merchant's settings - exactly as if nothing below were configured. Turning this off later keeps the fields below filled in, so re-enabling once ELEK has a real listing does not mean retyping everything.</p>

  <label for="provider_template">Provider template</label>
  <select id="provider_template">
    <option value="custom">Custom / self-hosted</option>
    <option value="coingecko">CoinGecko (api.coingecko.com)</option>
  </select>
  <p class="form-hint form-hint--tight">Fills in the fields below with a known provider's shape (still editable, still yours to point at a self-hosted mirror or a different coin id). "Custom" leaves everything blank/as-is.</p>

  <label for="base_url">Base URL</label>
  <input id="base_url" name="base_url" type="text" maxlength="500" placeholder="https://api.coingecko.com/api/v3"
         value="<?php echo htmlspecialchars((string) ($primary['base_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

  <label for="coin_id">Coin id</label>
  <input id="coin_id" name="coin_id" type="text" maxlength="100" placeholder="elektron-net"
         value="<?php echo htmlspecialchars((string) ($primary['coin_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Whatever id the platform above uses for ELEK - not necessarily "elektron-net"; depends entirely on where/if it ever gets listed, or what id a self-hosted mirror assigns it.</p>

  <label for="price_path">Price path</label>
  <input id="price_path" name="price_path" type="text" maxlength="200" placeholder="/simple/price"
         value="<?php echo htmlspecialchars((string) ($primary['price_path'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

  <label for="ids_param">Coin-id query parameter name</label>
  <input id="ids_param" name="ids_param" type="text" maxlength="100" placeholder="ids"
         value="<?php echo htmlspecialchars((string) ($primary['ids_param'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

  <label for="vs_currencies_param">Currency query parameter name</label>
  <input id="vs_currencies_param" name="vs_currencies_param" type="text" maxlength="100" placeholder="vs_currencies"
         value="<?php echo htmlspecialchars((string) ($primary['vs_currencies_param'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Leave the four fields above blank to use CoinGecko's own names (<code>/simple/price</code>, <code>ids</code>, <code>vs_currencies</code>) - only change them for a platform that names things differently.</p>

  <label for="api_key_header">API key header (optional)</label>
  <input id="api_key_header" name="api_key_header" type="text" maxlength="100" placeholder="x-cg-demo-api-key"
         value="<?php echo htmlspecialchars((string) ($primary['api_key_header'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

  <label for="api_key">API key (optional)</label>
  <input id="api_key" name="api_key" type="text" maxlength="200"
         value="<?php echo htmlspecialchars((string) ($primary['api_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
  <p class="form-hint form-hint--tight">Only needed if the platform requires one (e.g. CoinGecko's demo-tier key). Leave both blank for a public endpoint that needs none.</p>

  <details class="help-details">
    <summary>Advanced: multiple providers (raw JSON)</summary>
    <p class="form-hint form-hint--tight">Filling this in overrides everything above. A JSON array of endpoint objects, tried in order until one returns a rate - the same shape <code>PAY_SERVER_PRICE_FEED_ENDPOINTS</code> already uses, for listing more than one platform at once. Leave blank to use the single-provider fields above instead.</p>
    <textarea id="advanced_json" name="advanced_json" rows="4" class="json-textarea" placeholder='[{"type":"simple_price","base_url":"https://api.coingecko.com/api/v3","coin_id":"elektron-net"}]'><?php echo htmlspecialchars((string) ($current['advanced_json'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
  </details>

  <button type="submit">Save price feed settings</button>
</form>

<script>
  (function () {
    var templates = {
      coingecko: {
        base_url: 'https://api.coingecko.com/api/v3',
        coin_id: '',
        price_path: '/simple/price',
        ids_param: 'ids',
        vs_currencies_param: 'vs_currencies'
      }
    };

    document.getElementById('provider_template').addEventListener('change', function (event) {
      var tpl = templates[event.target.value];
      if (!tpl) {
        return;
      }
      Object.keys(tpl).forEach(function (field) {
        var input = document.getElementById(field);
        if (input && (input.value === '' || field === 'base_url' || field === 'price_path' || field === 'ids_param' || field === 'vs_currencies_param')) {
          if (tpl[field] !== '') {
            input.value = tpl[field];
          }
        }
      });
    });
  })();
</script>
