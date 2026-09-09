<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use ElektronNet\Payments\Core\PriceFeed\PriceFeedProviderInterface;
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
    private ?PriceFeedProviderInterface $priceFeed;

    /**
     * $priceFeed is optional and defaults to none: section 12 requires
     * that with no PriceFeedProviderInterface implementation configured
     * server-wide (true for every deployment today -- core/ ships none),
     * a merchant's optional fiat readout simply never appears, which is
     * this class's default behavior, not a degraded one.
     */
    public function __construct(
        OrderRepository $orders,
        MerchantRepository $merchants,
        string $templateDir,
        ?PriceFeedProviderInterface $priceFeed = null
    ) {
        $this->orders = $orders;
        $this->merchants = $merchants;
        $this->templateDir = rtrim($templateDir, '/');
        $this->priceFeed = $priceFeed;
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
            'fiatDisplay' => $this->fiatDisplay($order->amountLep, $merchant->defaultDisplayCurrency),
        ]));
    }

    /**
     * Section 12: "an optional informational conversion target when
     * base_currency is ELEK ... computed live from the feed at display
     * time, clearly marked as approximate and never authoritative, never
     * frozen, never affecting amount_lep." Returns null (renders nothing)
     * whenever the merchant has not set a display currency, or no price
     * feed provider is configured, or the configured one has no rate
     * right now -- every one of those is a normal, expected state, not
     * an error.
     *
     * @return array{currency: string, amount: string}|null
     */
    private function fiatDisplay(int $amountLep, ?string $displayCurrency): ?array
    {
        if ($displayCurrency === null || $this->priceFeed === null) {
            return null;
        }

        $rate = $this->priceFeed->getElekPriceInFiat($displayCurrency);
        if ($rate === null) {
            return null;
        }

        $amountElek = $amountLep / OrderRepository::LEP_PER_ELEK;

        return [
            'currency' => $displayCurrency,
            'amount' => number_format($amountElek * $rate, 2),
        ];
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
