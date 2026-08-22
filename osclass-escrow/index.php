<?php
/*
Plugin Name: Elektron Net Escrow
Plugin URI: https://elektron-net.org
Description: Trustless 2-of-2 multisig escrow payments in Elektron Net (ELEK) for Osclass listings. See osclass-escrow/README.MD for the full API specification.
Version: 0.1
Author: Elektron Net
Author URI: https://elektron-net.org
*/

    if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

    define('ELEKTRON_ESCROW_DOMAIN', 'osclass-escrow');

    require_once osc_plugin_path(__FILE__) . 'vendor/autoload.php';
    require_once osc_plugin_path(__FILE__) . 'install.php';
    require_once osc_plugin_path(__FILE__) . 'models/EscrowOrderDAO.php';
    require_once osc_plugin_path(__FILE__) . 'models/EscrowWalletDAO.php';
    require_once osc_plugin_path(__FILE__) . 'includes/config.php';   // elektron_escrow_config()
    require_once osc_plugin_path(__FILE__) . 'includes/i18n.php';     // elektron_escrow_t()
    require_once osc_plugin_path(__FILE__) . 'includes/wallet.php';   // one-time wallet connect
    require_once osc_plugin_path(__FILE__) . 'includes/hooks.php';    // item widget, routes, cron

    // Activation / deactivation. See install.php.
    osc_register_plugin(osc_plugin_path(__FILE__), 'elektron_escrow_install');
    osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', 'elektron_escrow_uninstall');

    // "Configure" action in the admin plugin list -> renders admin/settings.php
    // inside the oc-admin skin. See osc_admin_render_plugin_url()'s doc comment
    // in Osclass core for why the path resolution works this way.
    osc_add_hook(osc_plugin_path(__FILE__) . '_configure', function () {
        osc_admin_render_plugin('osclass-escrow/admin/settings.php');
    });

    // Dedicated top-level admin submenu entry, so the settings page is not
    // only reachable through the plugin list's "Configure" link.
    osc_add_hook('admin_menu_init', function () {
        osc_admin_menu_plugins(
            __('Elektron Net Escrow', ELEKTRON_ESCROW_DOMAIN),
            osc_admin_render_plugin_url('osclass-escrow/admin/settings.php'),
            'elektron_escrow_settings'
        );
    });

    // Frontend custom pages (checkout + order status). Handler files live
    // under controllers/, which does not match the "/admin/" substring the
    // core custom-page controller (CWebCustom) rejects for frontend routes.
    osc_add_route(
        'elektron_escrow_checkout',
        'elektron-escrow/checkout',
        'elektron-escrow/checkout',
        'osclass-escrow/controllers/checkout.php'
    );
    osc_add_route(
        'elektron_escrow_order_status',
        'elektron-escrow/order',
        'elektron-escrow/order',
        'osclass-escrow/controllers/order-status.php',
        true // show inside the logged-in user's own menu (user-custom.php), not just the public theme wrapper
    );
