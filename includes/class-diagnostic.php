<?php
/**
 * Diagnostic checks for Biodevas Gateway configuration.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Diagnostic {

	const SEVERITY_OK      = 'ok';
	const SEVERITY_WARNING = 'warning';
	const SEVERITY_ERROR   = 'error';
	const CACHE_KEY        = 'bdg_diagnostic_cache';
	const CACHE_TTL        = 3600; // 1 hour

	/**
	 * Run all diagnostic checks.
	 *
	 * @param bool $force Force fresh run (bypass cache).
	 * @return array Array of results.
	 */
	public static function run_all( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_option( self::CACHE_KEY, false );
			if ( $cached && isset( $cached['timestamp'] ) && ( time() - $cached['timestamp'] < self::CACHE_TTL ) ) {
				return $cached['results'];
			}
		}

		$results = array(
			self::check_environment(),
			self::check_merchant_code(),
			self::check_terminal(),
			self::check_return_pages(),
			self::check_offline_methods(),
			self::check_php_version(),
			self::check_openssl(),
			self::check_database(),
		);

		// Save to cache
		update_option(
			self::CACHE_KEY,
			array(
				'timestamp'    => time(),
				'results'      => $results,
				'has_errors'   => self::has_errors( $results ),
				'has_warnings' => self::has_warnings( $results ),
			)
		);

		return $results;
	}

	/**
	 * Check environment and secret key.
	 *
	 * @return array
	 */
	public static function check_environment(): array {
		$settings    = get_option( 'bdg_settings', array() );
		$environment = $settings['environment'] ?? 'test';
		$secret_key  = $settings['secret_key'] ?? '';

		if ( empty( $secret_key ) ) {
			return self::result( 'environment', 'Modo de entorno', 'Clave SHA-256 no configurada', self::SEVERITY_ERROR, 'Configura la clave secreta en la pestaña General' );
		}

		$test_keys = array(
			'sq7H8UFiN7w5oZ8xP2vT9mL4kJ6gH3n',
			'b0c9d2E4fA6gH8iJ0kL2mN4oP6qR8sT',
			'test',
			'Test',
			'SHA256',
			'sha256',
		);

		$is_test_key  = in_array( $secret_key, $test_keys, true );
		$is_test_mode = $environment === 'test';

		if ( $is_test_mode ) {
			$message  = 'Modo: Prueba (sandbox)';
			$severity = self::SEVERITY_OK;
		} elseif ( $is_test_key ) {
			$message  = 'Modo: Producción | ADVERTENCIA: La clave parece ser de test';
			$severity = self::SEVERITY_WARNING;
		} else {
			$message  = 'Modo: Producción | Clave configurada correctamente';
			$severity = self::SEVERITY_OK;
		}

		return self::result( 'environment', 'Modo de entorno', $message, $severity, 'Si estás en producción, asegúrate de usar la clave SHA-256 de producción, no la de test' );
	}

	/**
	 * Check merchant code (FUC).
	 *
	 * @return array
	 */
	public static function check_merchant_code(): array {
		$settings      = get_option( 'bdg_settings', array() );
		$merchant_code = $settings['merchant_code'] ?? '';

		if ( empty( $merchant_code ) ) {
			return self::result( 'merchant_code', 'Código de comercio (FUC)', 'No configurado', self::SEVERITY_ERROR, 'Configura el FUC en la pestaña General' );
		}

		// Basic validation: 9 digits
		if ( ! preg_match( '/^\d{9}$/', $merchant_code ) ) {
			return self::result( 'merchant_code', 'Código de comercio (FUC)', "FUC inválido: $merchant_code", self::SEVERITY_WARNING, 'El FUC debe tener 9 dígitos' );
		}

		return self::result( 'merchant_code', 'Código de comercio (FUC)', "FUC: $merchant_code (válido)", self::SEVERITY_OK );
	}

	/**
	 * Check terminal.
	 *
	 * @return array
	 */
	public static function check_terminal(): array {
		$settings = get_option( 'bdg_settings', array() );
		$terminal = $settings['terminal'] ?? '';

		if ( empty( $terminal ) ) {
			return self::result( 'terminal', 'Terminal', 'No configurado', self::SEVERITY_WARNING, 'Configura el terminal (por defecto 001)', array( __CLASS__, 'fix_default_terminal' ) );
		}

		return self::result( 'terminal', 'Terminal', "Terminal: $terminal", self::SEVERITY_OK );
	}

	/**
	 * Check return pages (OK and KO).
	 *
	 * @return array
	 */
	public static function check_return_pages(): array {
		$settings   = get_option( 'bdg_settings', array() );
		$ok_page_id = (int) ( $settings['ok_page_id'] ?? 0 );
		$ko_page_id = (int) ( $settings['ko_page_id'] ?? 0 );

		$results   = array();
		$has_error = false;

		// Check OK page
		if ( $ok_page_id > 0 ) {
			$ok_page = get_post( $ok_page_id );
			if ( $ok_page && $ok_page->post_status === 'publish' ) {
				$has_ok_shortcode = has_shortcode( $ok_page->post_content, 'convoca_pago_ok' );
				if ( $has_ok_shortcode ) {
					$results['ok_page'] = self::result( 'ok_page', 'Página de pago OK', 'Configurada y con shortcode', self::SEVERITY_OK );
				} else {
					$results['ok_page'] = self::result( 'ok_page', 'Página de pago OK', 'Existe pero sin shortcode [biodevas_pago_ok]', self::SEVERITY_WARNING );
				}
			} else {
				$results['ok_page'] = self::result( 'ok_page', 'Página de pago OK', 'No existe o no publicada', self::SEVERITY_ERROR, 'La página configurada no existe o no está publicada', array( __CLASS__, 'fix_create_pages' ) );
				$has_error          = true;
			}
		} else {
			$results['ok_page'] = self::result( 'ok_page', 'Página de pago OK', 'No configurada', self::SEVERITY_ERROR, 'Crea una página con el shortcode [biodevas_pago_ok]', array( __CLASS__, 'fix_create_pages' ) );
			$has_error          = true;
		}

		// Check KO page
		if ( $ko_page_id > 0 ) {
			$ko_page = get_post( $ko_page_id );
			if ( $ko_page && $ko_page->post_status === 'publish' ) {
				$has_ko_shortcode = has_shortcode( $ko_page->post_content, 'convoca_pago_ko' );
				if ( $has_ko_shortcode ) {
					$results['ko_page'] = self::result( 'ko_page', 'Página de pago error', 'Configurada y con shortcode', self::SEVERITY_OK );
				} else {
					$results['ko_page'] = self::result( 'ko_page', 'Página de pago error', 'Existe pero sin shortcode [biodevas_pago_ko]', self::SEVERITY_WARNING );
				}
			} else {
				$results['ko_page'] = self::result( 'ko_page', 'Página de pago error', 'No existe o no publicada', self::SEVERITY_ERROR, 'La página configurada no existe o no está publicada', array( __CLASS__, 'fix_create_pages' ) );
				$has_error          = true;
			}
		} else {
			$results['ko_page'] = self::result( 'ko_page', 'Página de pago error', 'No configurada', self::SEVERITY_ERROR, 'Crea una página con el shortcode [biodevas_pago_ko]', array( __CLASS__, 'fix_create_pages' ) );
			$has_error          = true;
		}

		return array(
			'slug'         => 'return_pages',
			'title'        => 'Páginas de retorno',
			'description'  => 'Verifica que las páginas de éxito y error existen y tienen los shortcodes',
			'severity'     => $has_error ? self::SEVERITY_ERROR : self::SEVERITY_OK,
			'message'      => $has_error ? 'Hay páginas faltantes' : 'Páginas OK y error configuradas',
			'fix'          => $has_error ? 'Haga clic en Reparar para crear las páginas automáticamente' : null,
			'fix_callback' => $has_error ? array( __CLASS__, 'fix_create_pages' ) : null,
			'children'     => $results,
		);
	}

	/**
	 * Check offline payment methods.
	 *
	 * @return array
	 */
	public static function check_offline_methods(): array {
		$settings = get_option( 'bdg_settings', array() );
		$iban     = $settings['iban'] ?? '';

		if ( ! empty( $iban ) ) {
			$beneficiary = $settings['beneficiary'] ?? '';
			$message     = 'Transferencia configurada';
			if ( $beneficiary ) {
				$message .= " | Beneficiario: $beneficiary";
			}
			return self::result( 'offline_methods', 'Métodos offline', $message, self::SEVERITY_OK );
		}

		return self::result( 'offline_methods', 'Métodos offline', 'No configurados (opcional)', self::SEVERITY_WARNING, 'Si deseas permitir pagos por transferencia, configura el IBAN en la pestaña Transferencia' );
	}

	/**
	 * Check PHP version.
	 *
	 * @return array
	 */
	public static function check_php_version(): array {
		$version     = PHP_VERSION;
		$major_minor = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );

		if ( version_compare( $version, '8.0', '>=' ) ) {
			return self::result( 'php_version', 'PHP', "Versión: $version", self::SEVERITY_OK );
		}

		return self::result( 'php_version', 'PHP', "Versión: $version (no recomendada)", self::SEVERITY_ERROR, 'Se recomienda PHP 8.0 o superior. Contacta con tu hosting para actualizar.' );
	}

	/**
	 * Check OpenSSL extension.
	 *
	 * @return array
	 */
	public static function check_openssl(): array {
		if ( extension_loaded( 'openssl' ) && defined( 'OPENSSL_VERSION_TEXT' ) ) {
			return self::result( 'openssl', 'OpenSSL', 'Extensión activa: ' . OPENSSL_VERSION_TEXT, self::SEVERITY_OK );
		}

		return self::result( 'openssl', 'OpenSSL', 'Extensión no disponible', self::SEVERITY_ERROR, 'La extensión OpenSSL es necesaria para la firma SHA-256. Contacta con tu hosting.' );
	}

	/**
	 * Check database connectivity and logs table.
	 *
	 * @return array
	 */
	public static function check_database(): array {
		global $wpdb;

		$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}biodevas_logs'" );

		if ( $table_exists ) {
			return self::result( 'database', 'Base de datos', 'Tabla de logs existente', self::SEVERITY_OK );
		}

		return self::result( 'database', 'Base de datos', 'Tabla de logs no encontrada', self::SEVERITY_WARNING, 'Los logs se almacenan en la tabla biodevas_logs. Activa Biodevas Common para crearla.' );
	}

	/**
	 * Get menu badge data.
	 *
	 * @return array {severity, message}
	 */
	public static function get_menu_badge(): array {
		$cached = get_option( self::CACHE_KEY, false );

		if ( ! $cached || ! isset( $cached['timestamp'] ) || ( time() - $cached['timestamp'] > self::CACHE_TTL ) ) {
			$cached = array(
				'has_errors'   => false,
				'has_warnings' => false,
			);
		}

		if ( ! empty( $cached['has_errors'] ) ) {
			return array(
				'severity' => 'error',
				'message'  => 'Hay errores de configuración',
			);
		}

		if ( ! empty( $cached['has_warnings'] ) ) {
			return array(
				'severity' => 'warning',
				'message'  => 'Hay advertencias',
			);
		}

		return array(
			'severity' => 'ok',
			'message'  => 'Todo correcto',
		);
	}

	/**
	 * Fix: Set default terminal to 001.
	 */
	public static function fix_default_terminal(): array {
		$settings             = get_option( 'bdg_settings', array() );
		$settings['terminal'] = '001';
		update_option( 'bdg_settings', $settings );

		return array(
			'success' => true,
			'message' => 'Terminal configurado a 001',
		);
	}

	public static function fix_create_pages(): array {
		$settings = get_option( 'bdg_settings', array() );
		$created  = array();

		$ok_page_id = (int) ( $settings['ok_page_id'] ?? 0 );
		$ko_page_id = (int) ( $settings['ko_page_id'] ?? 0 );

		// Check if OK page exists and is published
		if ( $ok_page_id <= 0 || ! get_post( $ok_page_id ) || get_post( $ok_page_id )->post_status !== 'publish' ) {
			// Check if page with this slug already exists
			$existing = get_page_by_path( 'pago-completado' );
			if ( $existing && $existing->post_status === 'publish' ) {
				$settings['ok_page_id'] = $existing->ID;
			} else {
				$new_ok_page_id = wp_insert_post(
					array(
						'post_title'   => 'Pago Completado',
						'post_content' => '<!-- wp:shortcode -->[biodevas_pago_ok]<!-- /wp:shortcode -->',
						'post_status'  => 'publish',
						'post_type'    => 'page',
						'post_name'    => 'pago-completado',
					)
				);

				if ( $new_ok_page_id && ! is_wp_error( $new_ok_page_id ) ) {
					$settings['ok_page_id'] = $new_ok_page_id;
					$created[]              = 'Página de pago OK';
				}
			}
		}

		// Check if KO page exists and is published
		if ( $ko_page_id <= 0 || ! get_post( $ko_page_id ) || get_post( $ko_page_id )->post_status !== 'publish' ) {
			// Check if page with this slug already exists
			$existing = get_page_by_path( 'pago-error' );
			if ( $existing && $existing->post_status === 'publish' ) {
				$settings['ko_page_id'] = $existing->ID;
			} else {
				$new_ko_page_id = wp_insert_post(
					array(
						'post_title'   => 'Pago Error',
						'post_content' => '<!-- wp:shortcode -->[biodevas_pago_ko]<!-- /wp:shortcode -->',
						'post_status'  => 'publish',
						'post_type'    => 'page',
						'post_name'    => 'pago-error',
					)
				);

				if ( $new_ko_page_id && ! is_wp_error( $new_ko_page_id ) ) {
					$settings['ko_page_id'] = $new_ko_page_id;
					$created[]              = 'Página de pago error';
				}
			}
		}

		update_option( 'bdg_settings', $settings );

		if ( empty( $created ) ) {
			return array(
				'success' => true,
				'message' => 'Las páginas ya estaban configuradas',
			);
		}

		return array(
			'success' => true,
			'message' => 'Creadas: ' . implode( ', ', $created ),
		);
	}

	// Helper methods

	private static function result( string $slug, string $title, string $message, string $severity, string $fix = null, $fix_callback = null ): array {
		return array(
			'slug'         => $slug,
			'title'        => $title,
			'message'      => $message,
			'severity'     => $severity,
			'fix'          => $fix,
			'fix_callback' => $fix_callback,
		);
	}

	public static function has_errors( array $results ): bool {
		foreach ( $results as $result ) {
			if ( isset( $result['children'] ) ) {
				foreach ( $result['children'] as $child ) {
					if ( $child['severity'] === self::SEVERITY_ERROR ) {
						return true;
					}
				}
			}
			if ( ( $result['severity'] ?? '' ) === self::SEVERITY_ERROR ) {
				return true;
			}
		}
		return false;
	}

	public static function has_warnings( array $results ): bool {
		foreach ( $results as $result ) {
			if ( isset( $result['children'] ) ) {
				foreach ( $result['children'] as $child ) {
					if ( $child['severity'] === self::SEVERITY_WARNING ) {
						return true;
					}
				}
			}
			if ( ( $result['severity'] ?? '' ) === self::SEVERITY_WARNING ) {
				return true;
			}
		}
		return false;
	}
}
