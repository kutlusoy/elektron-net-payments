<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * The one-time wallet connect step from the README ("One-time wallet
 * setup"): a single compressed-public-key field on the user's own profile
 * form. Only the public key is ever collected; the private key never
 * leaves the user's own wallet software.
 */

function elektron_escrow_get_user_pubkey(int $userId): ?string
{
    return EscrowWalletDAO::newInstance()->getPubKey($userId);
}

osc_add_hook('user_profile_form', function ($user) {
    require osc_plugins_path() . 'osclass-escrow/views/wallet-field.php';
});

osc_add_hook('user_edit_completed', function ($userId) {
    $pubKeyHex = trim((string) Params::getParam('elektron_escrow_pubkey'));
    if ($pubKeyHex === '') {
        return;
    }

    if (!preg_match('/^(02|03)[0-9a-fA-F]{64}$/', $pubKeyHex)) {
        osc_add_flash_error_message(
            __('The Elektron Net public key looks invalid (expected a 33-byte compressed key, 66 hex characters starting with 02 or 03) and was not saved.', ELEKTRON_ESCROW_DOMAIN)
        );
        return;
    }

    EscrowWalletDAO::newInstance()->setPubKey((int) $userId, strtolower($pubKeyHex));
});
