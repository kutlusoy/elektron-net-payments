<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Bip21;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\OrderCreationService;

final class DashboardController
{
    private AdminSession $session;
    private MerchantRepository $merchants;
    private OrderRepository $orders;
    private OrderCreationService $orderCreation;
    private ViewRenderer $views;

    public function __construct(
        AdminSession $session,
        MerchantRepository $merchants,
        OrderRepository $orders,
        OrderCreationService $orderCreation,
        ViewRenderer $views
    ) {
        $this->session = $session;
        $this->merchants = $merchants;
        $this->orders = $orders;
        $this->orderCreation = $orderCreation;
        $this->views = $views;
    }

    public function orderList(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchantId = $this->session->merchantId();
        $merchant = $this->merchants->find($merchantId);

        $html = $this->views->renderPage('Orders', 'orders', 'orders.php', [
            'orders' => $this->orders->findByMerchant($merchantId),
            'total' => $this->orders->countByMerchant($merchantId),
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]);

        return new HtmlResponse(200, $html);
    }

    /**
     * The merchant-facing "type an amount, click through" workflow the
     * REST API alone has no UI for: a human at the admin dashboard has no
     * API key to paste into curl. This is the same OrderCreationService
     * `POST /v1/orders` uses, just behind session auth instead of a
     * Bearer token.
     */
    public function newOrderForm(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        $html = $this->views->renderPage('New order', 'orders', 'order-new.php', [
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
            'amount' => '',
            'externalReference' => '',
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]);

        return new HtmlResponse(200, $html);
    }

    public function createOrder(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        $csrf = $_POST['csrf_token'] ?? null;
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $externalReference = trim((string) ($_POST['external_reference'] ?? ''));

        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/orders/new');
        }

        try {
            $payload = ['mode' => 'direct', 'amount' => $amount];
            if ($externalReference !== '') {
                $payload['external_reference'] = $externalReference;
            }
            $result = $this->orderCreation->createDirectOrder($merchant, $payload);
        } catch (ApiException $e) {
            $html = $this->views->renderPage('New order', 'orders', 'order-new.php', [
                'csrfToken' => $this->session->csrfToken(),
                'error' => $e->getMessage(),
                'amount' => $amount,
                'externalReference' => $externalReference,
                'merchantName' => $merchant !== null ? $merchant->displayName : '',
            ]);
            return new HtmlResponse(422, $html);
        }

        return new RedirectResponse('/admin/orders/' . $result->order->id);
    }

    public function orderDetail(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchantId = $this->session->merchantId();
        $merchant = $this->merchants->find($merchantId);

        $order = $this->orders->find($request->params['id']);
        if ($order === null || $order->merchantId !== $merchantId) {
            return new HtmlResponse(404, $this->views->renderPage('Order not found', 'orders', 'orders.php', [
                'orders' => [],
                'total' => 0,
                'merchantName' => $merchant !== null ? $merchant->displayName : '',
            ]));
        }

        $paymentUri = Bip21::paymentUri(
            $order->address,
            $order->amountLep,
            $merchant !== null ? $merchant->displayName : 'Elektron Net order'
        );

        $html = $this->views->renderPage('Order detail', 'orders', 'order-detail.php', [
            'order' => $order,
            'events' => $this->orders->eventsForOrder($order->id),
            'paymentUri' => $paymentUri,
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]);

        return new HtmlResponse(200, $html);
    }
}
