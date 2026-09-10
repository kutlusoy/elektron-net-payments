<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use ElektronNet\Payments\PayServer\Auth\ApiKeyAuthenticator;
use ElektronNet\Payments\PayServer\Bip21;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Db\PaymentRequestRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\JsonResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\OrderCreationService;
use ElektronNet\Payments\PayServer\PaymentRequestValidation;

/**
 * Buyer-facing side of section 16's reusable payment requests: the
 * request id itself is the only credential (same model as
 * CheckoutController's order pages, section 21) -- no login, since a
 * donation button or tip jar is meant to be shared freely.
 */
final class PaymentRequestController
{
    private PaymentRequestRepository $paymentRequests;
    private MerchantRepository $merchants;
    private OrderRepository $orders;
    private OrderCreationService $orderCreation;
    private ApiKeyAuthenticator $auth;
    private string $templateDir;

    public function __construct(
        PaymentRequestRepository $paymentRequests,
        MerchantRepository $merchants,
        OrderRepository $orders,
        OrderCreationService $orderCreation,
        ApiKeyAuthenticator $auth,
        string $templateDir
    ) {
        $this->paymentRequests = $paymentRequests;
        $this->merchants = $merchants;
        $this->orders = $orders;
        $this->orderCreation = $orderCreation;
        $this->auth = $auth;
        $this->templateDir = rtrim($templateDir, '/');
    }

    /**
     * `POST /v1/payment-requests` (section 5's table), scoped
     * `payment_requests:manage` -- a merchant's own tooling creating a
     * reusable link, distinct from the admin dashboard's own "new payment
     * request" form (Admin\PaymentRequestsController), which calls
     * PaymentRequestRepository directly the same way branding/settings do.
     */
    public function create(Request $request): JsonResponse
    {
        $key = $this->auth->authenticate($request->bearerToken());
        $this->auth->requireScope($key, 'payment_requests:manage');
        $merchant = $this->merchants->find($key->merchantId);
        if ($merchant === null) {
            throw ApiException::notFound('Merchant not found.');
        }

        $fields = PaymentRequestValidation::validate($request->body);
        $paymentRequest = $this->paymentRequests->create(
            $merchant->id,
            $fields['amount_lep'],
            $fields['currency'],
            $fields['description'],
            $fields['expires_at']
        );

        return new JsonResponse(201, [
            'payment_request_id' => $paymentRequest->id,
            'amount_lep' => $paymentRequest->amountLep,
            'currency' => $paymentRequest->currency,
            'description' => $paymentRequest->description,
            'expires_at' => $paymentRequest->expiresAt,
            'page_url' => '/pay/' . $paymentRequest->id,
        ]);
    }

    /**
     * `GET /pay/{id}` (section 16): "a thin 'confirm or enter an amount,
     * then pay' screen."
     */
    public function page(Request $request): Responder
    {
        $paymentRequest = $this->paymentRequests->find($request->params['id']);
        $merchant = $paymentRequest !== null ? $this->merchants->find($paymentRequest->merchantId) : null;

        if ($paymentRequest === null || $merchant === null) {
            return new HtmlResponse(404, $this->render('payment-request-not-found.php', []));
        }
        if (!$paymentRequest->isPayable()) {
            return new HtmlResponse(410, $this->render('payment-request-not-found.php', []));
        }

        return new HtmlResponse(200, $this->render('payment-request.php', [
            'paymentRequest' => $paymentRequest,
            'merchant' => $merchant,
            'error' => null,
            'amount' => $paymentRequest->isOpenAmount() ? '' : Bip21::plainAmount($paymentRequest->amountLep),
        ]));
    }

    /**
     * `POST /pay/{id}`: spawns a genuine, fresh `orders` row (section 16
     * checklist: never itself payable, never its own address) and hands
     * off to the exact same checkout flow as any other order.
     */
    public function pay(Request $request): Responder
    {
        $paymentRequest = $this->paymentRequests->find($request->params['id']);
        $merchant = $paymentRequest !== null ? $this->merchants->find($paymentRequest->merchantId) : null;

        if ($paymentRequest === null || $merchant === null) {
            return new HtmlResponse(404, $this->render('payment-request-not-found.php', []));
        }
        if (!$paymentRequest->isPayable()) {
            return new HtmlResponse(410, $this->render('payment-request-not-found.php', []));
        }

        $enteredAmount = trim((string) ($_POST['amount'] ?? ''));

        try {
            if ($paymentRequest->isOpenAmount()) {
                if (
                    !is_numeric($enteredAmount)
                    || (float) $enteredAmount <= 0
                    || (float) $enteredAmount > PaymentRequestValidation::MAX_ELEK_AMOUNT
                ) {
                    throw ApiException::validationError(
                        'Enter an amount greater than 0 and no more than ' . PaymentRequestValidation::MAX_ELEK_AMOUNT . ' ELEK.'
                    );
                }
                $amountElek = $enteredAmount;
            } else {
                $amountElek = Bip21::plainAmount($paymentRequest->amountLep);
            }

            $result = $this->orderCreation->createDirectOrder(
                $merchant,
                ['mode' => 'direct', 'amount' => $amountElek, 'currency' => 'ELEK'],
                $paymentRequest->id
            );
        } catch (ApiException $e) {
            return new HtmlResponse(422, $this->render('payment-request.php', [
                'paymentRequest' => $paymentRequest,
                'merchant' => $merchant,
                'error' => $e->getMessage(),
                'amount' => $paymentRequest->isOpenAmount() ? $enteredAmount : Bip21::plainAmount($paymentRequest->amountLep),
            ]));
        }

        return new RedirectResponse('/order/' . $result->order->id);
    }

    /**
     * `GET /v1/payment-requests/{id}` (section 5's table): the same
     * information as page(), as JSON, for a merchant's own tooling
     * (e.g. rendering a custom "pay" button elsewhere) rather than the
     * hosted page above.
     */
    public function publicJson(Request $request): JsonResponse
    {
        $paymentRequest = $this->paymentRequests->find($request->params['id']);
        if ($paymentRequest === null) {
            throw ApiException::notFound('Payment request not found.');
        }
        $merchant = $this->merchants->find($paymentRequest->merchantId);

        return new JsonResponse(200, [
            'payment_request_id' => $paymentRequest->id,
            'merchant_display_name' => $merchant !== null ? $merchant->displayName : null,
            'amount_lep' => $paymentRequest->amountLep,
            'currency' => $paymentRequest->currency,
            'description' => $paymentRequest->description,
            'expires_at' => $paymentRequest->expiresAt,
            'is_payable' => $paymentRequest->isPayable(),
            'page_url' => '/pay/' . $paymentRequest->id,
        ]);
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
}
