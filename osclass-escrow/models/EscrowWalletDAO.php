<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Stores the one Elektron Net xpub each Osclass user has connected
 * (README, "One-time wallet setup"). Only ever a public key: the private
 * key never leaves the user's own wallet software and is never submitted
 * to, or stored by, this plugin.
 *
 * next_index is a per-user counter, not a per-order one: it is incremented
 * every time XpubChildKeyDeriver derives a fresh child key for this user
 * (as either buyer or seller), so the same child index is never reused
 * across two different orders -- see PlainMultisigEscrowScriptBuilder's
 * docblock for why that guarantee now lives here instead of in the script.
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
        $this->setFields(['fk_i_user_id', 'xpub', 'next_index', 'dt_pub_date']);
    }

    public function getXpub(int $userId): ?string
    {
        $row = $this->findByPrimaryKey($userId);

        return $row ? $row['xpub'] : null;
    }

    public function setXpub(int $userId, string $xpub): void
    {
        if ($this->findByPrimaryKey($userId)) {
            // A genuine key change resets the counter: the new xpub has never
            // had any child derived from it yet.
            $this->updateByPrimaryKey(['xpub' => $xpub, 'next_index' => 0], $userId);
            return;
        }

        $this->insert([
            'fk_i_user_id' => $userId,
            'xpub' => $xpub,
            'next_index' => 0,
            'dt_pub_date' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Allocates and returns the next unused derivation index for this
     * user's currently-registered xpub, then advances the counter so it is
     * never handed out again. Callers MUST persist the resulting order
     * before allocating another index for the same user, or two
     * in-progress orders could momentarily believe they share an index --
     * see controllers/checkout.php for the actual call site.
     */
    public function allocateNextIndex(int $userId): int
    {
        $row = $this->findByPrimaryKey($userId);
        if (!$row) {
            throw new RuntimeException("allocateNextIndex() called for user {$userId} with no connected wallet.");
        }

        $index = (int) $row['next_index'];
        $this->updateByPrimaryKey(['next_index' => $index + 1], $userId);

        return $index;
    }
}
