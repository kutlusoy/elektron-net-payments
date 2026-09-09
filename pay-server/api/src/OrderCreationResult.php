<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Db\Order;

final class OrderCreationResult
{
    public Order $order;

    /** True if this call returned an already-open order instead of creating a new one (idempotent retry, section 4). */
    public bool $wasExisting;

    public function __construct(Order $order, bool $wasExisting)
    {
        $this->order = $order;
        $this->wasExisting = $wasExisting;
    }
}
