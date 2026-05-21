<?php
/**
 * Redsys TPV Virtual client.
 *
 * Handles form generation and notification verification for Redsys redirect flow.
 * Compatible with HMAC SHA-256 (Ds_SignatureVersion = HMAC_SHA256_V1).
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Redsys_Client {


	/** Redsys endpoint URLs. */
	private const URL_TEST = 'https://sis-t.redsys.es:25443/sis/realizarPago';
	private const URL_PROD = 'https://sis.redsys.es/sis/realizarPago';

	/** Signature version. */
	private const SIG_VERSION = 'HMAC_SHA256_V1';

	/** Currency code for EUR (ISO 4217 numeric). */
	public const CURRENCY_EUR = '978';

	/** Transaction type: authorization. */
	public const TXTYPE_AUTH = '0';

	/** Payment methods. */
	public const METHOD_CARD  = 'T';
	public const METHOD_BIZUM = 'z';
	public const METHOD_ALL   = 'T,z';

	/* ── Settings cache ──────────────────────── */

	private static ?array $settings = null;

	/**
	 * Get gateway settings from wp_options.
	 */
	public static function settings(): array {
		if ( self::$settings === null ) {
			self::$settings = get_option( 'bdg_settings', array() );
		}
		return self::$settings;
	}

	/**
	 * Get the Redsys endpoint URL based on environment.
	 */
	public static function endpoint(): string {
		$s = self::settings();
		return ( $s['environment'] ?? 'test' ) === 'production' ? self::URL_PROD : self::URL_TEST;
	}

	/**
	 * Get the merchant secret key.
	 * Checks for BDG_SECRET_KEY constant first (recommended).
	 * Decrypts DB value if needed.
	 */
	public static function secret_key(): string {
		if ( defined( 'BDG_SECRET_KEY' ) ) {
			return (string) BDG_SECRET_KEY;
		}

		$s   = self::settings();
		$key = $s['secret_key'] ?? '';

		if ( empty( $key ) ) {
			self::mark_decryption_failed();
			return '';
		}

		// Check if value is encrypted (look for prefix).
		if ( str_starts_with( $key, 'enc:' ) ) {
			$decrypted = self::decrypt_key( substr( $key, 4 ) );
			if ( $decrypted === false ) {
				self::mark_decryption_failed();
				return '';
			}
			return $decrypted;
		}

		return $key;
	}

	/**
	 * Mark that secret key decryption has failed so the admin is alerted.
	 */
	private static function mark_decryption_failed(): void {
		if ( ! get_option( 'bdg_secret_needs_reentry' ) ) {
			update_option( 'bdg_secret_needs_reentry', 1 );
			\Convoca\Core\Logger::error(
				'La clave secreta de Redsys no se pudo descifrar. Los pagos no funcionarán hasta que se vuelva a introducir.',
				'Gateway/Security'
			);
		}
	}

	/**
	 * Get the merchant code.
	 */
	public static function merchant_code(): string {
		if ( defined( 'BDG_MERCHANT_CODE' ) ) {
			return BDG_MERCHANT_CODE;
		}

		$s = self::settings();
		return $s['merchant_code'] ?? '';
	}

	/**
	 * Get the Bizum merchant code.
	 */
	public static function bizum_merchant_code(): string {
		if ( defined( 'BDG_BIZUM_MERCHANT_CODE' ) ) {
			return BDG_BIZUM_MERCHANT_CODE;
		}

		$s = self::settings();
		return $s['bizum_merchant_code'] ?? '';
	}

	/**
	 * Get the terminal number.
	 */
	public static function terminal(): string {
		$s = self::settings();
		return $s['terminal'] ?? '001';
	}

	/* ── Order ID generation ─────────────────── */

	/**
	 * Generate a unique Redsys order ID.
	 *
	 * Redsys requires: 4 numeric digits + up to 8 alphanumeric chars (max 12 chars).
	 * Format: YYMMDDXXXXXX (date + 6 random alphanum chars).
	 */
	public static function generate_order_id(): string {
		$date   = gmdate( 'ymd' );          // 6 chars
		$random = strtoupper( substr( wp_generate_password( 6, false ), 0, 6 ) );
		return $date . $random;           // 12 chars total
	}

	/* ── Form building ───────────────────────── */

	/**
	 * Build merchant parameters for a payment request.
	 *
	 * @param array $params {
	 *     @type string $order_id      Unique order ID (12 chars).
	 *     @type int    $amount_cents  Amount in cents (e.g. 3000 = 30.00€).
	 *     @type string $product_desc  Product description for bank statement.
	 *     @type string $pay_methods   Payment methods (T, z, or T,z).
	 *     @type string $url_ok        Success redirect URL.
	 *     @type string $url_ko        Failure redirect URL.
	 *     @type string $url_notify    Server notification URL.
	 * }
	 */
	public static function build_merchant_params( array $params ): string {
		$merchant_code = $params['is_bizum'] ? ( self::bizum_merchant_code() ?: self::merchant_code() ) : self::merchant_code();

		$data = array(
			'DS_MERCHANT_AMOUNT'             => (string) $params['amount_cents'],
			'DS_MERCHANT_ORDER'              => $params['order_id'],
			'DS_MERCHANT_MERCHANTCODE'       => $merchant_code,
			'DS_MERCHANT_CURRENCY'           => self::CURRENCY_EUR,
			'DS_MERCHANT_TRANSACTIONTYPE'    => self::TXTYPE_AUTH,
			'DS_MERCHANT_TERMINAL'           => self::terminal(),
			'DS_MERCHANT_MERCHANTURL'        => $params['url_notify'],
			'DS_MERCHANT_URLOK'              => $params['url_ok'],
			'DS_MERCHANT_URLKO'              => $params['url_ko'],
			'DS_MERCHANT_PRODUCTDESCRIPTION' => mb_substr( $params['product_desc'] ?? '', 0, 125 ),
		);

		if ( ! empty( $params['pay_methods'] ) ) {
			$data['DS_MERCHANT_PAYMETHODS'] = $params['pay_methods'];
		}

		if ( ! empty( $params['tokenize'] ) ) {
			$data['DS_MERCHANT_IDENTIFIER']    = 'REQUIRED';
			$data['DS_MERCHANT_DIRECTPAYMENT'] = 'true';
		}

		return base64_encode( json_encode( $data, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Sign merchant parameters with HMAC SHA-256.
	 *
	 * Following Redsys HMAC_SHA256_V1 guide:
	 * 1. Decode merchant secret (Base64).
	 * 2. Encrypt Order ID with decoded secret using 3DES (EDE3-CBC, zero IV).
	 * 3. HMAC-SHA256 of merchant params using the result as key.
	 * 4. Base64 encode the final HMAC.
	 *
	 * @see https://pagosonline.redsys.es/conexion-redireccion.html.
	 */
	public static function sign( string $merchant_params_b64, string $order_id ): string {
		$secret = self::secret_key();
		if ( empty( $secret ) ) {
			\Convoca\Core\Logger::error( 'Falta la clave secreta de Redsys para la firma.', 'Gateway/Redsys' );
			return '';
		}

		$decoded_secret = base64_decode( $secret );

		// Derive key using 3DES encryption of the Order ID.
		$derived_key = self::encrypt_3des( $order_id, $decoded_secret );

		// HMAC-SHA256 of the Base64 params.
		$hmac = hash_hmac( 'sha256', $merchant_params_b64, $derived_key, true );

		return base64_encode( $hmac );
	}

	/**
	 * 3DES encryption (Redsys specific).
	 * Encrypts data (Order ID) with merchant decoded key.
	 */
	private static function encrypt_3des( string $data, string $key ): string {
		// Redsys requires padding the message to 8-byte blocks with null bytes.
		$l_message = strlen( $data );
		if ( $l_message % 8 != 0 ) {
			$data .= str_repeat( "\0", 8 - ( $l_message % 8 ) );
		}

		// 3DES-CBC with zero IV.
		$iv = str_repeat( "\0", 8 );

		return openssl_encrypt(
			$data,
			'des-ede3-cbc',
			$key,
			OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
			$iv
		);
	}

	/**
	 * Encrypt a key for somewhat safe DB storage.
	 *
	 * Uses AUTH_SALT (or wp_salt() as fallback) as the encryption key.
	 * IMPORTANT: If AUTH_SALT is changed in wp-config.php, previously encrypted
	 * keys will become undecryptable. Admins must re-enter the Redsys secret key
	 * after changing WordPress salts. The system will show an admin notice
	 * (bdg_secret_needs_reentry) and block payments until re-entered.
	 */
	public static function encrypt_key( string $value ): string {
		if ( empty( $value ) ) {
			return '';
		}
		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$salt      = defined( 'AUTH_SALT' ) ? AUTH_SALT : wp_salt();
		$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $salt, 0, $iv );
		return 'enc:' . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt a key from DB.
	 *
	 * @param string $payload Encrypted payload (Base64).
	 * @return string|false Decrypted key or false on failure.
	 */
	private static function decrypt_key( string $payload ): string|false {
		$data      = base64_decode( $payload );
		$iv_len    = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = substr( $data, 0, $iv_len );
		$encrypted = substr( $data, $iv_len );
		$salt      = defined( 'AUTH_SALT' ) ? AUTH_SALT : wp_salt();

		$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $salt, 0, $iv );

		if ( $decrypted === false ) {
			\Convoca\Core\Logger::error( 'Error crítico: No se pudo descifrar la clave secreta de Redsys (openssl_decrypt falló).', 'Gateway/Redsys' );
			// Mark settings as needing re-entry.
			update_option( 'bdg_secret_needs_reentry', current_time( 'mysql' ), 'no' );
			return false;
		}

		if ( empty( $decrypted ) ) {
			\Convoca\Core\Logger::warning( 'Aviso: La clave secreta de Redsys se descifró pero está vacía.', 'Gateway/Redsys' );
		}

		// Clear the re-entry flag on successful decryption.
		delete_option( 'bdg_secret_needs_reentry' );

		return (string) $decrypted;
	}

	/**
	 * Build the complete HTML form for Redsys redirect.
	 *
	 * @param array $params See build_merchant_params().
	 * @return string HTML form (auto-submitting).
	 */
	public static function build_form( array $params ): string {
		$mp_b64    = self::build_merchant_params( $params );
		$signature = self::sign( $mp_b64, $params['order_id'] );
		$endpoint  = self::endpoint();

		return sprintf(
			'<form id="bdg-redsys-form" method="POST" action="%s">
                <input type="hidden" name="Ds_SignatureVersion" value="%s">
                <input type="hidden" name="Ds_MerchantParameters" value="%s">
                <input type="hidden" name="Ds_Signature" value="%s">
                <noscript><button type="submit" class="convoca-btn convoca-btn-primary">Continuar al pago</button></noscript>
            </form>',
			esc_url( $endpoint ),
			esc_attr( self::SIG_VERSION ),
			esc_attr( $mp_b64 ),
			esc_attr( $signature )
		);
	}

	/**
	 * Renders the Redsys redirect form.
	 *
	 * @param array $params See build_merchant_params().
	 * @return string HTML form or error message.
	 */
	public static function render_redsys_redirect( array $params ): string {
		// Validate configuration before building form.
		if ( empty( self::merchant_code() ) || empty( self::secret_key() ) ) {
			return '<div class="convoca-alert convoca-alert--danger">
                <strong>Error de configuración:</strong> Faltan las claves de Redsys.<br>
                Por favor, configura el plugin Biodevas Gateway en el administrador.
            </div>';
		}

		return self::build_form( $params );
	}

	/* ── Notification verification ──────────────── */

	/**
	 * Redsys HMAC_SHA256_V2 signature.
	 *
	 * V2 usa HMAC-SHA256 para la derivación de clave en lugar de 3DES:
	 * 1. Base64 decode del secret
	 * 2. HMAC-SHA256(orderId, decoded_secret) → derived_key
	 * 3. HMAC-SHA256(merchantParams, derived_key) → signature
	 * 4. Base64 encode
	 */
	public static function sign_v2( string $merchant_params_b64, string $order_id ): string {
		$secret = self::secret_key();
		if ( empty( $secret ) ) {
			\Convoca\Core\Logger::error( 'Falta la clave secreta de Redsys para la firma V2.', 'Gateway/Redsys' );
			return '';
		}

		$decoded_secret = base64_decode( $secret );

		// Derivar clave con HMAC-SHA256 en lugar de 3DES.
		$derived_key = hash_hmac( 'sha256', $order_id, $decoded_secret, true );

		// HMAC-SHA256 de los parámetros.
		$hmac = hash_hmac( 'sha256', $merchant_params_b64, $derived_key, true );

		return base64_encode( $hmac );
	}

	/**
	 * Verify and decode a Redsys notification.
	 * Soporta HMAC_SHA256_V1 y HMAC_SHA256_V2.
	 *
	 * @param array $post_data $_POST data from Redsys callback.
	 * @return array|false Decoded parameters or false if invalid signature.
	 */
	public static function verify_notification( array $post_data ): array|false {
		$signature_version = $post_data['Ds_SignatureVersion'] ?? '';

		$mp_b64    = $post_data['Ds_MerchantParameters'] ?? '';
		$signature = $post_data['Ds_Signature'] ?? '';

		if ( empty( $mp_b64 ) || empty( $signature ) ) {
			return false;
		}

		$decoded = json_decode( base64_decode( $mp_b64 ), true );
		if ( ! $decoded ) {
			return false;
		}

		$order_id = $decoded['Ds_Order'] ?? '';
		if ( empty( $order_id ) ) {
			return false;
		}

		// Elegir método de firma según la versión.
		if ( $signature_version === 'HMAC_SHA256_V1' ) {
			$expected = self::sign( $mp_b64, $order_id );
		} elseif ( $signature_version === 'HMAC_SHA256_V2' ) {
			$expected = self::sign_v2( $mp_b64, $order_id );
		} else {
			\Convoca\Core\Logger::error(
				"Invalid signature version: '$signature_version'. Expected HMAC_SHA256_V1 or V2.",
				'Gateway/Redsys'
			);
			return false;
		}

		// URL-safe base64 comparison.
		$sig_clean = strtr( $signature, '-_', '+/' );
		$exp_clean = strtr( $expected, '-_', '+/' );

		if ( ! hash_equals( $exp_clean, $sig_clean ) ) {
			return false;
		}

		return $decoded;
	}

	/**
	 * Check if a Redsys response code indicates success.
	 *
	 * Response codes 0000-0099 mean approved.
	 */
	public static function is_approved( string $response_code ): bool {
		$code = (int) $response_code;
		return $code >= 0 && $code <= 99;
	}

	/**
	 * Get a human-readable message for a Redsys response code.
	 */
	public static function get_response_message( string $response_code ): string {
		$code = (int) $response_code;
		if ( $code >= 0 && $code <= 99 ) {
			return 'Transacción autorizada';
		}

		$messages = array(
			101  => 'Tarjeta caducada',
			102  => 'Tarjeta en excepción transitoria o bajo sospecha de fraude',
			106  => 'Intentos de PIN excedidos',
			125  => 'Tarjeta no operativa',
			129  => 'Código de seguridad (CVV2/CVC2) incorrecto',
			180  => 'Tarjeta ajena al servicio',
			184  => 'Error en la autenticación del titular',
			190  => 'Denegación sin especificar motivo',
			191  => 'Fecha de caducidad errónea',
			202  => 'Tarjeta en excepción transitoria o bajo sospecha de fraude con retirada de tarjeta',
			904  => 'Comercio no registrado en FUC',
			909  => 'Error de sistema',
			912  => 'Emisor no disponible',
			913  => 'Pedido repetido',
			944  => 'Sesión caducada',
			950  => 'Operación de devolución no permitida',
			9912 => 'Emisor no disponible (9912)',
			9914 => 'Confirmación denegada',
			9915 => 'Usuario ha cancelado el pago',
			9928 => 'Anulación de autorización en curso',
			9929 => 'Anulación después de 15 minutos',
			9997 => 'Transacción simultánea en curso',
			9998 => 'Operación en proceso de solicitud de datos de tarjeta',
			9999 => 'Operación interrumpida o redirigida al emisor para autenticar',
		);

		return $messages[ $code ] ?? "Denegación o error desconocido (Código: {$response_code})";
	}
}
