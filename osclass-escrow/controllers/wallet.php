<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Route: elektron-escrow/wallet
 * The one-time wallet-connect page (see includes/wallet.php's docblock for
 * why this is a dedicated route rather than a field on the profile form).
 */

if (!osc_is_web_user_logged_in()) {
    osc_redirect_to(osc_user_login_url());
    exit;
}

$userId = (int) osc_logged_user_id();
$errorMessage = null;
$justSaved = false;

// The 'save' marker alone decides whether this is a submission; the CSRF
// token itself is deliberately NOT inspected here before calling
// osc_csrf_check(). An earlier version also required
// Params::getParam('CSRFName') !== '', which hardcoded Shopclass's own
// token field name as an assumption about a *different* real, deployed
// platform's internals -- confirmed wrong live: that platform's actual
// CSRF field is a single 'octoken' input, not a 'CSRFName'/'CSRFToken'
// pair, so the guard was always false and osc_csrf_check() never even ran.
// osc_csrf_check() is already responsible for rejecting a missing/invalid
// token on whichever platform is actually running underneath; this plugin
// has no business assuming what that token looks like.
if (Params::getParam('save') === 'elektron_escrow_wallet') {
    osc_csrf_check();

    // Renders the success confirmation directly in this same response
    // instead of osc_add_flash_ok_message() + a redirect back to this
    // same route. A flash message is only shown by whatever the *active
    // theme's* custom.php/user-custom.php template does with it, which is
    // entirely outside this plugin's control.
    $result = elektron_escrow_save_user_pubkey($userId, (string) Params::getParam('pubkey_hex'));
    if ($result === true) {
        $justSaved = true;
    } else {
        $errorMessage = $result;
    }
}

$currentPubKey = elektron_escrow_get_user_pubkey($userId);

require osc_plugins_path() . 'osclass-escrow/views/wallet.php';
