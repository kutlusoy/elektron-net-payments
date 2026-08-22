<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $user Osclass user row, injected by the 'user_profile_form' hook */
$currentPubKey = elektron_escrow_get_user_pubkey((int) $user['pk_i_id']);
?>

<div class="control-group">
    <label for="elektron_escrow_pubkey"><?php _e('Elektron Net public key (for escrow payments)', ELEKTRON_ESCROW_DOMAIN); ?></label>
    <input type="text" name="elektron_escrow_pubkey" id="elektron_escrow_pubkey"
           value="<?php echo osc_esc_html($currentPubKey ?? ''); ?>"
           placeholder="02..." maxlength="66" size="70" />
    <p class="help-block">
        <?php _e('Paste the public key from your Elektron Net wallet, never a private key or seed phrase. Needed once, before you can buy or sell with escrow.', ELEKTRON_ESCROW_DOMAIN); ?>
    </p>
</div>
