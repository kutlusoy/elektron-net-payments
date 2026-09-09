<?php

/**
 * pay-watcher entrypoint. A single long-running process (see docker/Dockerfile.pay-watcher);
 * not a cron job (section 6). Poll interval and batch size are configurable
 * via environment variables per section 6's checklist.
 */

require dirname(__DIR__, 3) . '/pay-server/vendor/autoload.php';

use ElektronNet\Payments\Core\ChainData\EsploraChainDataProvider;
use ElektronNet\Payments\Core\ChainData\FallbackChainDataProvider;
use ElektronNet\Payments\PayServer\Config;
use ElektronNet\Payments\PayServer\Db\Database;
use ElektronNet\Payments\PayServer\Db\OrderRepository;
use ElektronNet\Payments\PayWatcher\Watcher;

$config = Config::fromEnv();
$pdo = Database::connect($config);

$providers = [];
foreach ($config->defaultChainEndpoints() as $endpoint) {
    if (($endpoint['type'] ?? null) === 'esplora') {
        $providers[] = new EsploraChainDataProvider((string) $endpoint['base_url']);
    }
    // 'electrum' endpoints (section 7's future tier) need
    // ElectrumChainDataProvider, which does not exist yet; entries of that
    // type are skipped here rather than erroring, matching section 7's
    // "not a blocker for launch" note.
}
$chainData = new FallbackChainDataProvider($providers);

$orders = new OrderRepository($pdo);
$watcher = new Watcher($pdo, $chainData, $orders);

$pollIntervalSeconds = (int) (getenv('PAY_WATCHER_POLL_INTERVAL_SECONDS') ?: 30);
$batchSize = (int) (getenv('PAY_WATCHER_BATCH_SIZE') ?: 100);

fwrite(STDERR, "pay-watcher starting: interval={$pollIntervalSeconds}s batch={$batchSize}\n");

while (true) {
    try {
        $watcher->pollOnce($batchSize);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'pay-watcher poll failed: ' . $e->getMessage() . "\n");
    }
    sleep($pollIntervalSeconds);
}
