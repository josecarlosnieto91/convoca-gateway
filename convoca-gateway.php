<?php
/**
 * Plugin Name: Convoca Gateway — Payment Gateway
 * Plugin URI: https://biodevas.org
 * Description: Redsys payment gateway (card + Bizum).
 * Version: 2.6.1
 * Author:      Jose Carlos Nieto Ramos
 * Author URI:  https://josecarlosnietoramos.wordpress.com
 * Text Domain: convoca-gateway
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: GPL2
 */


namespace Convoca\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

/* ── Startup guard: biodevas-common must be active ── */
if (!class_exists('\\Convoca\\Core\\Utils')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' .
            'Biodevas Gateway requiere el plugin Biodevas Common Utilities activo.' .
            '</p></div>';
    });
    return;
}

/* ── Constants ────────────────────────────────── */
if (!defined('BDG_VERSION')) {
    define('BDG_VERSION', '2.6.1');
}
if (!defined('BDG_DB_VERSION')) {
    define('BDG_DB_VERSION', '1.0.2');
}
if (!defined('BDG_DIR')) {
    define('BDG_DIR', plugin_dir_path(__FILE__));
}
if (!defined('BDG_URL')) {
    define('BDG_URL', plugin_dir_url(__FILE__));
}
if (!defined('BDG_BASENAME')) {
    define('BDG_BASENAME', plugin_basename(__FILE__));
}

/* ── Autoload ─────────────────────────────────── */
spl_autoload_register(function (string $class) {
    $prefix = 'Convoca\\Gateway\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace($prefix, '', $class);
    $relative = strtolower(str_replace('_', '-', $relative));

    foreach (['includes/', 'admin/'] as $dir) {
        $file = BDG_DIR . $dir . 'class-' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

/* ── Deactivation cleanup ── */
register_deactivation_hook(__FILE__, function () {
    // Gateway does not schedule cron events currently.
    // Placeholder for future cron cleanup.
});

/* ── Bootstrap ────────────────────────────────── */
add_action('plugins_loaded', function () {
    new CPT_Pago();
    new Payment_Handler();
    new Email_Notifications();
    new Block_Gateway();
    if (is_admin()) {
        new Admin_Payments();
        new Admin_Settings();
        new Admin_Generador();
        new Dashboard_Widget();
    }

    // Upgrade Manager (checks for DB version upgrades on admin_init).
    new Gateway_Upgrade_Manager();
});

/* ── REST API Notifications ───────────────────── */
add_action('rest_api_init', function () {
    register_rest_route('biodevas-gateway/v1', '/notify', [
        'methods' => 'POST',
        'callback' => function (\WP_REST_Request $request) {
            $handler = new \Convoca\Gateway\Payment_Handler();
            $params = $request->get_params(); // DS_MerchantParameters, DS_Signature, etc.
            $result = $handler->process_notification($params);

            if (is_wp_error($result)) {
                return new \WP_REST_Response([
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message()
                ], 400);
            }

            // Return plain text 'OK' as Redsys expects.
            return new \WP_REST_Response('OK', 200);
        },
        'permission_callback' => '__return_true', // IP security is handled inside process_notification.
    ]);
});

/* ── Activation ───────────────────────────────── */
register_activation_hook(__FILE__, function () {
    CPT_Pago::register();
    flush_rewrite_rules();
    add_option('bdg_db_version', BDG_DB_VERSION, '', false);
});

/* ── Public API Functions ─────────────────────── */

if (!function_exists('bdv_gateway_create_payment')) {
    /**
     * Global wrapper to create a payment.
     *
     * @param array $args Payment arguments (amount_cents, origin, etc.).
     * @return array|\WP_Error Payment creation result with URL.
     */
    function bdv_gateway_create_payment(array $args): array|\WP_Error
    {
        return \Convoca\Gateway\Payment_Handler::create_payment($args);
    }
}

if (!function_exists('bdv_get_gateway_settings')) {
    /**
     * Retrieve Gateway settings.
     *
     * @return array
     */
    function bdv_get_gateway_settings(): array
    {
        return get_option('bdg_settings', []);
    }
}
