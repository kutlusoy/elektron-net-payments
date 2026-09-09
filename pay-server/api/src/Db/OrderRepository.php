<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;
use PDOException;

final class OrderRepository
{
    /**
     * Base units per ELEK, confirmed against elektron-net's own
     * src/consensus/amount.h (`static constexpr CAmount COIN = 100000000;`),
     * the same 8-decimal scale Bitcoin uses -- Elektron Net is a parameter
     * fork, not a full protocol fork (see core/README.md, "Chain
     * parameters").
     */
    public const LEP_PER_ELEK = 100_000_000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function find(string $orderId): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch();

        return $row === false ? null : Order::fromRow($row);
    }

    /**
     * The compare-and-set half of section 4's double-submit fix: if a
     * non-terminal order already exists for this (merchant, external
     * reference), an idempotent retry MUST return that same order instead
     * of creating a second one. The database's partial unique index
     * (uq_orders_merchant_external_ref_open, migrations/0001_init.sql) is
     * the actual race-closer; this is the read-side half a client's retry
     * needs.
     */
    public function findOpenByMerchantAndExternalReference(string $merchantId, string $externalReference): ?Order
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM orders
             WHERE merchant_id = :merchant_id
               AND external_reference = :external_reference
               AND status IN ('new', 'processing')
             LIMIT 1"
        );
        $stmt->execute([
            'merchant_id' => $merchantId,
            'external_reference' => $externalReference,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : Order::fromRow($row);
    }

    /**
     * @throws PDOException on any constraint violation other than the
     *     external-reference race (SQLSTATE 23505 on
     *     uq_orders_merchant_external_ref_open), which the caller is
     *     expected to have already ruled out via
     *     findOpenByMerchantAndExternalReference() inside the same
     *     transaction.
     */
    public function insertDirectOrder(
        string $merchantId,
        int $amountLep,
        string $address,
        ?string $externalReference,
        string $expiresAt,
        int $requiredConfirmations
    ): Order {
        $id = $this->generateUuid();
        $nonceHex = bin2hex(random_bytes(16));

        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (
                id, merchant_id, mode, status, amount_lep, address,
                order_nonce_hex, external_reference, expires_at, required_confirmations
            ) VALUES (
                :id, :merchant_id, :mode, :status, :amount_lep, :address,
                :order_nonce_hex, :external_reference, :expires_at, :required_confirmations
            )'
        );
        $stmt->execute([
            'id' => $id,
            'merchant_id' => $merchantId,
            'mode' => 'direct',
            'status' => 'new',
            'amount_lep' => $amountLep,
            'address' => $address,
            'order_nonce_hex' => $nonceHex,
            'external_reference' => $externalReference,
            'expires_at' => $expiresAt,
            'required_confirmations' => $requiredConfirmations,
        ]);

        $order = $this->find($id);
        if ($order === null) {
            throw new PDOException("Order {$id} could not be re-read immediately after insert.");
        }

        $this->recordEvent($id, 'created', ['mode' => 'direct']);

        return $order;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(string $orderId, string $type, array $payload = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_events (id, order_id, type, payload) VALUES (:id, :order_id, :type, :payload)'
        );
        $stmt->execute([
            'id' => $this->generateUuid(),
            'order_id' => $orderId,
            'type' => $type,
            'payload' => json_encode($payload),
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
