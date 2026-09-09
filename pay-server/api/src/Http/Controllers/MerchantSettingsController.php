<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers;

use ElektronNet\Payments\PayServer\Auth\ApiKeyAuthenticator;
use ElektronNet\Payments\PayServer\BrandingValidation;
use ElektronNet\Payments\PayServer\Db\Merchant;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\JsonResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\SettingsValidation;

/**
 * `PUT /v1/merchants/{id}/branding`, `GET`/`PUT /v1/merchants/{id}/settings`
 * (section 5's table). The admin dashboard's own branding/settings pages
 * call the same MerchantRepository methods directly; this is the REST
 * surface a merchant's own tooling (rather than a human at the admin
 * dashboard) would use, scoped `branding:write`/`settings:write` like the
 * guideline's endpoint table specifies.
 */
final class MerchantSettingsController
{
    private ApiKeyAuthenticator $auth;
    private MerchantRepository $merchants;
    private bool $priceFeedConfigured;

    public function __construct(ApiKeyAuthenticator $auth, MerchantRepository $merchants, bool $priceFeedConfigured)
    {
        $this->auth = $auth;
        $this->merchants = $merchants;
        $this->priceFeedConfigured = $priceFeedConfigured;
    }

    public function updateBranding(Request $request): JsonResponse
    {
        $merchant = $this->authenticateForOwnMerchant($request, 'branding:write');

        $fields = BrandingValidation::validate($request->body);
        $this->merchants->updateBranding($merchant->id, $fields);

        return new JsonResponse(200, [
            'display_name' => $fields['display_name'] ?? $merchant->name,
            'logo_url' => $fields['logo_url'],
            'theme_color' => $fields['theme_color'],
            'checkout_subdomain' => $fields['checkout_subdomain'],
        ]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        $merchant = $this->authenticateForOwnMerchant($request, 'settings:write');

        return new JsonResponse(200, [
            'order_expiry_minutes' => $merchant->orderExpiryMinutes,
            'default_required_confirmations' => $merchant->defaultRequiredConfirmations,
            'underpayment_tolerance_percent' => (float) $merchant->underpaymentTolerancePercent,
            'default_display_currency' => $merchant->defaultDisplayCurrency,
            'success_url' => $merchant->successUrl,
            'cancel_url' => $merchant->cancelUrl,
            'enabled_fiat_currencies' => $merchant->enabledFiatCurrencies,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $merchant = $this->authenticateForOwnMerchant($request, 'settings:write');

        $fields = SettingsValidation::validate($request->body, $this->priceFeedConfigured);
        $this->merchants->updateSettings($merchant->id, $fields);

        return new JsonResponse(200, $fields);
    }

    private function authenticateForOwnMerchant(Request $request, string $scope): Merchant
    {
        $key = $this->auth->authenticate($request->bearerToken());
        $this->auth->requireScope($key, $scope);

        if ($request->params['id'] !== $key->merchantId) {
            throw ApiException::notFound('Merchant not found.');
        }
        $merchant = $this->merchants->find($key->merchantId);
        if ($merchant === null) {
            throw ApiException::notFound('Merchant not found.');
        }

        return $merchant;
    }
}
