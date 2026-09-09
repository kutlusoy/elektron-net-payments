<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use BitWasp\Bitcoin\Network\Network;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;
use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;
use ElektronNet\Payments\PayServer\XpubValidation;
use Throwable;

/**
 * Section 8: "every merchant onboarding flow needs a 'connect your
 * receiving wallet' step, storing the resulting xpub in
 * merchants.receiving_xpub, before that merchant can accept a direct
 * payment at all." Without this, `merchants.receiving_xpub` had no way
 * to be set except directly in the database -- meaning a new merchant
 * could not actually onboard themselves through the product at all.
 */
final class WalletController
{
    private AdminSession $session;
    private MerchantRepository $merchants;
    private ViewRenderer $views;
    private Network $network;
    private XpubChildKeyDeriver $deriver;

    public function __construct(
        AdminSession $session,
        MerchantRepository $merchants,
        ViewRenderer $views,
        Network $network,
        XpubChildKeyDeriver $deriver
    ) {
        $this->session = $session;
        $this->merchants = $merchants;
        $this->views = $views;
        $this->network = $network;
        $this->deriver = $deriver;
    }

    public function form(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());

        $justSaved = isset($_SESSION['flash_wallet_saved']);
        unset($_SESSION['flash_wallet_saved']);

        return new HtmlResponse(200, $this->views->renderPage('Wallet', 'wallet', 'wallet.php', [
            'merchant' => $merchant,
            'previewAddress' => $this->previewAddress($merchant->receivingXpub ?? null),
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
            'justSaved' => $justSaved,
            'merchantName' => $merchant !== null ? $merchant->displayName : '',
        ]));
    }

    public function update(Request $request): Responder
    {
        if (!$this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/login');
        }
        $merchant = $this->merchants->find($this->session->merchantId());
        $csrf = $_POST['csrf_token'] ?? null;
        $rawXpub = (string) ($_POST['xpub'] ?? '');

        if (!$this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            return new RedirectResponse('/admin/wallet');
        }

        try {
            $normalized = XpubValidation::normalizeAndValidate($rawXpub, $this->network);
        } catch (ApiException $e) {
            return new HtmlResponse(422, $this->views->renderPage('Wallet', 'wallet', 'wallet.php', [
                'merchant' => $merchant,
                'previewAddress' => $this->previewAddress($merchant->receivingXpub ?? null),
                'csrfToken' => $this->session->csrfToken(),
                'error' => $e->getMessage(),
                'justSaved' => false,
                'merchantName' => $merchant !== null ? $merchant->displayName : '',
            ]));
        }

        $this->merchants->updateReceivingXpub($this->session->merchantId(), $normalized);
        $_SESSION['flash_wallet_saved'] = true;

        return new RedirectResponse('/admin/wallet');
    }

    /**
     * The address the wallet-connect page shows back so the merchant can
     * confirm it matches an address they actually recognize in their own
     * wallet, before the xpub is trusted for real orders (mirrors
     * osclass-escrow/includes/wallet.php's
     * elektron_escrow_preview_address_for_xpub()). A wallet whose xpub
     * does not actually sit at the depth this platform assumes would
     * otherwise only be caught the hard way, once a real order's funds
     * are unrecoverable.
     */
    private function previewAddress(?string $xpub): ?string
    {
        if ($xpub === null) {
            return null;
        }
        try {
            return $this->deriver->deriveChildAddress($xpub, 0, $this->network);
        } catch (Throwable $e) {
            return null;
        }
    }
}
