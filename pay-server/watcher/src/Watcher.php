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
    private ChainDataProviderFactory $chainDataFactory;
    private OrderRepository $orders;

    public function __construct(PDO $pdo, ChainDataProviderFactory $chainDataFactory, OrderRepository $orders)
    {
        $this->pdo = $pdo;
        $this->chainDataFactory = $chainDataFactory;
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
     * Joined with merchants (section 7: per-merchant chain_endpoints
     * override; underpayment_tolerance_percent) so each order is
     * evaluated against its own merchant's configuration without an N+1
     * query per order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchNonTerminalOrders(int $batchSize): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.*,
                    m.chain_endpoints AS merchant_chain_endpoints,
                    m.underpayment_tolerance_percent AS merchant_underpayment_tolerance_percent
             FROM orders o
             JOIN merchants m ON m.id = o.merchant_id
             WHERE o.status IN ('new', 'processing')
             ORDER BY o.created_at ASC
             LIMIT :limit"
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
        $chainData = $this->chainDataFactory->forMerchant(
            $row['merchant_chain_endpoints'] !== null ? (string) $row['merchant_chain_endpoints'] : null
        );

        $outputs = $chainData->getFundingOutputs((string) $row['address']);
        $receivedLep = array_sum(array_map(fn ($output) => $output->amountLep(), $outputs));
        $requiredLep = (int) $row['amount_lep'];

        if ($receivedLep <= 0) {
            if ($now > $expiresAt && $status === OrderStatus::NEW) {
                $this->transition($orderId, OrderStatus::EXPIRED, ['reason' => 'expired_with_no_payment']);
            }
            return;
        }

        $confirmations = $this->minConfirmations($chainData, $outputs);
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

            $tolerancePercent = (float) ($row['merchant_underpayment_tolerance_percent'] ?? 0);
            $shortfallPercent = (($requiredLep - $receivedLep) / $requiredLep) * 100;
            if ($tolerancePercent > 0 && $shortfallPercent <= $tolerancePercent) {
                $this->transition($orderId, OrderStatus::SETTLED, [
                    'received_lep' => $receivedLep,
                    'confirmations' => $confirmations,
                    'underpaid_within_tolerance' => true,
                    'shortfall_percent' => round($shortfallPercent, 4),
                ]);
                return;
            }

            // Confirmed but short beyond the merchant's own tolerance:
            // stays processing (the merchant's admin order view still
            // shows the received amount via this event) rather than
            // auto-settling or auto-invalidating -- resolving a genuine
            // underpayment beyond tolerance is a merchant decision this
            // slice does not make for them.
            if ($status === OrderStatus::NEW) {
                $this->transition($orderId, OrderStatus::PROCESSING, ['received_lep' => $receivedLep]);
            }
            return;
        }

        if ($confirmations < $requiredConfirmations) {
            if ($status === OrderStatus::NEW) {
                $this->transition($orderId, OrderStatus::PROCESSING, ['received_lep' => $receivedLep]);
            }
            return;
        }

        $settledPayload = [
            'received_lep' => $receivedLep,
            'confirmations' => $confirmations,
        ];
        // Section 13: "Overpayment is always accepted as settled; the
        // excess amount MUST be flagged clearly to the merchant (surfaced
        // via order_events and the admin order view) since it is the
        // natural trigger for the refund flow in section 15."
        if ($receivedLep > $requiredLep) {
            $settledPayload['overpaid'] = true;
            $settledPayload['overpayment_lep'] = $receivedLep - $requiredLep;
        }

        $this->transition($orderId, OrderStatus::SETTLED, $settledPayload);
    }

    /**
     * @param array<int, mixed> $outputs
     */
    private function minConfirmations(ChainDataProviderInterface $chainData, array $outputs): int
    {
        if ($outputs === []) {
            return 0;
        }

        $min = null;
        foreach ($outputs as $output) {
            $confirmations = $chainData->getConfirmations($output->txid());
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
