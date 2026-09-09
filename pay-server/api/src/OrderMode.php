<?php

namespace ElektronNet\Payments\PayServer;

/**
 * `orders.mode` (section 4). Escrow is real, present code end to end, but
 * stays unreachable through the API while Config::escrowEnabled() is
 * false -- see section 20 and Http\Controllers\OrdersController.
 */
final class OrderMode
{
    public const DIRECT = 'direct';
    public const ESCROW = 'escrow';

    private function __construct()
    {
    }
}
