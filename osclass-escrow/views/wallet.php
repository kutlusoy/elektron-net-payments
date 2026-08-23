<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var string|null $currentXpub */
/** @var string|null $previewAddress */
/** @var string|null $errorMessage */
/** @var bool $justSaved */
?>

<div class="elektron-escrow-wallet">
    <h1><?php _e('Elektron Net wallet', ELEKTRON_ESCROW_DOMAIN); ?></h1>

    <?php if ($justSaved) { ?>
        <p class="elektron-escrow-success"><?php _e('Wallet connected.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } ?>

    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <?php if ($currentXpub !== null) { ?>
        <p><?php _e('A wallet is connected. Paste a different xpub below to replace it.', ELEKTRON_ESCROW_DOMAIN); ?></p>

        <?php if ($previewAddress !== null) { ?>
            <p><?php _e('The first address this xpub produces is:', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <code class="elektron-escrow-code"><?php echo osc_esc_html($previewAddress); ?></code>
            <p class="help-block">
                <?php _e('Check this against your own wallet: does it match the first address your wallet shows you? If not, the xpub was pasted from the wrong place and orders using it will not be usable later -- see the instructions below for how to get the right one.', ELEKTRON_ESCROW_DOMAIN); ?>
            </p>
        <?php } ?>
    <?php } ?>

    <form method="post" action="<?php echo osc_route_url('elektron_escrow_wallet'); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="save" value="elektron_escrow_wallet" />

        <label for="xpub"><?php _e('Elektron Net extended public key (xpub, for escrow payments)', ELEKTRON_ESCROW_DOMAIN); ?></label>
        <input type="text" name="xpub" id="xpub"
               value="<?php echo osc_esc_html($currentXpub ?? ''); ?>"
               placeholder="xpub..." maxlength="120" size="70" />
        <p class="help-block">
            <?php _e('Paste your xpub, never a private key, xprv, or seed phrase. Needed once, before you can buy or sell with escrow: a fresh address is derived from it for every order automatically, so you never have to do this again.', ELEKTRON_ESCROW_DOMAIN); ?>
        </p>
        <p class="help-block">
            <?php _e('Starts with ypub or zpub instead of xpub? That is fine, paste it as shown -- some wallets use a different prefix to hint at the address type, but it is accepted here either way.', ELEKTRON_ESCROW_DOMAIN); ?>
        </p>

        <details class="elektron-escrow-pubkey-help">
            <summary><?php _e('How do I find my xpub?', ELEKTRON_ESCROW_DOMAIN); ?></summary>

            <p><strong><?php _e('Elektrum desktop app', ELEKTRON_ESCROW_DOMAIN); ?></strong></p>
            <p><?php _e('Menu: Wallet > Information. The "Master Public Key" field shown there is your xpub (also available as a QR code).', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <p><?php _e('Or, in the Console (Tools > Console, or Help > Debug window > Console in some versions):', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <pre>getmpk</pre>

            <p><strong><?php _e('Elektrum mobile app', ELEKTRON_ESCROW_DOMAIN); ?></strong></p>
            <p><?php _e('The mobile app does not currently show the xpub anywhere in its own screens. Until that changes, open the same wallet (same seed phrase) once in the Elektrum desktop app to read it there -- you only need to do this once, the xpub never changes for a given seed.', ELEKTRON_ESCROW_DOMAIN); ?></p>

            <p><strong><?php _e('Elektron Net node wallet (elektrond / elektron-cli / elektron-qt)', ELEKTRON_ESCROW_DOMAIN); ?></strong></p>
            <p><?php _e('No wallet password needed for this (it only reads public keys):', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <pre>elektron-cli -rpcwallet="YOUR_WALLET_NAME" gethdkeys</pre>
            <p>
                <?php _e('In the result, find the entry whose "descriptors" array contains one starting with "wpkh(" and marked "active": true, and copy that entry\'s "xpub" value.', ELEKTRON_ESCROW_DOMAIN); ?>
            </p>

            <p>
                <?php _e('After saving, this page shows you the first address your xpub produces so you can check it matches what your own wallet shows -- do that before using the connected wallet for a real order.', ELEKTRON_ESCROW_DOMAIN); ?>
            </p>
        </details>

        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Save', ELEKTRON_ESCROW_DOMAIN)); ?>" />
    </form>
</div>
