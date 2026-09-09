<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\BrandingValidation;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\SettingsValidation;

/**
 * Section 17 branding and section 12/13 settings, admin-UI side. Shares
 * the same validation classes (BrandingValidation/SettingsValidation) and
 * MerchantRepository methods as the REST endpoints in
 * MerchantSettingsController, so the two surfaces cannot drift apart.
 */
final class SettingsController
{
    private AdminSession $session;
    private MerchantRepository $merchants;
    private ViewRenderer $views;
    private bool $priceFeedConfigured;

    public function __construct(
        AdminSession $session,
        MerchantRepository $merchants,
        ViewRenderer $views,
        bool $priceFeedConfigured
    ) {
        $this->session = $session;
        $this->merchants = $merchants;
        $this->views = $views;
        $this->priceFeedConfigured = $priceFeedConfigured;
    }

    public function brandingForm(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        return new HtmlResponse(200, $this->views->renderPage('Branding', 'branding', 'branding.php', [
            'merchant' => $merchant,
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
            'justSaved' => $this->consumeFlash('flash_branding_saved'),
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]));
    }

    public function updateBranding(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());
        $csrf = $_POST['csrf_token'] ?? null;
        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/branding');
        }

        $payload = [
            'display_name' => $_POST['display_name'] ?? null,
            'logo_url' => $_POST['logo_url'] ?? null,
            'theme_color' => $_POST['theme_color'] ?? null,
            'checkout_subdomain' => $_POST['checkout_subdomain'] ?? null,
        ];

        try {
            $fields = BrandingValidation::validate($payload);
        } catch (ApiException $e) {
            return new HtmlResponse(422, $this->views->renderPage('Branding', 'branding', 'branding.php', [
                'merchant' => $merchant,
                'csrfToken' => $this->session->csrfToken(),
                'error' => $e->getMessage(),
                'justSaved' => false,
                'merchantName' => $merchant !== null ? $merchant->displayName : '',
            ]));
        }

        $this->merchants->updateBranding($this->session->merchantId(), $fields);
        $_SESSION['flash_branding_saved'] = true;

        return new RedirectResponse('/admin/branding');
    }

    public function generalForm(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        return new HtmlResponse(200, $this->views->renderPage('Settings', 'settings', 'settings.php', [
            'merchant' => $merchant,
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
            'justSaved' => $this->consumeFlash('flash_settings_saved'),
            'priceFeedConfigured' => $this->priceFeedConfigured,
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]));
    }

    public function updateGeneral(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());
        $csrf = $_POST['csrf_token'] ?? null;
        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/settings');
        }

        $payload = [
            'order_expiry_minutes' => $_POST['order_expiry_minutes'] ?? null,
            'default_required_confirmations' => $_POST['default_required_confirmations'] ?? null,
            'underpayment_tolerance_percent' => $_POST['underpayment_tolerance_percent'] ?? null,
            'default_display_currency' => $_POST['default_display_currency'] ?? null,
            'success_url' => $_POST['success_url'] ?? null,
            'cancel_url' => $_POST['cancel_url'] ?? null,
            'enabled_fiat_currencies' => $_POST['enabled_fiat_currencies'] ?? [],
        ];

        try {
            $fields = SettingsValidation::validate($payload, $this->priceFeedConfigured);
        } catch (ApiException $e) {
            return new HtmlResponse(422, $this->views->renderPage('Settings', 'settings', 'settings.php', [
                'merchant' => $merchant,
                'csrfToken' => $this->session->csrfToken(),
                'error' => $e->getMessage(),
                'justSaved' => false,
                'priceFeedConfigured' => $this->priceFeedConfigured,
                'merchantName' => $merchant !== null ? $merchant->displayName : '',
            ]));
        }

        $this->merchants->updateSettings($this->session->merchantId(), $fields);
        $_SESSION['flash_settings_saved'] = true;

        return new RedirectResponse('/admin/settings');
    }

    private function consumeFlash(string $key): bool
    {
        $value = isset($_SESSION[$key]);
        unset($_SESSION[$key]);

        return $value;
    }
}
