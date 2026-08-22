<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Stores the one Elektron Net public key each Osclass user has connected
 * (README, "One-time wallet setup"). Only ever a public key: the private
 * key never leaves the user's own wallet software and is never submitted
 * to, or stored by, this plugin.
 */
class EscrowWalletDAO extends DAO
{
    /** @var EscrowWalletDAO */
    private static $instance;

    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('elektron_escrow_wallet');
        $this->setPrimaryKey('fk_i_user_id');
        $this->setFields(['fk_i_user_id', 'pubkey_hex', 'dt_pub_date']);
    }

    public function getPubKey(int $userId): ?string
    {
        $row = $this->findByPrimaryKey($userId);

        return $row ? $row['pubkey_hex'] : null;
    }

    public function setPubKey(int $userId, string $pubKeyHex): void
    {
        if ($this->findByPrimaryKey($userId)) {
            $this->updateByPrimaryKey(['pubkey_hex' => $pubKeyHex], $userId);
            return;
        }

        $this->insert([
            'fk_i_user_id' => $userId,
            'pubkey_hex' => $pubKeyHex,
            'dt_pub_date' => date('Y-m-d H:i:s'),
        ]);
    }
}
