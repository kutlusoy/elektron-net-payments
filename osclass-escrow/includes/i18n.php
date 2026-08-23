<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\OrderStatus;
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

/**
 * Short, translatable status label for a compact list column (the "My
 * Elektron orders" page). Distinct from elektron_escrow_t()'s
 * MessageCatalog lookups, which are full sentences meant for a single
 * order's own status area, not a table cell.
 */
function elektron_escrow_status_label(string $status): string
{
    $labels = [
        OrderStatus::AWAITING_PAYMENT => __('Awaiting payment', ELEKTRON_ESCROW_DOMAIN),
        OrderStatus::CONFIRMING => __('Confirming', ELEKTRON_ESCROW_DOMAIN),
        OrderStatus::FUNDED => __('Funded', ELEKTRON_ESCROW_DOMAIN),
        OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE => __('Release pending', ELEKTRON_ESCROW_DOMAIN),
        OrderStatus::RELEASED => __('Released', ELEKTRON_ESCROW_DOMAIN),
        OrderStatus::REFUNDED => __('Refunded', ELEKTRON_ESCROW_DOMAIN),
        OrderStatus::CLAIMED_BY_SELLER => __('Claimed by seller', ELEKTRON_ESCROW_DOMAIN),
    ];

    return $labels[$status] ?? $status;
}
