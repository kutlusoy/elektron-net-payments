<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use BitWasp\Bitcoin\Network\Network;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;
use ElektronNet\Payments\PayServer\Auth\ApiKeyAuthenticator;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\JsonResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\OrderAddressAllocator;
use ElektronNet\Payments\PayServer\OrderValidation;
use PDO;

/**
 * `POST /v1/orders` and `GET /v1/orders/{id}` (section 5), direct mode
 * only for now -- section 25's own build order names this the first slice
 * ("direct mode first (simplest case), then escrow mode"). Escrow's own
 * order-creation path is intentionally not implemented yet; see
 * OrderValidation for exactly where it is gated off.
 */
final class OrdersController
{
    private PDO $pdo;
    private ApiKeyAuthenticator $auth;
    private MerchantRepository $merchants;
    private OrderRepository $orders;
    private OrderAddressAllocator $addressAllocator;
    private bool $escrowEnabled;
    private string $checkoutBaseUrl;

    public function __construct(
        PDO $pdo,
        ApiKeyAuthenticator $auth,
        MerchantRepository $merchants,
        OrderRepository $orders,
        OrderAddressAllocator $addressAllocator,
        bool $escrowEnabled,
        string $checkoutBaseUrl
    ) {
        $this->pdo = $pdo;
        $this->auth = $auth;
        $this->merchants = $merchants;
        $this->orders = $orders;
        $this->addressAllocator = $addressAllocator;
        $this->escrowEnabled = $escrowEnabled;
        $this->checkoutBaseUrl = $checkoutBaseUrl;
    }

    public function create(Request $request): JsonResponse
    {
        $key = $this->auth->authenticate($request->bearerToken());
        $this->auth->requireScope($key, 'orders:create');

        $merchant = $this->merchants->find($key->merchantId);
        if ($merchant === null) {
            throw ApiException::notFound('Merchant not found.');
        }

        OrderValidation::validateCreateOrderPayload($request->body, $merchant, $this->escrowEnabled);

        $externalReference = isset($request->body['external_reference'])
            ? (string) $request->body['external_reference']
            : null;

        $this->pdo->beginTransaction();
        try {
            // Section 4's compare-and-set insert: with the merchant row
            // locked (claimNextReceivingIndex() below issues the lock),
            // re-check for an already-open order under the same
            // (merchant, external_reference) before creating a new one, so
            // a client's retry-on-timeout returns the existing order
            // instead of a duplicate.
            if ($externalReference !== null) {
                $existing = $this->orders->findOpenByMerchantAndExternalReference($merchant->id, $externalReference);
                if ($existing !== null) {
                    $this->pdo->commit();
                    return new JsonResponse(200, $existing->toApiArray($this->checkoutBaseUrl));
                }
            }

            $childIndex = $this->merchants->claimNextReceivingIndex($merchant->id);
            $address = $this->addressAllocator->deriveDirectAddress($merchant->receivingXpub, $childIndex);

            $amountLep = (int) round(((float) $request->body['amount']) * OrderRepository::LEP_PER_ELEK);
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
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return new JsonResponse(201, $order->toApiArray($this->checkoutBaseUrl));
    }

    public function get(Request $request): JsonResponse
    {
        $key = $this->auth->authenticate($request->bearerToken());
        $this->auth->requireScope($key, 'orders:read');

        $order = $this->orders->find($request->params['id']);
        if ($order === null || $order->merchantId !== $key->merchantId) {
            throw ApiException::notFound('Order not found.');
        }

        return new JsonResponse(200, $order->toApiArray($this->checkoutBaseUrl));
    }
}
