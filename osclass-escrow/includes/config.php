<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use BitWasp\Bitcoin\Network\Network;
use ElektronNet\Payments\Core\ChainData\EsploraChainDataProvider;
use ElektronNet\Payments\Core\ChainData\FallbackChainDataProvider;
use ElektronNet\Payments\Core\Config\PaymentsConfig;
use ElektronNet\Payments\Core\Escrow\ElektronNetworkFactory;
use ElektronNet\Payments\Core\Escrow\TimeoutPolicy;
use RuntimeException;

/**
 * Builds a core PaymentsConfig from this plugin's own admin preferences
 * (section 'plugin-osclass-escrow', see install.php for the defaults). This
 * is the one place that translates Osclass storage into core types; every
 * other file works with core objects only.
 */
function elektron_escrow_config(): PaymentsConfig
{
    $endpointsRaw = osc_get_preference('chain_data_endpoints', 'plugin-osclass-escrow');
    $endpoints = array_values(array_filter(array_map('trim', explode("\n", $endpointsRaw))));

    $timeoutPolicy = TimeoutPolicy::fromDays(
        (int) osc_get_preference('buyer_refund_days', 'plugin-osclass-escrow'),
        (int) osc_get_preference('seller_release_days', 'plugin-osclass-escrow')
    );

    return new PaymentsConfig(
        osc_get_preference('network', 'plugin-osclass-escrow'),
        $endpoints,
        (int) osc_get_preference('required_confirmations', 'plugin-osclass-escrow'),
        $timeoutPolicy,
        (int) osc_get_preference('fee_rate_lep_per_vbyte', 'plugin-osclass-escrow')
    );
}

/**
 * Chain-data provider built from the same config, with automatic failover
 * across every configured endpoint (see shared/README.md, "ChainData\*").
 */
function elektron_escrow_chain_data(): FallbackChainDataProvider
{
    $config = elektron_escrow_config();
    $providers = array_map(
        function (string $endpoint) {
            return new EsploraChainDataProvider($endpoint);
        },
        $config->chainDataEndpoints()
    );

    return new FallbackChainDataProvider($providers);
}

/**
 * The bitwasp Network matching the configured network. All three values
 * PaymentsConfig accepts ('mainnet', 'testnet', 'regtest') have confirmed
 * bech32 parameters, verified against elektron-net's own
 * src/kernel/chainparams.cpp (see ElektronNetworkFactory's docblock).
 */
function elektron_escrow_network(): Network
{
    switch (elektron_escrow_config()->network()) {
        case 'mainnet':
            return ElektronNetworkFactory::mainnet();
        case 'testnet':
            return ElektronNetworkFactory::testnet();
        case 'regtest':
            return ElektronNetworkFactory::regtest();
        default:
            // Unreachable: PaymentsConfig's constructor already rejects any
            // other value before this function can be called.
            throw new RuntimeException('Unknown network.');
    }
}
