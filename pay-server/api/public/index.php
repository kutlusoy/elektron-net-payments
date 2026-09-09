<?php

/**
 * pay-api front controller. One PHP-FPM/CLI-server entrypoint for every
 * `/v1/*` route in doc-elektron/guideline-standalone-payment-server.md
 * section 5.
 */

$repoRoot = dirname(__DIR__, 3);
require $repoRoot . '/pay-server/vendor/autoload.php';

use ElektronNet\Payments\Core\Escrow\ElektronNetworkFactory;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;
use ElektronNet\Payments\PayServer\Admin\AdminSession;
use ElektronNet\Payments\PayServer\Admin\ViewRenderer;
use ElektronNet\Payments\PayServer\Auth\ApiKeyAuthenticator;
use ElektronNet\Payments\PayServer\Config;
use ElektronNet\Payments\PayServer\Db\ApiKeyRepository;
use ElektronNet\Payments\PayServer\Db\Database;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\MerchantUserRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\Controllers\Admin\ApiKeysController;
use ElektronNet\Payments\PayServer\Http\Controllers\Admin\DashboardController;
use ElektronNet\Payments\PayServer\Http\Controllers\Admin\LoginController;
use ElektronNet\Payments\PayServer\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use ElektronNet\Payments\PayServer\Http\Controllers\Admin\WalletController;
use ElektronNet\Payments\PayServer\Http\Controllers\CheckoutController;
use ElektronNet\Payments\PayServer\Http\Controllers\MerchantSettingsController;
use ElektronNet\Payments\PayServer\Http\Controllers\OrdersController;
use ElektronNet\Payments\PayServer\Http\FileResponse;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Router;
use ElektronNet\Payments\PayServer\OrderAddressAllocator;
use ElektronNet\Payments\PayServer\OrderCreationService;

$config = Config::fromEnv();
$pdo = Database::connect($config);

$network = (function () use ($config) {
    switch ($config->network()) {
        case 'mainnet':
            return ElektronNetworkFactory::mainnet();
        case 'testnet':
            return ElektronNetworkFactory::testnet();
        case 'regtest':
            return ElektronNetworkFactory::regtest();
        default:
            throw new InvalidArgumentException("Unknown network '{$config->network()}'.");
    }
})();

$auth = new ApiKeyAuthenticator($pdo);
$merchants = new MerchantRepository($pdo);
$orders = new OrderRepository($pdo);
$addressAllocator = new OrderAddressAllocator(new XpubChildKeyDeriver(), $network);
$orderCreation = new OrderCreationService($pdo, $merchants, $orders, $addressAllocator, $config->escrowEnabled());

$ordersController = new OrdersController($auth, $merchants, $orders, $orderCreation, $config->checkoutBaseUrl());

// No PriceFeedProviderInterface implementation ships with core/ yet
// (section 12); every real deployment runs with none configured today.
$priceFeed = null;
$priceFeedConfigured = $priceFeed !== null;

$checkoutController = new CheckoutController(
    $orders,
    $merchants,
    $repoRoot . '/pay-server/checkout/templates',
    $priceFeed
);

$adminSession = new AdminSession();
$adminViews = new ViewRenderer($repoRoot . '/pay-server/admin/templates');
$merchantUsers = new MerchantUserRepository($pdo);
$apiKeyRepository = new ApiKeyRepository($pdo);

$loginController = new LoginController($adminSession, $merchantUsers, $adminViews);
$dashboardController = new DashboardController($adminSession, $merchants, $orders, $orderCreation, $adminViews);
$apiKeysController = new ApiKeysController($adminSession, $merchants, $apiKeyRepository, $adminViews);
$walletController = new WalletController($adminSession, $merchants, $adminViews, $network, new XpubChildKeyDeriver());
$adminSettingsController = new AdminSettingsController($adminSession, $merchants, $adminViews, $priceFeedConfigured);
$merchantSettingsController = new MerchantSettingsController($auth, $merchants, $priceFeedConfigured);

$router = new Router();
$router->add('POST', '/v1/orders', [$ordersController, 'create']);
$router->add('GET', '/v1/orders/{id}', [$ordersController, 'get']);
$router->add('GET', '/v1/orders/{id}/public', [$checkoutController, 'publicStatus']);
$router->add('GET', '/v1/orders/{id}/events', [$checkoutController, 'events']);

