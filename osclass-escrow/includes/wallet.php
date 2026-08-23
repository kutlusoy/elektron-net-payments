<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\Network;
use BitWasp\Buffertools\Buffer;
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
 * SLIP-132 version-byte prefixes some wallets (Elektrum among them, for a
 * native-segwit BIP84 wallet) use instead of the plain BIP32 "xpub" one,
 * to hint at the script type the key is meant for. Cryptographically it is
 * the exact same extended key either way -- only the 4 version bytes at
 * the front of the base58check payload differ -- so this platform accepts
 * any of them and rewrites the version bytes to the network's own plain
 * xpub prefix before ever parsing or storing the key, rather than
 * rejecting a perfectly valid key just because of which prefix a
 * particular wallet chose to display it with.
 *
 * Values confirmed against SLIP-132 (github.com/satoshilabs/slips/blob/
 * master/slip-0132.md), the standard this convention comes from.
 */
const ELEKTRON_ESCROW_SLIP132_PUBKEY_VERSIONS = [
    '049d7cb2', // ypub (BIP49, P2SH-P2WPKH)
    '04b24746', // zpub (BIP84, native P2WPKH)
    '0295b43f', // Ypub (BIP49 multisig)
    '02aa7ed3', // Zpub (BIP84 multisig)
];
const ELEKTRON_ESCROW_SLIP132_PRIVKEY_VERSIONS = [
    '0488ade4', // xprv
    '049d7878', // yprv
    '04b2430c', // zprv
    '0295b005', // Yprv
    '02aa7a99', // Zprv
];

/**
 * @return string|null the same key re-encoded with $network's own plain
 *     xpub version bytes, or null if $xpub does not base58check-decode to
 *     a recognized extended-public-key version at all (including a
 *     recognized *private*-key version -- callers must check for that
 *     themselves via elektron_escrow_is_slip132_private_key() first if
 *     they want a different message for it)
 */
function elektron_escrow_normalize_xpub_prefix(string $xpub, Network $network): ?string
{
    try {
        $raw = Base58::decodeCheck($xpub);
    } catch (\Throwable $e) {
        return null;
    }
    if ($raw->getSize() !== 78) {
        return null;
    }

    $versionHex = strtolower($raw->slice(0, 4)->getHex());
    if ($versionHex !== $network->getHDPubByte() && !in_array($versionHex, ELEKTRON_ESCROW_SLIP132_PUBKEY_VERSIONS, true)) {
        return null;
    }

    $rewritten = Buffer::hex($network->getHDPubByte())->getBinary() . $raw->slice(4)->getBinary();

    return Base58::encodeCheck(new Buffer($rewritten));
}

function elektron_escrow_is_slip132_private_key(string $xpub): bool
{
    try {
        $raw = Base58::decodeCheck($xpub);
    } catch (\Throwable $e) {
        return false;
    }

    return $raw->getSize() === 78 && in_array(strtolower($raw->slice(0, 4)->getHex()), ELEKTRON_ESCROW_SLIP132_PRIVKEY_VERSIONS, true);
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

    if (elektron_escrow_is_slip132_private_key($xpub)) {
        return __('This is a private extended key (xprv), not a public one (xpub). Never paste a private key or seed phrase anywhere on this site.', ELEKTRON_ESCROW_DOMAIN);
    }

    // Accepts ypub/zpub/Ypub/Zpub too, not only a plain xpub -- see
    // elektron_escrow_normalize_xpub_prefix()'s docblock.
    $normalized = elektron_escrow_normalize_xpub_prefix($xpub, elektron_escrow_network());
    if ($normalized === null) {
        return __('This does not look like a valid extended public key (xpub) for this network.', ELEKTRON_ESCROW_DOMAIN);
    }
    $xpub = $normalized;

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
