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

// TEMPORARY DIAGNOSTIC (remove once the save issue is confirmed fixed):
// shows exactly what this request actually received, since the reported
// symptom (no message at all, field cleared) is consistent with several
// different failure points and none of them can be told apart from the
// outside. Deliberately does not touch the CSRFToken value itself.
$debugInfo = array(
    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '(unknown)',
    "Params::getParam('save')" => Params::getParam('save'),
    "Params::getParam('CSRFName') present" => Params::getParam('CSRFName') !== '' ? 'yes' : 'no',
    "Params::getParam('CSRFToken') present" => Params::getParam('CSRFToken') !== '' ? 'yes' : 'no',
    "Params::getParam('pubkey_hex')" => Params::getParam('pubkey_hex'),
    'if-block entered' => 'no',
);

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
    $debugInfo['if-block entered'] = 'yes';

    osc_csrf_check();

    $debugInfo['reached after osc_csrf_check()'] = 'yes';

    $result = elektron_escrow_save_user_pubkey($userId, (string) Params::getParam('pubkey_hex'));
    $debugInfo['elektron_escrow_save_user_pubkey() returned'] = $result === true ? 'true' : $result;
    if ($result === true) {
        $justSaved = true;
    } else {
        $errorMessage = $result;
    }
}

$currentPubKey = elektron_escrow_get_user_pubkey($userId);

require osc_plugins_path() . 'osclass-escrow/views/wallet.php';
