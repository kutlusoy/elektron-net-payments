<?php

namespace ElektronNet\Payments\PayServer\Db;

use PDO;

final class MerchantUserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByEmail(string $email): ?MerchantUser
    {
        $stmt = $this->pdo->prepare('SELECT * FROM merchant_users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : MerchantUser::fromRow($row);
    }

    public function find(string $id): ?MerchantUser
    {
        $stmt = $this->pdo->prepare('SELECT * FROM merchant_users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : MerchantUser::fromRow($row);
    }
}
