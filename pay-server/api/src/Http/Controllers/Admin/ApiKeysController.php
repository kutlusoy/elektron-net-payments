<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Db\ApiKeyRepository;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;

/**
 * Admin key management (section 14): create/list-masked/revoke. The raw
 * key is only ever available once, right after creation -- carried across
 * the redirect via a one-time session flash, never persisted anywhere.
 */
final class ApiKeysController
{
    private AdminSession $session;
    private MerchantRepository $merchants;
    private ApiKeyRepository $apiKeys;
    private ViewRenderer $views;

    public function __construct(
        AdminSession $session,
        MerchantRepository $merchants,
        ApiKeyRepository $apiKeys,
        ViewRenderer $views
    ) {
        $this->session = $session;
        $this->merchants = $merchants;
        $this->apiKeys = $apiKeys;
        $this->views = $views;
    }

    public function index(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchantId = $this->session->merchantId();
        $merchant = $this->merchants->find($merchantId);

        $newRawKey = $_SESSION['flash_new_key'] ?? null;
        unset($_SESSION['flash_new_key']);

        $html = $this->views->renderPage('API keys', 'api-keys', 'api-keys.php', [
            'keys' => $this->apiKeys->listForMerchant($merchantId),
            'validScopes' => ApiKeyRepository::validScopes(),
            'newRawKey' => $newRawKey,
            'csrfToken' => $this->session->csrfToken(),
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]);

        return new HtmlResponse(200, $html);
    }

    public function create(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $csrf = $_POST['csrf_token'] ?? null;
        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/api-keys');
        }

        $label = trim((string) ($_POST['label'] ?? ''));
        $scopes = isset($_POST['scopes']) && is_array($_POST['scopes']) ? array_map('strval', $_POST['scopes']) : [];

        if ($label !== '' && $scopes !== []) {
            $result = $this->apiKeys->create($this->session->merchantId(), $label, $scopes);
            $_SESSION['flash_new_key'] = $result['rawKey'];
        }

        return new RedirectResponse('/admin/api-keys');
    }

    public function revoke(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $csrf = $_POST['csrf_token'] ?? null;
        if ($this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            $this->apiKeys->revoke($request->params['id'], $this->session->merchantId());
        }

        return new RedirectResponse('/admin/api-keys');
    }
}
