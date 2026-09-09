<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;

final class DashboardController
{
    private AdminSession $session;
    private MerchantRepository $merchants;
    private OrderRepository $orders;
    private ViewRenderer $views;

    public function __construct(
        AdminSession $session,
        MerchantRepository $merchants,
        OrderRepository $orders,
        ViewRenderer $views
    ) {
        $this->session = $session;
        $this->merchants = $merchants;
        $this->orders = $orders;
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

        $html = $this->views->renderPage('Order detail', 'orders', 'order-detail.php', [
            'order' => $order,
            'events' => $this->orders->eventsForOrder($order->id),
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]);

        return new HtmlResponse(200, $html);
    }
}
