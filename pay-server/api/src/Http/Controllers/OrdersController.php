<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use ElektronNet\Payments\PayServer\Auth\ApiKeyAuthenticator;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\JsonResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\OrderCreationService;

/**
 * `POST /v1/orders` and `GET /v1/orders/{id}` (section 5), direct mode
 * only for now -- section 25's own build order names this the first slice
 * ("direct mode first (simplest case), then escrow mode"). Escrow's own
 * order-creation path is intentionally not implemented yet; see
 * OrderValidation for exactly where it is gated off.
 *
 * Order creation itself lives in OrderCreationService, shared with the
 * admin dashboard's own "new order" form -- this controller only adds the
 * Bearer-token auth/scope layer in front of it.
 */
final class OrdersController
{
    private ApiKeyAuthenticator $auth;
    private MerchantRepository $merchants;
    private OrderRepository $orders;
    private OrderCreationService $orderCreation;
    private string $checkoutBaseUrl;

    public function __construct(
        ApiKeyAuthenticator $auth,
        MerchantRepository $merchants,
        OrderRepository $orders,
        OrderCreationService $orderCreation,
        string $checkoutBaseUrl
    ) {
        $this->auth = $auth;
        $this->merchants = $merchants;
        $this->orders = $orders;
        $this->orderCreation = $orderCreation;
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

        $result = $this->orderCreation->createDirectOrder($merchant, $request->body);

        return new JsonResponse(
            $result->wasExisting ? 200 : 201,
            $result->order->toApiArray($this->checkoutBaseUrl)
        );
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
