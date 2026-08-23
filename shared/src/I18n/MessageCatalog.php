<?php

namespace ElektronNet\Payments\Core\I18n;

/**
 * Canonical list of user-facing message keys and their English fallback
 * text, shared by every platform adapter. The core never renders text
 * itself; each adapter looks a key up here (for the fallback string and the
 * list of placeholders it must fill in) and then asks its own platform's
 * translation system (e.g. Osclass's `__($key, $domain)` against
 * languages/<locale>/messages.po) for the localized version. This is what
 * keeps translations centrally defined once while still going through each
 * platform's native i18n tooling.
 *
 * Placeholders use `{name}` and are substituted by the adapter, not here.
 */
final class MessageCatalog
{
    /** @var array<string, string> */
    private const MESSAGES = [
        'order.awaiting_payment' => 'Send {amount} ELEK to the address below to start this order.',
        'order.awaiting_payment.seller' => 'Waiting for the buyer to send {amount} ELEK.',
        'order.confirming' => 'Payment seen, waiting for {confirmations} confirmation(s).',
        'order.confirming.seller' => 'Payment seen, waiting for {confirmations} confirmation(s) before it counts as funded.',
        'order.funded.buyer' => 'Payment confirmed. Please confirm receipt once the item arrives.',
        'order.funded.seller' => 'Payment confirmed. Waiting for the buyer to confirm receipt.',
        'order.countdown.buyer_refund' => 'Automatic refund available from {date} if this order is not confirmed before then.',
        'order.countdown.seller_release' => 'Automatic release available from {date} if the buyer does not respond.',
        'order.release_pending_seller_signature' => 'Buyer confirmed receipt. Waiting for the seller\'s wallet to sign the release.',
        'order.confirm_receipt.too_close_to_refund' => 'Too close to the automatic refund date to safely start a release now. Please contact support.',
        'order.released' => 'Funds released to the seller.',
        'order.refunded' => 'Funds refunded to the buyer.',
        'order.claimed_by_seller' => 'Funds claimed by the seller after the buyer did not respond in time.',
        'reminder.buyer_refund_upcoming' => 'Your automatic refund window opens on {date} if this order stays unconfirmed.',
        'reminder.seller_release_upcoming' => 'Your automatic release window opens on {date} if the buyer does not respond.',
        'admin.timeout_policy.invalid_ratio' => 'The seller release threshold must be at least {ratio}x the buyer refund threshold.',
        'admin.timeout_policy.invalid_bounds' => 'Buyer refund and seller release thresholds must stay within the allowed range.',
    ];

    public static function fallback(string $key): string
    {
        return self::MESSAGES[$key] ?? $key;
    }

    /** @return array<string, string> every defined key with its English fallback */
    public static function all(): array
    {
        return self::MESSAGES;
    }
}
