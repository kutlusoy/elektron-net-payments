<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * DAO for the plugin's own order table (t_elektron_escrow_order). Never
 * touches Osclass's native t_item table beyond reading an item's id, price,
 * and owner; the order/escrow record lives entirely in this plugin's own
 * storage, since Osclass has no built-in order/checkout concept.
 *
 * Column-to-core mapping: `status` stores one of the
 * ElektronNet\Payments\Core\Escrow\OrderStatus constants; `redeem_script_hex`,
 * `buyer_refund_locktime`, and `seller_release_locktime` are copied verbatim
 * from the EscrowAddress returned at order creation and are never
 * recomputed later (see shared/README.md).
 */
class EscrowOrderDAO extends DAO
{
    /** @var EscrowOrderDAO */
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
        $this->setTableName('elektron_escrow_order');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields([
            'pk_i_id',
            'fk_i_item_id',
            'fk_i_buyer_id',
            'fk_i_seller_id',
            'buyer_pubkey',
            'seller_pubkey',
            's_address',
            'redeem_script_hex',
            'buyer_refund_locktime',
            'seller_release_locktime',
            'amount_lep',
            'status',
            'psbt_base64',
            'psbt_signature_count',
            'dt_pub_date',
            'dt_mod_date',
        ]);
    }

    /**
     * @return array|null the single matching order row, or null if this
     *     buyer has not started one for this item yet
     */
    public function findByItemAndBuyer(int $itemId, int $buyerId): ?array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_item_id', $itemId);
        $this->dao->where('fk_i_buyer_id', $buyerId);
        $result = $this->dao->get();

        if ($result === false || $result->numRows() !== 1) {
            return null;
        }

        return $result->row();
    }

    /**
     * Every order still waiting on the buyer's own confirmation, i.e. one
     * whose buyer_refund_locktime the daily cron sweep needs to check
     * against Notifications\ReminderScheduler. Not filtered by how close
     * that threshold is; the scheduler decides that per order.
     *
     * @return array[] list of order rows
     */
    public function findFunded(): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('status', 'funded');
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }

    /**
     * Every order that could still end in an unilateral seller release,
     * i.e. one whose seller_release_locktime the daily cron sweep needs to
     * check against Notifications\ReminderScheduler.
     *
     * @return array[] list of order rows
     */
    public function findOpenForSellerRelease(): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->whereIn('status', ['funded', 'release_pending_seller_signature']);
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }
}
