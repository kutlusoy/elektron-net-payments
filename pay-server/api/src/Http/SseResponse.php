<?php

namespace ElektronNet\Payments\PayServer\Http;

use ElektronNet\Payments\PayServer\Db\Order;
use ElektronNet\Payments\PayServer\OrderStatus;

/**
 * Server-Sent Events stream for live order-status push (section 10.C):
 * "pushed from pay-api whenever the watcher transitions that order;
 * plain polling remains as the fallback for any client or network that
 * blocks SSE". This implementation polls the database itself on the same
 * process rather than subscribing to a message bus -- reasonable given
 * pay-watcher and pay-api do not yet share one (see pay-server/README.MD's
 * open items); a future webhook/pub-sub-driven push can replace the
 * sleep() loop below without changing this class's public contract or the
 * checkout page's EventSource usage.
 */
final class SseResponse implements Responder
{
    /** @var \Closure(): ?Order */
    private \Closure $fetchOrder;
    private string $merchantLabel;
    private int $pollIntervalSeconds;
    private int $maxDurationSeconds;

    /**
     * @param \Closure(): ?Order $fetchOrder
     */
    public function __construct(
        \Closure $fetchOrder,
        string $merchantLabel,
        int $pollIntervalSeconds = 2,
        int $maxDurationSeconds = 290
    ) {
        $this->fetchOrder = $fetchOrder;
        $this->merchantLabel = $merchantLabel;
        $this->pollIntervalSeconds = $pollIntervalSeconds;
        $this->maxDurationSeconds = $maxDurationSeconds;
    }

    public function send(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        set_time_limit(0);

        $lastStatus = null;
        $start = time();

        while (true) {
            $order = ($this->fetchOrder)();

            if ($order === null) {
                $this->emit('error', ['error' => 'not_found']);
                return;
            }

            if ($order->status !== $lastStatus) {
                $this->emit('status', $order->toPublicArray($this->merchantLabel));
                $lastStatus = $order->status;
            } else {
                // Comment-only ping: keeps intermediate proxies/load
                // balancers from closing an idle connection.
                echo ": ping\n\n";
                flush();
            }

            if (OrderStatus::isTerminal($order->status)) {
                return;
            }
            if (connection_aborted()) {
                return;
            }
            if (time() - $start > $this->maxDurationSeconds) {
                // EventSource reconnects automatically on a closed stream;
                // the checkout page also falls back to plain polling if
                // that reconnect itself fails (section 10.C).
                $this->emit('timeout', []);
                return;
            }

            sleep($this->pollIntervalSeconds);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        flush();
    }
}
