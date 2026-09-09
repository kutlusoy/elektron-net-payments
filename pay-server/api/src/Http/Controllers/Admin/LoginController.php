<?php

namespace ElektronNet\Payments\PayServer\Http\Controllers\Admin;

use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Db\MerchantUserRepository;
use ElektronNet\Payments\PayServer\Http\HtmlResponse;
use ElektronNet\Payments\PayServer\Http\RedirectResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Responder;

/**
 * Admin login (section 21): `merchant_users`, a normal login distinct from
 * a merchant's API key.
 */
final class LoginController
{
    private AdminSession $session;
    private MerchantUserRepository $users;
    private ViewRenderer $views;

    public function __construct(AdminSession $session, MerchantUserRepository $users, ViewRenderer $views)
    {
        $this->session = $session;
        $this->users = $users;
        $this->views = $views;
    }

    public function showForm(Request $request): Responder
    {
        if ($this->session->isAuthenticated()) {
            return new RedirectResponse('/admin/orders');
        }

        return new HtmlResponse(200, $this->views->render('login.php', [
            'csrfToken' => $this->session->csrfToken(),
            'error' => null,
        ]));
    }

    public function submit(Request $request): Responder
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $csrf = $_POST['csrf_token'] ?? null;

        $error = 'Invalid email or password.';

        if ($this->session->verifyCsrf(is_string($csrf) ? $csrf : null)) {
            $user = $this->users->findByEmail($email);
            if ($user !== null && $user->passwordHash !== null && password_verify($password, $user->passwordHash)) {
                $this->session->login($user->id, $user->merchantId);
                return new RedirectResponse('/admin/orders');
            }
        }

        return new HtmlResponse(401, $this->views->render('login.php', [
            'csrfToken' => $this->session->csrfToken(),
            'error' => $error,
        ]));
    }

    public function logout(Request $request): Responder
    {
        $this->session->logout();
        return new RedirectResponse('/admin/login');
    }
}
