<?php
/*
Plugin Name: Elektron Net Escrow
Plugin URI: https://elektron-net.org
Description: Trustless 2-of-2 multisig escrow payments in Elektron (ELEK) on the Elektron Net network, for Osclass listings. See osclass-escrow/README.MD for the full API specification.
Version: 0.1.0
Author: Elektron Net
Author URI: https://elektron-net.org
*/

    if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

    define('ELEKTRON_ESCROW_DOMAIN', 'osclass-escrow');

    // __DIR__, not osc_plugin_path(__FILE__): that helper returns the full
    // path to THIS file (see its own source in oc-includes/osclass/helpers/
    // hPlugins.php -- it strips down to, and re-appends, the path
    // including the filename, it does not return a directory), which is
    // exactly right for the plugin-identifier uses below but silently
    // wrong for building a path to another file, since it leaves no
    // separator before the appended relative path.
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/install.php';
    require_once __DIR__ . '/models/EscrowOrderDAO.php';
    require_once __DIR__ . '/models/EscrowWalletDAO.php';
    require_once __DIR__ . '/includes/config.php';   // elektron_escrow_config()
    require_once __DIR__ . '/includes/i18n.php';     // elektron_escrow_t()
    require_once __DIR__ . '/includes/formatting.php'; // elektron_escrow_format_amount()
    require_once __DIR__ . '/includes/wallet.php';   // wallet pubkey get/save helpers
    require_once __DIR__ . '/includes/hooks.php';    // item widget, routes, cron

    // Activation / deactivation. See install.php.
    // osc_register_plugin() genuinely does want osc_plugin_path(__FILE__)
    // here (the full path), matching how Shopclass's own bundled
    // sample-widgets plugin calls it. The hook names below are different:
    // Plugins::uninstall() and CAdminPlugins's "Configure" action fire
    // '<relative-path-to-index.php>_uninstall'/'_configure' (PLUGINS_PATH
    // already stripped on their side, confirmed in Plugins.php), so the
    // hook must be registered under that same relative identifier, not
    // the full path osc_plugin_path(__FILE__) returns.
    osc_register_plugin(osc_plugin_path(__FILE__), 'elektron_escrow_install');
    osc_add_hook('osclass-escrow/index.php_uninstall', 'elektron_escrow_uninstall');

    // "Configure" action in the admin plugin list -> renders admin/settings.php
    // inside the oc-admin skin. See the comment above osc_register_plugin()
    // for why this hook name uses the relative path, not
    // osc_plugin_path(__FILE__).
    osc_add_hook('osclass-escrow/index.php_configure', function () {
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
    osc_add_route(
        'elektron_escrow_wallet',
        'elektron-escrow/wallet',
        'elektron-escrow/wallet',
        'osclass-escrow/controllers/wallet.php',
        true // in the user's own menu; see includes/wallet.php for why this replaced the old 'user_profile_form' hook
    );
