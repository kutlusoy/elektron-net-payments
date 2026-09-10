<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;
use PDOException;

/**
 * Section 11 checklist: append-only, permanently -- no update()/delete()
 * method exists here, on purpose, matching "no PUT/DELETE on a posted
 * message, at the API layer or anywhere else; once written, a message
 * stays exactly as written."
 */
final class OrderMessageRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * $sender MUST already be resolved server-side from the caller's own
     * identity (Bearer key -> 'merchant', unauthenticated capability-token
     * access -> 'buyer') -- never accepted as client input, so a buyer can
     * never post a message that later reads as having come from the
     * merchant, or vice versa.
     */
    public function create(string $orderId, string $sender, string $body): OrderMessage
    {
        $id = $this->generateUuid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_messages (id, order_id, sender, body) VALUES (:id, :order_id, :sender, :body)'
        );
        $stmt->execute([
            'id' => $id,
            'order_id' => $orderId,
            'sender' => $sender,
            'body' => $body,
        ]);

        $stmt = $this->pdo->prepare('SELECT * FROM order_messages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PDOException("Order message {$id} could not be re-read immediately after insert.");
        }

        return OrderMessage::fromRow($row);
    }

    /**
     * Section 11: "Both sides read the same thread ... there is exactly
     * one log per order, not a separate copy per side" -- this is the
     * single method both the buyer-facing and merchant-facing read paths
     * call.
     *
     * @return OrderMessage[]
     */
    public function findByOrder(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_messages WHERE order_id = :order_id ORDER BY created_at ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        return array_map(fn (array $row) => OrderMessage::fromRow($row), $stmt->fetchAll());
    }

    /**
     * Section 11 checklist: "Basic rate limiting on POST .../messages to
     * prevent spamming the log." A naive per-order count over a short
     * window is enough for what this is protecting against (one party
     * flooding a single order's thread), not a general API rate limiter.
     */
    public function countRecentByOrder(string $orderId, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT count(*) FROM order_messages WHERE order_id = :order_id AND created_at > now() - (:window_seconds || ' seconds')::interval"
        );
        $stmt->execute(['order_id' => $orderId, 'window_seconds' => $windowSeconds]);

        return (int) $stmt->fetchColumn();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
