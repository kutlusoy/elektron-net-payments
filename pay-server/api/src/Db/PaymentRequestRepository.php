<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;
use PDOException;

final class PaymentRequestRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function find(string $id): ?PaymentRequest
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : PaymentRequest::fromRow($row);
    }

    public function create(
        string $merchantId,
        ?int $amountLep,
        string $currency,
        ?string $description,
        ?string $expiresAt
    ): PaymentRequest {
        $id = $this->generateUuid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO payment_requests (id, merchant_id, amount_lep, currency, description, expires_at)
             VALUES (:id, :merchant_id, :amount_lep, :currency, :description, :expires_at)'
        );
        $stmt->execute([
            'id' => $id,
            'merchant_id' => $merchantId,
            'amount_lep' => $amountLep,
            'currency' => $currency,
            'description' => $description,
            'expires_at' => $expiresAt,
        ]);

        $request = $this->find($id);
        if ($request === null) {
            throw new PDOException("Payment request {$id} could not be re-read immediately after insert.");
        }

        return $request;
    }

    /**
     * Admin list (section 16), most recent first.
     *
     * @return PaymentRequest[]
     */
    public function findByMerchant(string $merchantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payment_requests WHERE merchant_id = :merchant_id ORDER BY created_at DESC'
        );
        $stmt->execute(['merchant_id' => $merchantId]);

        return array_map(fn (array $row) => PaymentRequest::fromRow($row), $stmt->fetchAll());
    }

    public function archive(string $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE payment_requests SET archived_at = now() WHERE id = :id AND archived_at IS NULL');
        $stmt->execute(['id' => $id]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
