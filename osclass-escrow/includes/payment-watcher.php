<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\OrderStatus;

/**
 * Checks one order's own escrow address against the chain and applies
 * whatever status transition that justifies. Shared by the 'cron_minutely'
 * watcher (includes/hooks.php) and the manual "Check payment now" action on
 * the order-status and "My Elektron orders" pages -- added because
 * Osclass's own auto-cron (`osc_auto_cron()`, `oc-includes/osclass/
 * helpers/hPreference.php`) only ever fires opportunistically at the end of
 * a normal page load, and only if the marketplace operator has it enabled
 * (Admin > Settings) or has a real system cron hitting
 * `index.php?page=cron&type=minutely` instead; on a site with neither, this
 * watcher never runs at all, so a payment can sit fully confirmed on chain
 * with nothing to notice it until someone visits a page that does.
 *
 * Checks total value received *at the order's own address*, never who sent
 * it or from where -- the escrow address itself is what a buyer's payment
 * has to reach; nothing about the connected pubkeys is a "from" address it
 * has to match. An order still short of its full amount_lep is left in
 * awaiting_payment (no partial-payment handling yet, see README, "Open
 * Questions"); once the received total covers it, the order moves to
 * confirming immediately (even at 0 confirmations -- "seen on chain"
 * already, per OrderStatus's own docblock) and to funded once the best
 * confirmation count among its transactions reaches the admin-configured
 * threshold.
 *
 * @param array $order EscrowOrderDAO row
 * @return array the same row, with 'status' (and 'dt_mod_date') updated in
 *     place if this call changed it
 */
function elektron_escrow_check_payment(array $order): array
{
    if (!in_array($order['status'], [OrderStatus::AWAITING_PAYMENT, OrderStatus::CONFIRMING], true)) {
        return $order;
    }

    $chainData = elektron_escrow_chain_data();
    $requiredConfirmations = (int) osc_get_preference('required_confirmations', 'plugin-osclass-escrow');
    $transactions = $chainData->getAddressTransactions($order['s_address']);

    $receivedLep = 0;
    $bestConfirmations = 0;
    foreach ($transactions as $transaction) {
        $receivedLep += $transaction->receivedLep();
        $bestConfirmations = max($bestConfirmations, $transaction->confirmations());
    }

    if ($receivedLep < (int) $order['amount_lep']) {
        return $order; // not (fully) paid yet
    }

    $newStatus = $bestConfirmations >= $requiredConfirmations
        ? OrderStatus::FUNDED
        : OrderStatus::CONFIRMING;

    if ($newStatus === $order['status']) {
        return $order;
    }

    $modDate = date('Y-m-d H:i:s');
    EscrowOrderDAO::newInstance()->updateByPrimaryKey(
        ['status' => $newStatus, 'dt_mod_date' => $modDate],
        (int) $order['pk_i_id']
    );
    $order['status'] = $newStatus;
    $order['dt_mod_date'] = $modDate;

    if ($newStatus === OrderStatus::FUNDED) {
        $item = Item::newInstance()->findByPrimaryKey((int) $order['fk_i_item_id']);
        if ($item !== false) {
            elektron_escrow_send_order_funded_emails($order, $item, ((int) $order['amount_lep']) / 100000000);
        }
    }

    return $order;
}
