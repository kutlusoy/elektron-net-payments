<?php

namespace ElektronNet\Payments\PayServer;

use BitWasp\Bitcoin\Base58;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\Network;
use BitWasp\Buffertools\Buffer;
use ElektronNet\Payments\PayServer\Http\ApiException;
use Throwable;

/**
 * Section 8: the "connect your receiving wallet" step every merchant
 * onboarding flow needs before that merchant can accept a direct payment
 * at all. Mirrors the already-verified logic in
 * osclass-escrow/includes/wallet.php (elektron_escrow_normalize_xpub_prefix(),
 * elektron_escrow_is_slip132_private_key(), elektron_escrow_save_user_xpub())
 * rather than sharing a core class with it, matching this build-out's
 * existing pattern (see api/src/Bip21.php's docblock) of mirroring
 * verified logic instead of touching the working osclass-escrow adapter
 * as a side effect.
 *
 * Section 8's own requirement, unchanged here: accept any well-formed
 * xpub/ypub/zpub regardless of which coin type derived it (SLIP-44 1370
 * vs. legacy 0'), and never ask which one was used -- this class has no
 * coin-type check anywhere, on purpose.
 */
final class XpubValidation
{
    /**
     * SLIP-132 version-byte prefixes some wallets use instead of the
     * plain BIP32 "xpub" one, to hint at the script type the key is meant
     * for -- cryptographically the exact same extended key either way.
     * Values confirmed against SLIP-132
     * (github.com/satoshilabs/slips/blob/master/slip-0132.md).
     */
    private const SLIP132_PUBKEY_VERSIONS = [
        '049d7cb2', // ypub (BIP49, P2SH-P2WPKH)
        '04b24746', // zpub (BIP84, native P2WPKH)
        '0295b43f', // Ypub (BIP49 multisig)
        '02aa7ed3', // Zpub (BIP84 multisig)
    ];
    private const SLIP132_PRIVKEY_VERSIONS = [
        '0488ade4', // xprv
        '049d7878', // yprv
        '04b2430c', // zprv
        '0295b005', // Yprv
        '02aa7a99', // Zprv
    ];

    private function __construct()
    {
    }

    /**
     * @return string the same key, re-encoded with $network's own plain
     *     xpub version bytes
     * @throws ApiException 422 with a typed message if $rawXpub is empty,
     *     a private key, or not a recognized extended public key at all
     */
    public static function normalizeAndValidate(string $rawXpub, Network $network): string
    {
        $xpub = trim($rawXpub);
        if ($xpub === '') {
            throw ApiException::validationError('Please paste an xpub.');
        }

        if (self::isSlip132PrivateKey($xpub)) {
            throw ApiException::validationError(
                'This is a private extended key (xprv), not a public one (xpub). Never paste a private key or seed phrase anywhere.'
            );
        }

        $normalized = self::normalizePrefix($xpub, $network);
        if ($normalized === null) {
            throw ApiException::validationError('This does not look like a valid extended public key (xpub) for this network.');
        }

        try {
            $account = (new HierarchicalKeyFactory())->fromExtended($normalized, $network);
        } catch (Throwable $e) {
            throw ApiException::validationError('This does not look like a valid extended public key (xpub) for this network.');
        }
        if ($account->isPrivate()) {
            throw ApiException::validationError(
                'This is a private extended key, not a public one. Never paste a private key or seed phrase anywhere.'
            );
        }

        return $normalized;
    }

    private static function normalizePrefix(string $xpub, Network $network): ?string
    {
        try {
            $raw = Base58::decodeCheck($xpub);
        } catch (Throwable $e) {
            return null;
        }
        if ($raw->getSize() !== 78) {
            return null;
        }

        $versionHex = strtolower($raw->slice(0, 4)->getHex());
        if ($versionHex !== $network->getHDPubByte() && !in_array($versionHex, self::SLIP132_PUBKEY_VERSIONS, true)) {
            return null;
        }

        $rewritten = Buffer::hex($network->getHDPubByte())->getBinary() . $raw->slice(4)->getBinary();

        return Base58::encodeCheck(new Buffer($rewritten));
    }

    private static function isSlip132PrivateKey(string $xpub): bool
    {
        try {
            $raw = Base58::decodeCheck($xpub);
        } catch (Throwable $e) {
            return false;
        }

        return $raw->getSize() === 78
            && in_array(strtolower($raw->slice(0, 4)->getHex()), self::SLIP132_PRIVKEY_VERSIONS, true);
    }
}
