<?php

/**
 * pay-api front controller. One PHP-FPM/CLI-server entrypoint for every
 * `/v1/*` route in doc-elektron/guideline-standalone-payment-server.md
 * section 5.
 */

require dirname(__DIR__, 3) . '/pay-server/vendor/autoload.php';

use ElektronNet\Payments\Core\Escrow\ElektronNetworkFactory;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;
use ElektronNet\Payments\PayServer\Auth\ApiKeyAuthenticator;
use ElektronNet\Payments\PayServer\Config;
use ElektronNet\Payments\PayServer\Db\Database;
use ElektronNet\Payments\PayServer\Db\MerchantRepository;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\Http\Controllers\OrdersController;
use ElektronNet\Payments\PayServer\Http\Request;
use ElektronNet\Payments\PayServer\Http\Router;
use ElektronNet\Payments\PayServer\OrderAddressAllocator;

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

$ordersController = new OrdersController(
    $pdo,
    $auth,
    $merchants,
    $orders,
    $addressAllocator,
    $config->escrowEnabled(),
    $config->checkoutBaseUrl()
);

$router = new Router();
$router->add('POST', '/v1/orders', [$ordersController, 'create']);
$router->add('GET', '/v1/orders/{id}', [$ordersController, 'get']);

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
