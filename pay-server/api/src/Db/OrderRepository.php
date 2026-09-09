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
    /**
     * $fiatCurrency/$fiatAmount/$exchangeRateUsed are set together, only
     * when this order was priced in fiat and converted to $amountLep at
     * creation time (section 12); the rate is frozen here permanently,
     * never recomputed afterward, exactly like T1/T2 are frozen elsewhere
     * in this design.
     */
    public function insertDirectOrder(
        string $merchantId,
        int $amountLep,
        string $address,
        ?string $externalReference,
        string $expiresAt,
        int $requiredConfirmations,
        ?string $fiatCurrency = null,
        ?float $fiatAmount = null,
        ?float $exchangeRateUsed = null
    ): Order {
        $id = $this->generateUuid();
        $nonceHex = bin2hex(random_bytes(16));

        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (
                id, merchant_id, mode, status, amount_lep, address,
                order_nonce_hex, external_reference, expires_at, required_confirmations,
                fiat_currency, fiat_amount, exchange_rate_used
            ) VALUES (
                :id, :merchant_id, :mode, :status, :amount_lep, :address,
                :order_nonce_hex, :external_reference, :expires_at, :required_confirmations,
                :fiat_currency, :fiat_amount, :exchange_rate_used
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
            'fiat_currency' => $fiatCurrency,
            'fiat_amount' => $fiatAmount,
            'exchange_rate_used' => $exchangeRateUsed,
        ]);

        $order = $this->find($id);
        if ($order === null) {
            throw new PDOException("Order {$id} could not be re-read immediately after insert.");
        }

        $this->recordEvent($id, 'created', ['mode' => 'direct']);

        return $order;
    }

    /**
     * Admin order list (section 21), most recent first.
     *
     * @return Order[]
     */
    public function findByMerchant(string $merchantId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders WHERE merchant_id = :merchant_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('merchant_id', $merchantId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => Order::fromRow($row), $stmt->fetchAll());
    }

    public function countByMerchant(string $merchantId): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM orders WHERE merchant_id = :merchant_id');
        $stmt->execute(['merchant_id' => $merchantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Order detail page's event log (section 4: order_events doubles as
     * the audit trail).
     *
     * @return array<int, array{type: string, payload: array<string, mixed>, created_at: string}>
     */
    public function eventsForOrder(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT type, payload, created_at FROM order_events WHERE order_id = :order_id ORDER BY created_at ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        return array_map(function (array $row) {
            $payload = json_decode((string) $row['payload'], true);
            return [
                'type' => (string) $row['type'],
                'payload' => is_array($payload) ? $payload : [],
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll());
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
