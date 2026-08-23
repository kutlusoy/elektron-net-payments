<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Formats an ELEK amount using the current visitor's locale settings
 * (decimal point vs. comma, thousands separator), the same per-locale
 * `t_locale` values Osclass's own osc_format_price() reads from.
 *
 * Not implemented as a call to osc_format_price() itself: that helper
 * divides by a hardcoded 1,000,000, matching Osclass's own generic
 * `i_price` column, not the 100,000,000 (1 ELEK = 100,000,000 lepton)
 * scale this plugin uses, and always shows a fixed number of decimals
 * where a crypto amount reads better with trailing zeros trimmed.
 */
function elektron_escrow_format_amount(float $amountElek): string
{
    $fixed = number_format($amountElek, 8, '.', '');
    $trimmed = rtrim(rtrim($fixed, '0'), '.');
    if ($trimmed === '') {
        $trimmed = '0';
    }

    [$integerPart, $fractionPart] = array_pad(explode('.', $trimmed, 2), 2, null);

    $formattedInteger = number_format((float) $integerPart, 0, '', osc_locale_thousands_sep());

    if ($fractionPart === null) {
        return $formattedInteger;
    }

    return $formattedInteger . osc_locale_dec_point() . $fractionPart;
}

/**
 * Prints a small, self-contained <style> block for this plugin's own
 * tables (class "elektron-escrow-table"): borders, a header background, and
 * row separators, regardless of whatever the active theme's own ".table"
 * class does or does not provide.
 *
 * Not queued via osc_enqueue_style(): that helper only takes effect if
 * called before the theme prints its own <head>, but every one of this
 * plugin's pages is rendered through Osclass's custom-route pipeline,
 * which -- like osc_redirect_to() (see includes/notice.php's docblock) --
 * always renders the theme's header.php, and so its <head>, *before* this
 * plugin's own controller/view code ever runs. An inline <style> block
 * printed directly in the page body works regardless of that ordering.
 */
function elektron_escrow_table_style(): void
{
    ?>
    <style>
        .elektron-escrow-table { width: 100%; border-collapse: collapse; margin: 1em 0; }
        .elektron-escrow-table th, .elektron-escrow-table td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        .elektron-escrow-table th { background: #f0f0f0; font-weight: bold; }
        .elektron-escrow-table tr:nth-child(even) td { background: #fafafa; }
    </style>
    <?php
}

/**
 * Plain (locale-independent, '.' decimal point, no thousands separator,
 * trailing zeros trimmed) decimal string for an ELEK amount. Used inside a
 * payment URI, which a wallet must parse unambiguously regardless of the
 * visitor's own locale -- unlike elektron_escrow_format_amount(), which is
 * for human display only.
 */
function elektron_escrow_plain_amount(float $amountElek): string
{
    $fixed = number_format($amountElek, 8, '.', '');
    $trimmed = rtrim(rtrim($fixed, '0'), '.');

    return $trimmed === '' ? '0' : $trimmed;
}

/**
 * BIP21-style payment URI for the given address/amount. Scheme is 'elek',
 * not 'bitcoin': confirmed directly in the actual Elektron Net wallet
 * fork's own source (kutlusoy/elektron-net-electrum,
 * electrum/bip21.py: `BITCOIN_BIP21_URI_SCHEME = 'elek'`, deliberately
 * renamed there specifically so a real bitcoin: URI is never silently
 * accepted for this, different, chain). `amount` is a plain decimal ELEK
 * value (matching that same file's create_bip21_uri(), which formats the
 * amount with format_satoshis_plain() -- i.e. a plain decimal string, not
 * locale-formatted and not in lepton), so a wallet that understands this
 * scheme can prefill both the address and the amount from one scan or one
 * paste.
 */
function elektron_escrow_payment_uri(string $address, float $amountElek): string
{
    return 'elek:' . $address . '?amount=' . elektron_escrow_plain_amount($amountElek);
}
