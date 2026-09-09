<?php

namespace ElektronNet\Payments\PayServer;

/**
 * The BTCPay-style order lifecycle from
 * doc-elektron/guideline-standalone-payment-server.md section 13. Distinct
 * from core's Escrow\OrderStatus (which describes the older, Osclass-only
 * escrow-specific lifecycle): this is `pay-server`'s own `orders.status`
 * column and MUST stay the single source of truth the watcher, API, and
 * webhooks all agree on.
 */
final class OrderStatus
{
    public const NEW = 'new';
    public const PROCESSING = 'processing';
    public const SETTLED = 'settled';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    public const TERMINAL = [
        self::SETTLED,
        self::EXPIRED,
        self::INVALID,
    ];

    public const ALL = [
        self::NEW,
        self::PROCESSING,
        self::SETTLED,
        self::EXPIRED,
        self::INVALID,
    ];

    private function __construct()
    {
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
