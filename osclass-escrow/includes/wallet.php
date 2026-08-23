<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use ElektronNet\Payments\Core\Escrow\ElektronNetworkFactory;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;

/**
 * The one-time wallet connect step from the README ("One-time wallet
 * setup"): a single xpub field. Only ever a public key: the private key
 * never leaves the user's own wallet software. A per-order child public
 * key is derived from this xpub for every new order (XpubChildKeyDeriver),
 * replacing the earlier design's single long-lived pubkey plus an
 * order-nonce baked into the script -- see PlainMultisigEscrowScriptBuilder's
 * docblock for why that had to change.
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
 * from checkout when a buyer has not connected a wallet yet.
 */

function elektron_escrow_get_user_xpub(int $userId): ?string
{
    return EscrowWalletDAO::newInstance()->getXpub($userId);
}

/**
 * @return true on success, or a translated error message string on failure
 */
function elektron_escrow_save_user_xpub(int $userId, string $xpub)
{
    $xpub = trim($xpub);

    if ($xpub === '') {
        return __('Please paste an xpub.', ELEKTRON_ESCROW_DOMAIN);
    }

    try {
        $account = (new HierarchicalKeyFactory())->fromExtended($xpub, elektron_escrow_network());
    } catch (\Throwable $e) {
        return __('This does not look like a valid extended public key (xpub) for this network.', ELEKTRON_ESCROW_DOMAIN);
    }
    if ($account->isPrivate()) {
        return __('This is a private extended key (xprv), not a public one (xpub). Never paste a private key or seed phrase anywhere on this site.', ELEKTRON_ESCROW_DOMAIN);
    }

    $currentXpub = EscrowWalletDAO::newInstance()->getXpub($userId);
    $isActualChange = $currentXpub !== null && $currentXpub !== $xpub;

    // Blocked only for an actual change (re-saving the same key is always a
    // no-op), and only while an order still depends on a key already
    // derived from the xpub being replaced. See
    // EscrowOrderDAO::hasActiveOrderForUser()'s docblock: this does not
    // protect anything cryptographic (an existing order's script is frozen
    // at creation regardless of this table's contents) -- it exists so a
    // user does not casually change wallets mid-order and then find they no
    // longer have the key that order still needs.
    if ($isActualChange && EscrowOrderDAO::newInstance()->hasActiveOrderForUser($userId)) {
        return __("You have an active escrow order that still needs a key derived from your currently connected xpub. It must reach a final state (released, refunded, or claimed) before you can change your xpub.", ELEKTRON_ESCROW_DOMAIN);
    }

    EscrowWalletDAO::newInstance()->setXpub($userId, $xpub);

    return true;
}

/**
 * The address the wallet-connect page shows back to the user so they can
 * confirm it matches an address they actually recognize in their own
 * wallet, BEFORE the xpub is trusted for real orders. See
 * XpubChildKeyDeriver's docblock: a wallet whose xpub does not actually
 * sit at the depth this platform assumes will otherwise only be caught the
 * hard way, at release time.
 */
function elektron_escrow_preview_address_for_xpub(string $xpub): ?string
{
    try {
        return (new XpubChildKeyDeriver())->deriveChildAddress($xpub, 0, elektron_escrow_network());
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Derives and permanently allocates the next order-specific child public
 * key for this user's currently-registered xpub. Never call this more than
 * once for the same order: each call consumes a derivation index that is
 * never reused (EscrowWalletDAO::allocateNextIndex()), so calling it twice
 * for one order would burn an index without using it.
 */
function elektron_escrow_derive_order_pubkey(int $userId): string
{
    $xpub = elektron_escrow_get_user_xpub($userId);
    if ($xpub === null) {
        throw new RuntimeException("elektron_escrow_derive_order_pubkey() called for user {$userId} with no connected wallet.");
    }

    $index = EscrowWalletDAO::newInstance()->allocateNextIndex($userId);

    return (new XpubChildKeyDeriver())->deriveChildPubKeyHex($xpub, $index, elektron_escrow_network());
}
