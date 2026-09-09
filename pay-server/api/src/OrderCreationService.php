<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\PayServer\Db\Merchant;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use PDO;
use Throwable;

/**
 * Direct-mode order creation (section 5, section 25's "direct mode first"
 * priority), shared between `POST /v1/orders` (Http\Controllers\OrdersController,
 * Bearer-token authenticated) and the admin dashboard's own "new order"
 * form (Http\Controllers\Admin\DashboardController, session
 * authenticated) -- two different auth models in front of exactly the
 * same validation, idempotency, and address-allocation logic, so that
 * logic is written and transaction-scoped once rather than kept in sync
 * by hand in two controllers.
 */
final class OrderCreationService
{
    private PDO $pdo;
    private MerchantRepository $merchants;
    private OrderRepository $orders;
    private OrderAddressAllocator $addressAllocator;
    private bool $escrowEnabled;

    public function __construct(
        PDO $pdo,
        MerchantRepository $merchants,
        OrderRepository $orders,
        OrderAddressAllocator $addressAllocator,
        bool $escrowEnabled
    ) {
        $this->pdo = $pdo;
        $this->merchants = $merchants;
        $this->orders = $orders;
        $this->addressAllocator = $addressAllocator;
        $this->escrowEnabled = $escrowEnabled;
    }

    /**
     * @param array<string, mixed> $payload same shape as POST /v1/orders' body
     * @throws Http\ApiException on any validation failure (typed error codes, see OrderValidation)
     */
    public function createDirectOrder(Merchant $merchant, array $payload): OrderCreationResult
    {
        OrderValidation::validateCreateOrderPayload($payload, $merchant, $this->escrowEnabled);

        $externalReference = isset($payload['external_reference']) ? (string) $payload['external_reference'] : null;

        $this->pdo->beginTransaction();
        try {
            // Section 4's compare-and-set insert: with the merchant row
            // locked (claimNextReceivingIndex() below issues the lock),
            // re-check for an already-open order under the same
            // (merchant, external_reference) before creating a new one, so
            // a caller's retry-on-timeout returns the existing order
            // instead of a duplicate.
            if ($externalReference !== null) {
                $existing = $this->orders->findOpenByMerchantAndExternalReference($merchant->id, $externalReference);
                if ($existing !== null) {
                    $this->pdo->commit();
                    return new OrderCreationResult($existing, true);
                }
            }

            $childIndex = $this->merchants->claimNextReceivingIndex($merchant->id);
            $address = $this->addressAllocator->deriveDirectAddress($merchant->receivingXpub, $childIndex);

            $amountLep = (int) round(((float) $payload['amount']) * OrderRepository::LEP_PER_ELEK);
            $expiresAt = (new \DateTimeImmutable())
                ->modify("+{$merchant->orderExpiryMinutes} minutes")
                ->format(DATE_ATOM);

            $order = $this->orders->insertDirectOrder(
                $merchant->id,
                $amountLep,
                $address,
                $externalReference,
                $expiresAt,
                $merchant->defaultRequiredConfirmations
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return new OrderCreationResult($order, false);
    }
}
