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

// Renders the success confirmation directly in this same response instead
// of osc_add_flash_ok_message() + a redirect back to this same route. Not
// just style: a flash message is stored server-side and only shown by
// whatever the *active theme's* custom.php/user-custom.php template does
// with it -- Shopclass ships with no bundled theme at all (oc-content/themes/
// is empty except for the security stub), so that rendering is entirely
// outside this plugin's control. Reporting success the same direct way
// $errorMessage already does removes that dependency for the one message
// that matters most here.
if (Params::getParam('save') === 'elektron_escrow_wallet' && Params::getParam('CSRFName') !== '') {
    osc_csrf_check();

    $result = elektron_escrow_save_user_pubkey($userId, (string) Params::getParam('pubkey_hex'));
    if ($result === true) {
        $justSaved = true;
    } else {
        $errorMessage = $result;
    }
}

$currentPubKey = elektron_escrow_get_user_pubkey($userId);

require osc_plugins_path() . 'osclass-escrow/views/wallet.php';