// Section 2's architecture: pay-api also serves the checkout pages and
// (see the admin routes below) the admin UI, all from one process.
$router->add('GET', '/order/{id}', [$checkoutController, 'page']);

// Fixed, hardcoded paths only (Http\FileResponse never takes a
// user-supplied path) -- a real deployment would typically have its
// reverse proxy serve these same routes directly and never reach PHP.
$checkoutAssets = $repoRoot . '/pay-server/checkout/assets';
$router->add('GET', '/assets/checkout/style.css', fn () => new FileResponse($checkoutAssets . '/style.css', 'text/css'));
$router->add('GET', '/assets/checkout/checkout.js', fn () => new FileResponse($checkoutAssets . '/checkout.js', 'application/javascript'));
$router->add('GET', '/assets/checkout/vendor/qrcode.js', fn () => new FileResponse($checkoutAssets . '/vendor/qrcode.js', 'application/javascript'));

// Admin UI (section 21): merchant login, order list/detail, API key
// management. Session-cookie authenticated -- a different auth model
// from both the Bearer-token /v1/* API and the capability-token checkout
// pages, on purpose (see Admin\AdminSession's docblock).
$router->add('GET', '/admin', fn () => new \ElektronNet\Payments\PayServer\Http\RedirectResponse('/admin/orders'));
$router->add('GET', '/admin/login', [$loginController, 'showForm']);
$router->add('POST', '/admin/login', [$loginController, 'submit']);
$router->add('POST', '/admin/logout', [$loginController, 'logout']);
$router->add('GET', '/admin/orders', [$dashboardController, 'orderList']);
// Registered before /admin/orders/{id}: the router matches routes in
// registration order, and {id}'s pattern would otherwise also match the
// literal path segment "new".
$router->add('GET', '/admin/orders/new', [$dashboardController, 'newOrderForm']);
$router->add('POST', '/admin/orders', [$dashboardController, 'createOrder']);
$router->add('GET', '/admin/orders/{id}', [$dashboardController, 'orderDetail']);
$router->add('GET', '/admin/wallet', [$walletController, 'form']);
$router->add('POST', '/admin/wallet', [$walletController, 'update']);
$router->add('GET', '/admin/branding', [$adminSettingsController, 'brandingForm']);
$router->add('POST', '/admin/branding', [$adminSettingsController, 'updateBranding']);
$router->add('GET', '/admin/settings', [$adminSettingsController, 'generalForm']);
$router->add('POST', '/admin/settings', [$adminSettingsController, 'updateGeneral']);
$router->add('GET', '/admin/api-keys', [$apiKeysController, 'index']);
$router->add('POST', '/admin/api-keys', [$apiKeysController, 'create']);
$router->add('POST', '/admin/api-keys/{id}/revoke', [$apiKeysController, 'revoke']);

// REST surface for the same branding/settings management (section 5's
// table), scoped branding:write/settings:write, for a merchant's own
// tooling rather than a human at the admin dashboard.
$router->add('PUT', '/v1/merchants/{id}/branding', [$merchantSettingsController, 'updateBranding']);
$router->add('GET', '/v1/merchants/{id}/settings', [$merchantSettingsController, 'getSettings']);
$router->add('PUT', '/v1/merchants/{id}/settings', [$merchantSettingsController, 'updateSettings']);

$adminAssets = $repoRoot . '/pay-server/admin/assets';
$router->add('GET', '/assets/admin/style.css', fn () => new FileResponse($adminAssets . '/style.css', 'text/css'));

$request = Request::fromGlobals();

try {
    $response = $router->dispatch($request);
} catch (ApiException $e) {
    $response = new \ElektronNet\Payments\PayServer\Http\JsonResponse($e->statusCode(), [
        'error' => $e->errorCode(),
        'message' => $e->getMessage(),
    ]);
} catch (\Throwable $e) {
    $response = new \ElektronNet\Payments\PayServer\Http\JsonResponse(500, [
        'error' => 'internal_error',
        'message' => 'An unexpected error occurred.',
    ]);
}

$response->send();
