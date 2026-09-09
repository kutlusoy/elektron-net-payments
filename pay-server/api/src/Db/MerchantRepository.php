<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;
use RuntimeException;

final class MerchantRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function find(string $merchantId): ?Merchant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM merchants WHERE id = :id');
        $stmt->execute(['id' => $merchantId]);
        $row = $stmt->fetch();

        return $row === false ? null : Merchant::fromRow($row);
    }

    /**
     * Claims the next child-derivation index for this merchant's
     * receiving_xpub and returns it, incrementing the stored counter in
     * the same transaction so the index is never handed out twice --
     * section 9 checklist: "strictly monotonic child-derivation index per
     * xpub, never reused even for a cancelled or expired order". Caller
     * MUST already be inside a transaction; this issues a row lock
     * (SELECT ... FOR UPDATE) to serialize concurrent order creations for
     * the same merchant.
     */
    public function claimNextReceivingIndex(string $merchantId): int
    {
        $stmt = $this->pdo->prepare('SELECT next_receiving_index FROM merchants WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $merchantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("Merchant {$merchantId} not found while claiming a receiving index.");
        }

        $index = (int) $row['next_receiving_index'];

        $update = $this->pdo->prepare('UPDATE merchants SET next_receiving_index = :next WHERE id = :id');
        $update->execute(['next' => $index + 1, 'id' => $merchantId]);

        return $index;
    }
}
