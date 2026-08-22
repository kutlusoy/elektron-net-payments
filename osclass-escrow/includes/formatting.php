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
