<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * The one-time wallet connect step from the README ("One-time wallet
 * setup"): a single compressed-public-key field. Only the public key is
 * ever collected; the private key never leaves the user's own wallet
 * software.
 *
 * This used to be a field embedded into the user's own profile-edit form
 * via the 'user_profile_form' hook. That hook does not exist in Shopclass
 * (verified directly against github.com/mindstellar/shopclass, not
 * assumed: Osclass's old bundled fallback profile template, the only place
 * that hook ever fired from, was removed there entirely, and no core
 * controller fires an equivalent). A dedicated route
 * (controllers/wallet.php, registered in index.php) replaces it: a
 * standalone page, its own form and POST handler, reached from the
 * logged-in user's own menu (`user_menu = true` on the route) and linked
 * from checkout when a buyer has not connected a wallet yet. This is also
 * more robust than the hook ever was, since it does not depend on whatever
 * template the active theme happens to render for the profile page.
 */

function elektron_escrow_get_user_pubkey(int $userId): ?string
{
    return EscrowWalletDAO::newInstance()->getPubKey($userId);
}

/**
 * @return true on success, or a translated error message string on failure
 */
function elektron_escrow_save_user_pubkey(int $userId, string $pubKeyHex)
{
    $pubKeyHex = trim($pubKeyHex);

    if (!preg_match('/^(02|03)[0-9a-fA-F]{64}$/', $pubKeyHex)) {
        return __('The Elektron Net public key looks invalid (expected a 33-byte compressed key, 66 hex characters starting with 02 or 03) and was not saved.', ELEKTRON_ESCROW_DOMAIN);
    }

    EscrowWalletDAO::newInstance()->setPubKey($userId, strtolower($pubKeyHex));

    return true;
}
