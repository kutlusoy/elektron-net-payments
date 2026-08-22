<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\I18n\MessageCatalog;

/**
 * Looks up a shared-core message key, translates its English fallback text
 * through Osclass's own gettext-based `__()` (domain: ELEKTRON_ESCROW_DOMAIN,
 * matching languages/<locale>/messages.po, msgid = the English fallback
 * text itself, per Osclass convention), then fills in placeholders.
 *
 * @param string $key a ElektronNet\Payments\Core\I18n\MessageCatalog key
 * @param array<string, string> $vars e.g. ['{date}' => '2026-09-21']
 */
function elektron_escrow_t(string $key, array $vars = []): string
{
    $text = __(MessageCatalog::fallback($key), ELEKTRON_ESCROW_DOMAIN);

    return strtr($text, $vars);
}
