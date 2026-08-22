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

if (Params::getParam('save') === 'elektron_escrow_wallet' && Params::getParam('CSRFName') !== '') {
    osc_csrf_check();

    $result = elektron_escrow_save_user_pubkey($userId, (string) Params::getParam('pubkey_hex'));
    if ($result === true) {
        osc_add_flash_ok_message(__('Wallet connected.', ELEKTRON_ESCROW_DOMAIN));
        osc_redirect_to(osc_route_url('elektron_escrow_wallet'));
        exit;
    }

    $errorMessage = $result;
}

$currentPubKey = elektron_escrow_get_user_pubkey($userId);

require osc_plugins_path() . 'osclass-escrow/views/wallet.php';
