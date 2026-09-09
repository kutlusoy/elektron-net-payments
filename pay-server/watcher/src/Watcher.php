<?php

namespace ElektronNet\Payments\PayWatcher;

use ElektronNet\Payments\Core\ChainData\ChainDataProviderInterface;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\OrderStatus;
use PDO;

/**
 * `pay-watcher` (section 6): a separate long-running process, not a cron
 * job and not triggered by web requests, so status updates happen
 * regardless of storefront traffic. This implements the direct-mode
 * lifecycle transitions (new -> processing -> settled, or -> expired);
 * escrow-specific T1/T2 reminder dispatch (section 6, `ReminderScheduler`)
 * is not wired in yet -- no order can carry a non-null t1_seconds/
 * t2_seconds while escrow stays disabled (section 20), so there is
 * nothing for it to act on yet.
 *
 * Webhook delivery (section 5) is not implemented yet either: every
 * transition is still written to order_events (the delivery log section 4
 * describes), so wiring up the actual HTTP delivery loop later does not
 * require touching this class's transition logic.
 */
final class Watcher
{
    private PDO $pdo;
    private ChainDataProviderInterface $chainData;
    private OrderRepository $orders;

    public function __construct(PDO $pdo, ChainDataProviderInterface $chainData, OrderRepository $orders)
    {
        $this->pdo = $pdo;
        $this->chainData = $chainData;
        $this->orders = $orders;
    }

    /**
     * One polling pass over every non-terminal order. The caller (bin/watch.php)
     * is responsible for looping this on a configurable interval
     * (section 6 checklist: "poll interval and batch size MUST be
     * configurable").
     *
     * @param int $batchSize
     */
    public function pollOnce(int $batchSize): void
    {
        foreach ($this->fetchNonTerminalOrders($batchSize) as $order) {
            $this->evaluateOrder($order);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchNonTerminalOrders(int $batchSize): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM orders WHERE status IN ('new', 'processing') ORDER BY created_at ASC LIMIT :limit"
        );
        $stmt->bindValue('limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function evaluateOrder(array $row): void
    {
        $orderId = (string) $row['id'];
        $status = (string) $row['status'];
        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        $now = new \DateTimeImmutable();

        $outputs = $this->chainData->getFundingOutputs((string) $row['address']);
        $receivedLep = array_sum(array_map(fn ($output) => $output->amountLep(), $outputs));
        $requiredLep = (int) $row['amount_lep'];

        if ($receivedLep <= 0) {
            if ($now > $expiresAt && $status === OrderStatus::NEW) {
                $this->transition($orderId, OrderStatus::EXPIRED, ['reason' => 'expired_with_no_payment']);
            }
            return;
        }

        $confirmations = $this->minConfirmations($outputs);
        $requiredConfirmations = (int) $row['required_confirmations'];

        if ($receivedLep < $requiredLep) {
            // Underpayment tolerance (section 13) is evaluated once the
            // required confirmation depth is reached, not before -- an
            // unconfirmed partial payment might still be topped up.
            if ($confirmations < $requiredConfirmations) {
                if ($status === OrderStatus::NEW) {
                    $this->transition($orderId, OrderStatus::PROCESSING, ['received_lep' => $receivedLep]);
                }
                return;
            }
            // Underpayment tolerance percentage lives on the merchant
            // record; the watcher's DB access here is kept to the orders
            // table only for this first slice, so a short amount is
            // treated as processing rather than settled or invalid until
            // the tolerance check is wired in.
            return;
        }

        if ($confirmations < $requiredConfirmations) {
            if ($status === OrderStatus::NEW) {
                $this->transition($orderId, OrderStatus::PROCESSING, ['received_lep' => $receivedLep]);
            }
            return;
        }

        $this->transition($orderId, OrderStatus::SETTLED, [
            'received_lep' => $receivedLep,
            'confirmations' => $confirmations,
        ]);
    }

    /**
     * @param array<int, mixed> $outputs
     */
    private function minConfirmations(array $outputs): int
    {
        if ($outputs === []) {
            return 0;
        }

        $min = null;
        foreach ($outputs as $output) {
            $confirmations = $this->chainData->getConfirmations($output->txid());
            $min = $min === null ? $confirmations : min($min, $confirmations);
        }

        return $min ?? 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function transition(string $orderId, string $newStatus, array $payload): void
    {
        $isTerminal = OrderStatus::isTerminal($newStatus);
        $stmt = $this->pdo->prepare(
            $isTerminal
                ? 'UPDATE orders SET status = :status, completed_at = now() WHERE id = :id'
                : 'UPDATE orders SET status = :status WHERE id = :id'
        );
        $stmt->execute(['status' => $newStatus, 'id' => $orderId]);

        $this->orders->recordEvent($orderId, $newStatus, $payload);

        // Webhook dispatch belongs here once implemented (section 5:
        // "Every event lands in order_events before delivery is
        // attempted, so a failed delivery can be retried from the log").
    }
}
