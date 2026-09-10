<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use ElektronNet\Payments\Core\PriceFeed\PriceFeedProviderInterface;
use ElektronNet\Payments\PayServer\Bip21;
use ElektronNet\Payments\PayServer\Db\Merchant;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\Order;
use ElektronNet\Payments\PayServer\Db\OrderMessageRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\JsonResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\Http\SseResponse;
use ElektronNet\Payments\PayServer\OrderMessageValidation;
use ElektronNet\Payments\PayServer\OrderRefundValidation;

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
    private OrderMessageRepository $messages;
    private string $templateDir;
    private ?PriceFeedProviderInterface $priceFeed;

    /** Section 11 checklist: "basic rate limiting", not a general API limiter. */
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_MAX_MESSAGES = 5;

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
        OrderMessageRepository $messages,
        string $templateDir,
        ?PriceFeedProviderInterface $priceFeed = null
    ) {
        $this->orders = $orders;
        $this->merchants = $merchants;
        $this->messages = $messages;
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

        return $this->renderOrderPage($order, $merchant, 200);
    }

    /**
     * `POST /order/{id}/messages` (buyer side of section 11/section 5's
     * `POST /v1/orders/{id}/messages` - see OrderMessagesController's
     * docblock for why the buyer path is unauthenticated: the order id
     * itself is the capability token, same as this whole page).
     */
    public function postMessage(Request $request): Responder
    {
        $order = $this->orders->find($request->params['id']);
        $merchant = $order !== null ? $this->merchants->find($order->merchantId) : null;
        if ($order === null || $merchant === null) {
            return new HtmlResponse(404, $this->render('not-found.php', []));
        }

        $error = null;
        $recentCount = $this->messages->countRecentByOrder($order->id, self::RATE_LIMIT_WINDOW_SECONDS);
        if ($recentCount >= self::RATE_LIMIT_MAX_MESSAGES) {
            $error = 'Too many messages posted to this order recently; please wait a moment.';
        } else {
            try {
                $body = OrderMessageValidation::validateBody(['body' => $_POST['body'] ?? '']);
                $this->messages->create($order->id, 'buyer', $body);
            } catch (ApiException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error === null) {
            return new RedirectResponse('/order/' . $order->id . '#messages');
        }

        return $this->renderOrderPage($order, $merchant, 422, ['messageError' => $error]);
    }

    /**
     * `POST /order/{id}/refund-address` (section 15, HTML-form flavor of
     * `POST /v1/orders/{id}/refund-address` below - same validation, same
     * repository call, redirect-on-success instead of JSON for a buyer
     * using the checkout page directly).
     */
    public function submitRefundAddress(Request $request): Responder
    {
        $order = $this->orders->find($request->params['id']);
        $merchant = $order !== null ? $this->merchants->find($order->merchantId) : null;
        if ($order === null || $merchant === null) {
            return new HtmlResponse(404, $this->render('not-found.php', []));
        }

        try {
            $address = OrderRefundValidation::validateAddress(['refund_address' => $_POST['refund_address'] ?? '']);
        } catch (ApiException $e) {
            return $this->renderOrderPage($order, $merchant, 422, ['refundError' => $e->getMessage()]);
        }

        $this->orders->setRefundAddress($order->id, $address);
        $this->orders->recordEvent($order->id, 'refund_address_set', ['refund_address' => $address]);

        return new RedirectResponse('/order/' . $order->id . '#refund');
    }

    /**
     * `POST /v1/orders/{id}/refund-address` (section 5's table): "No
     * scope needed; capability-token authenticated like the checkout page
     * itself" - JSON flavor for a merchant's own tooling/a custom checkout
     * frontend rather than this hosted page.
     */
    public function submitRefundAddressJson(Request $request): JsonResponse
    {
        $order = $this->orders->find($request->params['id']);
        if ($order === null) {
            throw ApiException::notFound('Order not found.');
        }

        $address = OrderRefundValidation::validateAddress($request->body);
        $this->orders->setRefundAddress($order->id, $address);
        $this->orders->recordEvent($order->id, 'refund_address_set', ['refund_address' => $address]);

        return new JsonResponse(200, ['refund_address' => $address]);
    }

    /**
     * @param array{messageError?: string|null, refundError?: string|null} $overrides
     */
    private function renderOrderPage(Order $order, Merchant $merchant, int $statusCode, array $overrides = []): HtmlResponse
    {
        $paymentUri = Bip21::paymentUri($order->address, $order->amountLep, $merchant->displayName);
        $latestOverpayment = $this->latestOverpaymentLep($order->id);

        return new HtmlResponse($statusCode, $this->render('order.php', array_merge([
            'order' => $order,
            'merchant' => $merchant,
            'paymentUri' => $paymentUri,
            'amountDisplay' => Bip21::plainAmount($order->amountLep),
            'fiatDisplay' => $this->fiatDisplay($order->amountLep, $merchant->defaultDisplayCurrency),
            'messages' => $this->messages->findByOrder($order->id),
            'messageError' => null,
            'refundError' => null,
            'overpaymentLep' => $latestOverpayment,
        ], $overrides)));
    }

    /**
     * Section 13/15: the settled event's own payload is the source of
     * truth for whether this order was overpaid (Watcher::evaluateOrder()
     * sets it there) - scanning order_events rather than adding a new
     * orders column keeps this consistent with how order_events already
     * doubles as the audit trail for every other state transition detail.
     */
    private function latestOverpaymentLep(string $orderId): ?int
    {
        foreach (array_reverse($this->orders->eventsForOrder($orderId)) as $event) {
            if ($event['type'] === 'settled' && !empty($event['payload']['overpaid'])) {
                return (int) $event['payload']['overpayment_lep'];
            }
        }

        return null;
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
