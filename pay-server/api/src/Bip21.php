<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Db\OrderRepository;

/**
 * BIP21-style payment URI for the checkout page's QR code (section 10.A).
 * Scheme is `elek`, not `bitcoin`: confirmed directly in the Elektron Net
 * wallet fork's own source (kutlusoy/elektron-net-electrum,
 * electrum/bip21.py: `BITCOIN_BIP21_URI_SCHEME = 'elek'`), the same
 * convention osclass-escrow/includes/formatting.php's
 * elektron_escrow_payment_uri() already uses -- mirrored here rather than
 * shared via core/ so this class stays a plain, dependency-free value
 * builder; hoisting both call sites onto one core class is a reasonable
 * later cleanup, not attempted here to avoid touching the working
 * osclass-escrow adapter as a side effect of this build-out.
 *
 * `amount` is a plain decimal ELEK value, not locale-formatted and not in
 * lep, so any wallet that understands this scheme can prefill both the
 * address and the amount from one scan or paste.
 */
final class Bip21
{
    private function __construct()
    {
    }

    /**
     * Locale-independent ('.' decimal point, no thousands separator,
     * trailing zeros trimmed) decimal string for an ELEK amount -- a
     * wallet must parse this unambiguously regardless of the buyer's own
     * locale.
     */
    public static function plainAmount(int $amountLep): string
    {
        $amountElek = $amountLep / OrderRepository::LEP_PER_ELEK;
        $fixed = number_format($amountElek, 8, '.', '');
        $trimmed = rtrim(rtrim($fixed, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    public static function paymentUri(string $address, int $amountLep, string $label): string
    {
        return 'elek:' . $address
            . '?amount=' . self::plainAmount($amountLep)
            . '&label=' . rawurlencode($label);
    }
}
