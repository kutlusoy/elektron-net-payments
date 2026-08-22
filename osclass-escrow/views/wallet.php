<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var string|null $currentPubKey */
/** @var string|null $errorMessage */
?>

<div class="elektron-escrow-wallet">
    <h1><?php _e('Elektron Net wallet', ELEKTRON_ESCROW_DOMAIN); ?></h1>

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

        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Save', ELEKTRON_ESCROW_DOMAIN)); ?>" />
    </form>
</div>
