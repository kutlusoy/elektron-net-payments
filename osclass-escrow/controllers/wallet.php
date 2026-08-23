<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Route: elektron-escrow/wallet
 * The one-time wallet-connect page (see includes/wallet.php's docblock for
 * why this is a dedicated route rather than a field on the profile form).
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to manage your Elektron Net wallet.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$userId = (int) osc_logged_user_id();
$errorMessage = null;
$justSaved = false;

// The 'save' marker alone decides whether this is a submission; the CSRF
// token itself is deliberately NOT inspected here before calling
// osc_csrf_check(). See git history for why an earlier version's extra
// guard here was itself wrong on a real deployed platform.
if (Params::getParam('save') === 'elektron_escrow_wallet') {
    osc_csrf_check();

    // Renders the success confirmation directly in this same response
    // instead of osc_add_flash_ok_message() + a redirect back to this
    // same route. A flash message is only shown by whatever the *active
    // theme's* custom.php/user-custom.php template does with it, which is
    // entirely outside this plugin's control.
    $result = elektron_escrow_save_user_xpub($userId, (string) Params::getParam('xpub'));
    if ($result === true) {
        $justSaved = true;
    } else {
        $errorMessage = $result;
    }
}

$currentXpub = elektron_escrow_get_user_xpub($userId);
$previewAddress = $currentXpub !== null ? elektron_escrow_preview_address_for_xpub($currentXpub) : null;

require osc_plugins_path() . 'osclass-escrow/views/wallet.php';
