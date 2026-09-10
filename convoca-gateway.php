<?php
/**
 * Plugin Name:       Convoca Gateway — Payment Gateway
 * Plugin URI:        https://getconvoca.app
 * Description:       Redsys payment gateway (card + Bizum).
 * Version:           2.6.6
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      7.1
 * Author:            Jose Carlos Nieto Ramos
 * Author URI:        https://getconvoca.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       convoca-gateway
 * Domain Path:       /languages
 * Requires Plugins:  convoca-core
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load translations.
add_action(
	'init',
	function () {
		wp_set_script_translations( 'convoca-gateway-scripts', 'convoca-gateway', plugin_dir_path( __FILE__ ) . 'languages/' );
		load_plugin_textdomain( 'convoca-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	} 
);

/* ── Composer autoload ─────────────────────────────── */
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
}

/*
── Convoca Core fallback ──────────────────────────
*/
// Core classes auto-loaded via Convoca Core's Composer PSR-4

/* ── Startup guard: convoca-common must be active ── */
if ( ! class_exists( '\\Convoca\\Core\\Utils' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' .
			'Convoca Gateway requiere el plugin Convoca Common Utilities activo.' .
			'</p></div>';
		}
	);
	return;
}

/* ── Constants ────────────────────────────────── */
if ( ! defined( 'CONVOCA_GATEWAY_VERSION' ) ) {
	// Cache-buster de los assets. Debe seguir la versión del plugin: si se queda
	// fija, la CDN y los navegadores sirven JS/CSS viejos para esa misma URL.
	$convoca_gateway_header = get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' );
	define( 'CONVOCA_GATEWAY_VERSION', ! empty( $convoca_gateway_header['Version'] ) ? $convoca_gateway_header['Version'] : '2.6.6' );
}
if ( ! defined( 'CONVOCA_GATEWAY_DB_VERSION' ) ) {
	define( 'CONVOCA_GATEWAY_DB_VERSION', '1.0.2' );
}
if ( ! defined( 'CONVOCA_GATEWAY_DIR' ) ) {
	define( 'CONVOCA_GATEWAY_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'CONVOCA_GATEWAY_URL' ) ) {
	define( 'CONVOCA_GATEWAY_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'CONVOCA_GATEWAY_BASENAME' ) ) {
	define( 'CONVOCA_GATEWAY_BASENAME', plugin_basename( __FILE__ ) );
}

/*
── Autoload ───────────────────────────────────
*/
// PSR-4 autoloading handled by Composer (vendor/autoload.php)

/* ── Deactivation cleanup ── */
register_deactivation_hook(
	__FILE__,
	function () {
		Link_Expiry::unschedule();
	}
);

/* ── Bootstrap ────────────────────────────────── */
add_action(
	'plugins_loaded',
	function () {
		new CPT_Pago();
		new Payment_Handler();
		new Email_Notifications();
		new Block_Gateway();
		if ( is_admin() ) {
			new Admin_Payments();
			new Admin_Settings();
			new Admin_Generador();
			new Dashboard_Widget();
		}

		// Upgrade Manager (checks for DB version upgrades on admin_init).
		new Gateway_Upgrade_Manager();
	}
);

/* ── REST API Notifications ───────────────────── */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'convoca-gateway/v1',
			'/notify',
			array(
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) {
					// Rate limit ligero por IP (anti-DoS): 30 notificaciones/min máximo.
					$ip   = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
					$rkey = 'convoca_notify_rt_' . md5( $ip );
					$rcnt = (int) get_transient( $rkey );
					if ( $rcnt > 30 ) {
						return new \WP_REST_Response(
							array(
								'code'    => 'too_many_requests',
								'message' => 'Too Many Requests',
							),
							429
						);
					}
					set_transient( $rkey, $rcnt + 1, 60 );

					// Límite de tamaño del payload.
					if ( strlen( $request->get_body() ) > 16384 ) {
						return new \WP_REST_Response(
							array(
								'code'    => 'payload_too_large',
								'message' => 'Payload too large',
							),
							413
						);
					}

					$handler = new \Convoca\Gateway\Payment_Handler();
					$params  = $request->get_params(); // DS_MerchantParameters, DS_Signature, etc.
					$result  = $handler->process_notification( $params );

					if ( is_wp_error( $result ) ) {
						return new \WP_REST_Response(
							array(
								'code'    => $result->get_error_code(),
								'message' => $result->get_error_message(),
							),
							400
						);
					}

					// Return plain text 'OK' as Redsys expects.
					return new \WP_REST_Response( 'OK', 200 );
				},
				'permission_callback' => '__return_true', // IP security is handled inside process_notification.
			)
		);
	}
);

/* ── Activation ───────────────────────────────── */
register_activation_hook(
	__FILE__,
	function () {
		CPT_Pago::register();
		flush_rewrite_rules();
		add_option( 'convoca_gateway_db_version', CONVOCA_GATEWAY_DB_VERSION, '', false );
		Link_Expiry::schedule();
	}
);

/* ── Cron: aviso de caducidad de enlaces (D21b) ── */
add_action( 'convoca_gateway_expiry_notice', array( \Convoca\Gateway\Link_Expiry::class, 'run_expiry_notices' ) );

/* ── Recibos/justificantes: numeración anual al completar pago (D23/D24) ── */
add_action( 'convoca_gateway_payment_completed', array( \Convoca\Gateway\Receipt_Generator::class, 'maybe_generate' ), 5, 1 );

/* ── Public API Functions ─────────────────────── */

if ( ! function_exists( 'convoca_gateway_create_payment' ) ) {
	/**
	 * Global wrapper to create a payment.
	 *
	 * @param array $args Payment arguments (amount_cents, origin, etc.).
	 * @return array|\WP_Error Payment creation result with URL.
	 */
	function convoca_gateway_create_payment( array $args ): array|\WP_Error {
		return \Convoca\Gateway\Payment_Handler::create_payment( $args );
	}
}

if ( ! function_exists( 'convoca_get_gateway_settings' ) ) {
	/**
	 * Retrieve Gateway settings.
	 *
	 * @return array
	 */
	function convoca_get_gateway_settings(): array {
		return get_option( 'convoca_gateway_settings', array() );
	}
}
