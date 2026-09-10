<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use ElektronNet\Payments\PayServer\Auth\ApiKeyAuthenticator;
use ElektronNet\Payments\PayServer\Db\OrderMessageRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\JsonResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\OrderMessageValidation;

/**
 * `POST`/`GET /v1/orders/{id}/messages` (section 5's table, section 11).
 *
 * Section 5 lists one endpoint pair scoped `orders:messages`, but section
 * 11 is explicit that "the buyer sees it on the order-status page via
 * their order capability token" -- the same unauthenticated, order-id-is-
 * the-credential model every other buyer-facing endpoint in this codebase
 * uses (CheckoutController, PaymentRequestController). Both requirements
 * are true at once: a request with a valid Bearer key scoped
 * `orders:messages` for this order's own merchant is treated as the
 * merchant; a request with no Authorization header at all is treated as
 * the buyer, exactly like the checkout page itself. Either way $sender is
 * resolved here from who is actually calling, never accepted as client
 * input -- a buyer can never post a message that reads as having come
 * from the merchant.
 */
final class OrderMessagesController
{
    private OrderRepository $orders;
    private OrderMessageRepository $messages;
    private ApiKeyAuthenticator $auth;

    /** Section 11 checklist: "basic rate limiting", not a general API limiter. */
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MAX_MESSAGES = 5;

    public function __construct(OrderRepository $orders, OrderMessageRepository $messages, ApiKeyAuthenticator $auth)
    {
        $this->orders = $orders;
        $this->messages = $messages;
        $this->auth = $auth;
    }

    public function post(Request $request): JsonResponse
    {
        $order = $this->orders->find($request->params['id']);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }

        $sender = $this->resolveSender($request, $order->merchantId);

        $recentCount = $this->messages->countRecentByOrder($order->id, self::RATE_LIMIT_WINDOW_SECONDS);
        if ($recentCount >= self::RATE_LIMIT_MAX_MESSAGES) {
            throw new ApiException(429, 'rate_limited', 'Too many messages posted to this order recently; please wait a moment.');
        }

        $body = OrderMessageValidation::validateBody($request->body);
        $message = $this->messages->create($order->id, $sender, $body);

        return new JsonResponse(201, $message->toApiArray());
    }

    public function get(Request $request): JsonResponse
    {
        $order = $this->orders->find($request->params['id']);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }

        // Read access mirrors post(): a matching Bearer key or no
        // Authorization header at all are both fine (section 11: "both
        // sides read the same thread"); a Bearer key present but wrong is
        // still rejected, same as everywhere else Bearer auth is checked.
        $this->resolveSender($request, $order->merchantId);

        $messages = array_map(
            fn ($message) => $message->toApiArray(),
            $this->messages->findByOrder($order->id)
        );

        return new JsonResponse(200, ['messages' => $messages]);
    }

    private function resolveSender(Request $request, string $orderMerchantId): string
    {
        $bearer = $request->bearerToken();
        if ($bearer === null) {
            return 'buyer';
        }

        $key = $this->auth->authenticate($bearer);
        $this->auth->requireScope($key, 'orders:messages');
        if ($key->merchantId !== $orderMerchantId) {
            throw ApiException::notFound('Order not found.');
        }

        return 'merchant';
    }
}
