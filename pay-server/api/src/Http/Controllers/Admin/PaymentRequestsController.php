<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Db\PaymentRequestRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\PaymentRequestValidation;

/**
 * Section 16 checklist: "Admin UI: create, archive, and view aggregate
 * history (all orders spawned from) a payment request."
 */
final class PaymentRequestsController
{
    private AdminSession $session;
    private MerchantRepository $merchants;
    private PaymentRequestRepository $paymentRequests;
    private OrderRepository $orders;
    private ViewRenderer $views;

    public function __construct(
        AdminSession $session,
        MerchantRepository $merchants,
        PaymentRequestRepository $paymentRequests,
        OrderRepository $orders,
        ViewRenderer $views
    ) {
        $this->session = $session;
        $this->merchants = $merchants;
        $this->paymentRequests = $paymentRequests;
        $this->orders = $orders;
        $this->views = $views;
    }

    public function list(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        return new HtmlResponse(200, $this->views->renderPage('Payment requests', 'payment-requests', 'payment-requests.php', [
            'requests' => $this->paymentRequests->findByMerchant($this->session->merchantId()),
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]));
    }

    public function newForm(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        return new HtmlResponse(200, $this->views->renderPage('New payment request', 'payment-requests', 'payment-request-new.php', [
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
            'amount' => '',
            'description' => '',
            'expiresAt' => '',
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]));
    }

    public function create(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());
        $csrf = $_POST['csrf_token'] ?? null;
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));

        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/payment-requests/new');
        }

        try {
            $fields = PaymentRequestValidation::validate([
                'amount' => $amount,
                'description' => $description,
                'expires_at' => $expiresAt,
            ]);
        } catch (ApiException $e) {
            return new HtmlResponse(422, $this->views->renderPage('New payment request', 'payment-requests', 'payment-request-new.php', [
                'csrfToken' => $this->session->csrfToken(),
                'error' => $e->getMessage(),
                'amount' => $amount,
                'description' => $description,
                'expiresAt' => $expiresAt,
                'merchantName' => $merchant !== null ? $merchant->displayName : '',
            ]));
        }

        $paymentRequest = $this->paymentRequests->create(
            $this->session->merchantId(),
            $fields['amount_lep'],
            $fields['currency'],
            $fields['description'],
            $fields['expires_at']
        );

        return new RedirectResponse('/admin/payment-requests/' . $paymentRequest->id);
    }

    public function detail(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchantId = $this->session->merchantId();
        $merchant = $this->merchants->find($merchantId);

        $paymentRequest = $this->paymentRequests->find($request->params['id']);
        if ($paymentRequest === null || $paymentRequest->merchantId !== $merchantId) {
            return new HtmlResponse(404, $this->views->renderPage('Payment request not found', 'payment-requests', 'payment-requests.php', [
                'requests' => [],
                'merchantName' => $merchant !== null ? $merchant->displayName : '',
            ]));
        }

        return new HtmlResponse(200, $this->views->renderPage('Payment request detail', 'payment-requests', 'payment-request-detail.php', [
            'paymentRequest' => $paymentRequest,
            'orders' => $this->orders->findByPaymentRequest($paymentRequest->id),
            'csrfToken' => $this->session->csrfToken(),
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]));
    }

    public function archive(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $csrf = $_POST['csrf_token'] ?? null;
        $paymentRequest = $this->paymentRequests->find($request->params['id']);

        if ($this->session->verifyCsrf(is_string($csrf) ? $csrf : null)
            && $paymentRequest !== null
            && $paymentRequest->merchantId === $this->session->merchantId()
        ) {
            $this->paymentRequests->archive($paymentRequest->id);
        }

        return new RedirectResponse('/admin/payment-requests/' . $request->params['id']);
    }
}
