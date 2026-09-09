<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use ElektronNet\Payments\PayServer\Bip21;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\JsonResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\Http\SseResponse;

/**
 * Buyer-facing order status (section 21: "the order id itself is the
 * buyer's only credential, exactly like any other hosted-checkout link").
 * No Bearer auth, no scopes -- deliberately separate from
 * OrdersController::get(), which is the merchant-authenticated,
 * full-fields version of the same lookup (section 5).
 */
final class CheckoutController
{
    private OrderRepository $orders;
    private MerchantRepository $merchants;
    private string $templateDir;

    public function __construct(OrderRepository $orders, MerchantRepository $merchants, string $templateDir)
    {
        $this->orders = $orders;
        $this->merchants = $merchants;
        $this->templateDir = rtrim($templateDir, '/');
    }

    /**
     * `GET /order/{id}` (section 10, section 19): the single-page hosted
     * checkout view. Public, no auth -- the order id is the buyer's only
     * credential (section 21).
     */
    public function page(Request $request): Responder
    {
        $order = $this->orders->find($request->params['id']);
        $merchant = $order !== null ? $this->merchants->find($order->merchantId) : null;

        if ($order === null || $merchant === null) {
            return new HtmlResponse(404, $this->render('not-found.php', []));
        }

        $paymentUri = Bip21::paymentUri($order->address, $order->amountLep, $merchant->displayName);
        $amountDisplay = Bip21::plainAmount($order->amountLep);

        return new HtmlResponse(200, $this->render('order.php', [
            'order' => $order,
            'merchant' => $merchant,
            'paymentUri' => $paymentUri,
            'amountDisplay' => $amountDisplay,
        ]));
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        require $this->templateDir . '/' . $template;
        return (string) ob_get_clean();
    }

    public function publicStatus(Request $request): JsonResponse
    {
        $order = $this->orders->find($request->params['id']);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }
        $merchant = $this->merchants->find($order->merchantId);
        $label = $merchant !== null ? $merchant->displayName : 'Elektron Net order';

        return new JsonResponse(200, $order->toPublicArray($label));
    }

    public function events(Request $request): SseResponse
    {
        $orderId = $request->params['id'];
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }
        $merchant = $this->merchants->find($order->merchantId);
        $label = $merchant !== null ? $merchant->displayName : 'Elektron Net order';

        $orders = $this->orders;

        return new SseResponse(
            function () use ($orders, $orderId) {
                return $orders->find($orderId);
            },
            $label
        );
    }
}
