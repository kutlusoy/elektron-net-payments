<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Db\PlatformSettingsRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\PlatformPriceFeedValidation;

/**
 * Section 12's price feed, server-wide by design (unlike branding/settings,
 * which are per-merchant): gated on AdminSession::isPlatformAdmin() rather
 * than being reachable by any merchant, since one operator's choice here
 * affects every merchant's optional fiat readout at once.
 */
final class PlatformSettingsController
{
    public const SETTINGS_KEY = 'price_feed';

    private AdminSession $session;
    private PlatformSettingsRepository $settings;
    private ViewRenderer $views;
    /** @var array<int, array<string, mixed>> */
    private array $envDefaultEndpoints;

    /**
     * @param array<int, array<string, mixed>> $envDefaultEndpoints
     */
    public function __construct(
        AdminSession $session,
        PlatformSettingsRepository $settings,
        ViewRenderer $views,
        array $envDefaultEndpoints
    ) {
        $this->session = $session;
        $this->settings = $settings;
        $this->views = $views;
        $this->envDefaultEndpoints = $envDefaultEndpoints;
    }

    public function priceFeedForm(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        if (!$this->session->isPlatformAdmin()) {
            return new HtmlResponse(403, 'Forbidden: platform admin access required.');
        }

        return new HtmlResponse(200, $this->views->renderPage('Price feed', 'platform-price-feed', 'platform-price-feed.php', [
            'current' => $this->currentSetting(),
            'usingEnvDefault' => $this->settings->get(self::SETTINGS_KEY) === null,
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
            'justSaved' => $this->consumeFlash('flash_price_feed_saved'),
            'isPlatformAdmin' => true,
        ]));
    }

    public function updatePriceFeed(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        if (!$this->session->isPlatformAdmin()) {
            return new HtmlResponse(403, 'Forbidden: platform admin access required.');
        }
        $csrf = $_POST['csrf_token'] ?? null;
        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/platform/price-feed');
        }

        $payload = [
            'enabled' => isset($_POST['enabled']),
            'base_url' => $_POST['base_url'] ?? null,
            'coin_id' => $_POST['coin_id'] ?? null,
            'price_path' => $_POST['price_path'] ?? null,
            'ids_param' => $_POST['ids_param'] ?? null,
            'vs_currencies_param' => $_POST['vs_currencies_param'] ?? null,
            'api_key_header' => $_POST['api_key_header'] ?? null,
            'api_key' => $_POST['api_key'] ?? null,
            'advanced_json' => $_POST['advanced_json'] ?? null,
        ];

        try {
            $validated = PlatformPriceFeedValidation::validate($payload);
        } catch (ApiException $e) {
            // Re-display exactly what was submitted (even though it did not
            // validate) rather than reverting to the last-saved value, so a
            // typo is easy to spot and fix without retyping everything else.
            $reEntered = [
                'enabled' => !empty($payload['enabled']),
                'endpoints' => [[
                    'base_url' => (string) ($payload['base_url'] ?? ''),
                    'coin_id' => (string) ($payload['coin_id'] ?? ''),
                    'price_path' => (string) ($payload['price_path'] ?? ''),
                    'ids_param' => (string) ($payload['ids_param'] ?? ''),
                    'vs_currencies_param' => (string) ($payload['vs_currencies_param'] ?? ''),
                    'api_key_header' => (string) ($payload['api_key_header'] ?? ''),
                    'api_key' => (string) ($payload['api_key'] ?? ''),
                ]],
                'advanced_json' => (string) ($payload['advanced_json'] ?? ''),
            ];

            return new HtmlResponse(422, $this->views->renderPage('Price feed', 'platform-price-feed', 'platform-price-feed.php', [
                'current' => $reEntered,
                'usingEnvDefault' => false,
                'csrfToken' => $this->session->csrfToken(),
                'error' => $e->getMessage(),
                'justSaved' => false,
                'isPlatformAdmin' => true,
            ]));
        }

        $this->settings->set(self::SETTINGS_KEY, $validated);
        $_SESSION['flash_price_feed_saved'] = true;

        return new RedirectResponse('/admin/platform/price-feed');
    }

    /**
     * @return array{enabled: bool, endpoints: array<int, array<string, mixed>>}
     */
    private function currentSetting(): array
    {
        $stored = $this->settings->get(self::SETTINGS_KEY);
        if ($stored !== null) {
            return [
                'enabled' => !empty($stored['enabled']),
                'endpoints' => is_array($stored['endpoints'] ?? null) ? $stored['endpoints'] : [],
            ];
        }

        return [
            'enabled' => $this->envDefaultEndpoints !== [],
            'endpoints' => $this->envDefaultEndpoints,
        ];
    }

    private function consumeFlash(string $key): bool
    {
        $value = isset($_SESSION[$key]);
        unset($_SESSION[$key]);

        return $value;
    }
}
