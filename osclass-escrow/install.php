<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Runs once when the plugin is activated (see index.php's
 * osc_register_plugin() call). Creates the plugin's own order table and
 * seeds the admin-settings preferences with the suite-wide defaults.
 *
 * Nothing here touches Osclass's own tables (t_item, t_user, ...); the
 * order/escrow record is entirely this plugin's own storage, since Osclass
 * has no built-in order/checkout concept to hook into.
 */
function elektron_escrow_install()
{
    $dao = EscrowOrderDAO::newInstance();
    $dao->dao->query(
        'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 'elektron_escrow_order (
            pk_i_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            fk_i_item_id INT(10) UNSIGNED NOT NULL,
            fk_i_buyer_id INT(10) UNSIGNED NOT NULL,
            fk_i_seller_id INT(10) UNSIGNED NOT NULL,
            buyer_pubkey VARCHAR(66) NOT NULL,
            seller_pubkey VARCHAR(66) NOT NULL,
            s_address VARCHAR(90) NOT NULL,
            redeem_script_hex TEXT NOT NULL,
            buyer_refund_locktime INT(10) UNSIGNED NOT NULL,
            seller_release_locktime INT(10) UNSIGNED NOT NULL,
            amount_lep BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT \'awaiting_payment\',
            psbt_base64 MEDIUMTEXT NULL,
            psbt_signature_count TINYINT(1) NOT NULL DEFAULT 0,
            dt_pub_date DATETIME NOT NULL,
            dt_mod_date DATETIME NULL,

            PRIMARY KEY (pk_i_id),
            INDEX idx_item (fk_i_item_id),
            INDEX idx_buyer (fk_i_buyer_id),
            INDEX idx_seller (fk_i_seller_id),
            INDEX idx_status (status),
            INDEX idx_s_address (s_address)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET \'UTF8\' COLLATE \'UTF8_GENERAL_CI\''
    );

    $dao->dao->query(
        'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 'elektron_escrow_wallet (
            fk_i_user_id INT(10) UNSIGNED NOT NULL,
            pubkey_hex VARCHAR(66) NOT NULL,
            dt_pub_date DATETIME NOT NULL,

            PRIMARY KEY (fk_i_user_id)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET \'UTF8\' COLLATE \'UTF8_GENERAL_CI\''
    );

    elektron_escrow_set_default_preferences();
}

/**
 * Writes every admin-settings default, but only for a preference that does
 * not already have a value; an existing installation's saved values are
 * never overwritten. Only called at install time, since every field always
 * has a value after that (admin/settings.php always writes every field on
 * save, even one left as its current default).
 */
function elektron_escrow_set_default_preferences()
{
    $defaults = [
        // Free-text list; the admin UI can add/remove/reorder entries, the
        // plugin tries them in order and fails over on timeout or error
        // (ChainData\FallbackChainDataProvider in the shared core).
        'chain_data_endpoints' => "https://mempool.elektron-net.org/api\nhttps://mempool2.elektron-net.org/api",
        'required_confirmations' => '3',
        'fee_rate_lep_per_vbyte' => '10',
        'buyer_refund_days' => (string) \ElektronNet\Payments\Core\Escrow\TimeoutPolicy::DEFAULT_BUYER_REFUND_DAYS,
        'seller_release_days' => (string) \ElektronNet\Payments\Core\Escrow\TimeoutPolicy::DEFAULT_SELLER_RELEASE_DAYS,
        'network' => 'mainnet',
    ];

    foreach ($defaults as $name => $value) {
        if (osc_get_preference($name, 'plugin-osclass-escrow') === '') {
            Preference::newInstance()->insert([
                's_section' => 'plugin-osclass-escrow',
                's_name' => $name,
                's_value' => $value,
                'e_type' => 'STRING',
            ]);
        }
    }
}

function elektron_escrow_uninstall()
{
    Preference::newInstance()->delete(['s_section' => 'plugin-osclass-escrow']);
    // The order table is deliberately kept on uninstall (it is transaction
    // history, not configuration); a marketplace operator who wants it gone
    // drops elektron_escrow_order manually.
}
