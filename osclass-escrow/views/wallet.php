<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var string|null $currentPubKey */
/** @var string|null $errorMessage */
/** @var bool $justSaved */
/** @var array $debugInfo */
?>

<div class="elektron-escrow-wallet">
    <h1><?php _e('Elektron Net wallet', ELEKTRON_ESCROW_DOMAIN); ?></h1>

    <div style="border:2px dashed red; padding:10px; margin-bottom:15px; font-family:monospace; font-size:13px;">
        <strong>TEMPORARY DEBUG (remove once the save issue is diagnosed):</strong>
        <ul>
            <?php foreach ($debugInfo as $debugKey => $debugValue) { ?>
                <li><?php echo osc_esc_html($debugKey); ?>: <strong><?php echo osc_esc_html((string) $debugValue); ?></strong></li>
            <?php } ?>
        </ul>
    </div>

    <?php if ($justSaved) { ?>
        <p class="elektron-escrow-success"><?php _e('Wallet connected.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } ?>

    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <?php if ($currentPubKey !== null) { ?>
        <p><?php _e('A wallet is connected. Paste a different public key below to replace it.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } ?>

    <form method="post" action="<?php echo osc_route_url('elektron_escrow_wallet'); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="save" value="elektron_escrow_wallet" />

        <label for="pubkey_hex"><?php _e('Elektron Net public key (for escrow payments)', ELEKTRON_ESCROW_DOMAIN); ?></label>
        <input type="text" name="pubkey_hex" id="pubkey_hex"
               value="<?php echo osc_esc_html($currentPubKey ?? ''); ?>"
               placeholder="02..." maxlength="66" size="70" />
        <p class="help-block">
            <?php _e('Paste the public key from your Elektron Net wallet, never a private key or seed phrase. Needed once, before you can buy or sell with escrow.', ELEKTRON_ESCROW_DOMAIN); ?>
        </p>

        <details class="elektron-escrow-pubkey-help">
            <summary><?php _e('How do I find my public key?', ELEKTRON_ESCROW_DOMAIN); ?></summary>

            <p>
                <?php _e("Works the same whether you type it into your wallet's built-in Console (in most wallets: Help > Debug window > Console) or run it with elektron-cli in a terminal, since both send the same commands to your node.", ELEKTRON_ESCROW_DOMAIN); ?>
            </p>

            <p><?php _e('1. Get a fresh receiving address:', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <pre>elektron-cli getnewaddress</pre>
            <pre>be1qw508d6qejxtdg4y5r3zarvary0c5xw7kpu8cre</pre>

            <p><?php _e("2. Look up that address's public key:", ELEKTRON_ESCROW_DOMAIN); ?></p>
            <pre>elektron-cli getaddressinfo "be1qw508d6qejxtdg4y5r3zarvary0c5xw7kpu8cre"</pre>
            <pre>{
  "address": "be1qw508d6qejxtdg4y5r3zarvary0c5xw7kpu8cre",
  "scriptPubKey": "0014751e76e8199196d454941c45d1b3a323f1433bd6",
  "ismine": true,
  "solvable": true,
  "iswitness": true,
  "witness_version": 0,
  "witness_program": "751e76e8199196d454941c45d1b3a323f1433bd6",
  "pubkey": "0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798",
  "iscompressed": true
}</pre>

            <p>
                <?php _e('Copy the value of "pubkey" (starts with 02 or 03, 66 hex characters) into the field above. Never run dumpprivkey or share its output anywhere; this plugin only ever needs the pubkey value.', ELEKTRON_ESCROW_DOMAIN); ?>
            </p>
        </details>

        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Save', ELEKTRON_ESCROW_DOMAIN)); ?>" />
    </form>
</div>
