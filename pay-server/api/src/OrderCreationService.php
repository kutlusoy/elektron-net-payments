<?php

namespace ElektronNet\Payments\PayServer;

use ElektronNet\Payments\Core\PriceFeed\PriceFeedProviderInterface;
use ElektronNet\Payments\PayServer\Db\Merchant;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
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
    private ?PriceFeedProviderInterface $priceFeed;

    public function __construct(
        PDO $pdo,
        MerchantRepository $merchants,
        OrderRepository $orders,
        OrderAddressAllocator $addressAllocator,
        bool $escrowEnabled,
        ?PriceFeedProviderInterface $priceFeed = null
    ) {
        $this->pdo = $pdo;
        $this->merchants = $merchants;
        $this->orders = $orders;
        $this->addressAllocator = $addressAllocator;
        $this->escrowEnabled = $escrowEnabled;
        $this->priceFeed = $priceFeed;
    }

    /**
     * @param array<string, mixed> $payload same shape as POST /v1/orders' body
     * @param string|null $paymentRequestId section 16: set when this order was spawned from a payment request
     * @throws Http\ApiException on any validation failure (typed error codes, see OrderValidation)
     */
    public function createDirectOrder(Merchant $merchant, array $payload, ?string $paymentRequestId = null): OrderCreationResult
    {
        OrderValidation::validateCreateOrderPayload($payload, $merchant, $this->escrowEnabled, $this->priceFeed !== null);

        $currency = isset($payload['currency']) ? (string) $payload['currency'] : $merchant->baseCurrency;
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

            $fiatCurrency = null;
            $fiatAmount = null;
            $exchangeRateUsed = null;

            if ($currency === 'ELEK') {
                $amountLep = (int) round(((float) $payload['amount']) * OrderRepository::LEP_PER_ELEK);
            } else {
                // Section 12's fiat-as-base-currency freeze: converted via
                // the feed and permanently frozen into amount_lep here,
                // never recomputed afterward even if the market rate
                // moves before the order is paid. OrderValidation already
                // confirmed a price feed is configured and this merchant
                // enabled $currency, so $this->priceFeed is non-null here.
                $fiatAmount = (float) $payload['amount'];
                $rate = $this->priceFeed->getElekPriceInFiat($currency);
                if ($rate === null || $rate <= 0) {
                    throw ApiException::validationError(
                        "No {$currency} rate is available right now; try again shortly or use ELEK directly.",
                        'price_unavailable'
                    );
                }
                $fiatCurrency = $currency;
                $exchangeRateUsed = $rate;
                $amountLep = (int) round(($fiatAmount / $rate) * OrderRepository::LEP_PER_ELEK);
                if ($amountLep <= 0) {
                    throw ApiException::validationError('Converted amount must be greater than zero.');
                }
            }

            $expiresAt = (new \DateTimeImmutable())
                ->modify("+{$merchant->orderExpiryMinutes} minutes")
                ->format(DATE_ATOM);

            $order = $this->orders->insertDirectOrder(
                $merchant->id,
                $amountLep,
                $address,
                $externalReference,
                $expiresAt,
                $merchant->defaultRequiredConfirmations,
                $fiatCurrency,
                $fiatAmount,
                $exchangeRateUsed,
                $paymentRequestId
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return new OrderCreationResult($order, false);
    }
}
