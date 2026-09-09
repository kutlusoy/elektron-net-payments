<?php

namespace ElektronNet\Payments\PayServer\Admin;

/**
 * Thin wrapper around PHP's native session for the admin dashboard
 * (section 21: "a human sitting down at a dashboard needs a normal login
 * ... rather than pasting a bearer token into a browser"). Deliberately
 * not shared with the buyer-facing checkout page or the Bearer-token API
 * auth (Auth\ApiKeyAuthenticator) -- these are three different auth
 * models for three different audiences, per section 21.
 */
final class AdminSession
{
    public function __construct()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function login(string $merchantUserId, string $merchantId, bool $isPlatformAdmin = false): void
    {
        session_regenerate_id(true);
        $_SESSION['merchant_user_id'] = $merchantUserId;
        $_SESSION['merchant_id'] = $merchantId;
        $_SESSION['is_platform_admin'] = $isPlatformAdmin;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['merchant_user_id'], $_SESSION['merchant_id']);
    }

    public function merchantId(): ?string
    {
        return isset($_SESSION['merchant_id']) ? (string) $_SESSION['merchant_id'] : null;
    }

    public function merchantUserId(): ?string
    {
        return isset($_SESSION['merchant_user_id']) ? (string) $_SESSION['merchant_user_id'] : null;
    }

    /**
     * Section 12: the price feed (and any future server-wide setting) is
     * gated on this, not on a separate login system -- a platform admin
     * is a merchant_users row like any other, just with one extra flag.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->isAuthenticated() && !empty($_SESSION['is_platform_admin']);
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public function verifyCsrf(?string $token): bool
    {
        return $token !== null && isset($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}
