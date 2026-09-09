<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\OrderCreationService;

/**
 * Point-of-sale / kiosk mode: a staff member types an amount and taps
 * "Charge", the same device's screen becomes the payment terminal (QR for
 * the buyer to scan with their own wallet), then returns to a fresh entry
 * screen once the order settles or the payment window expires - like a
 * card terminal at a till. Not a new payment mechanism: this is
 * OrderCreationService (the same one POST /v1/orders and the "new order"
 * admin form already use) plus the existing checkout page
 * (CheckoutController::page(), section 10), opened with a `?kiosk=1`
 * marker checkout.js reacts to by always returning here afterward instead
 * of the merchant's own success_url/cancel_url (see checkout.js).
 *
 * Deliberately rendered outside the normal admin shell (no sidebar nav):
 * this screen faces the till, often on a separate small display, not a
 * merchant sitting down to manage their account.
 */
final class TerminalController
{
    private AdminSession $session;
    private MerchantRepository $merchants;
    private OrderCreationService $orderCreation;
    private ViewRenderer $views;

    public function __construct(
        AdminSession $session,
        MerchantRepository $merchants,
        OrderCreationService $orderCreation,
        ViewRenderer $views
    ) {
        $this->session = $session;
        $this->merchants = $merchants;
        $this->orderCreation = $orderCreation;
        $this->views = $views;
    }

    public function form(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        return new HtmlResponse(200, $this->views->render('terminal.php', [
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
            'amount' => '',
            'currency' => 'ELEK',
            'enabledFiatCurrencies' => $merchant !== null ? $merchant->enabledFiatCurrencies : [],
        ]));
    }

    public function charge(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());
        $csrf = $_POST['csrf_token'] ?? null;
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $currency = trim((string) ($_POST['currency'] ?? 'ELEK'));

        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/terminal');
        }

        try {
            $result = $this->orderCreation->createDirectOrder($merchant, [
                'mode' => 'direct',
                'amount' => $amount,
                'currency' => $currency,
            ]);
        } catch (ApiException $e) {
            return new HtmlResponse(422, $this->views->render('terminal.php', [
                'csrfToken' => $this->session->csrfToken(),
                'error' => $e->getMessage(),
                'amount' => $amount,
                'currency' => $currency,
                'enabledFiatCurrencies' => $merchant !== null ? $merchant->enabledFiatCurrencies : [],
            ]));
        }

        return new RedirectResponse('/order/' . $result->order->id . '?kiosk=1');
    }
}
