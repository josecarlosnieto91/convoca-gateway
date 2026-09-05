<?php

/**
 * Convoca Gateway
 *
 * @package    Convoca\Gateway
 * @subpackage Includes
 *
 * @copyright  Copyright (C) 2026 Jose Carlos Nieto Ramos
 * @license    GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

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
			self::$settings = get_option( 'convoca_gateway_settings', array() );
		}
		return self::$settings;
	}

	/**
	 * Get the signature version configured by this plugin (fixed, not client-controlled).
	 * Soporta HMAC_SHA256_V1 (3DES) y HMAC_SHA256_V2.
	 */
	public static function signature_version(): string {
		$s = self::settings();
		$v = $s['signature_version'] ?? self::SIG_VERSION;
		return ( 'HMAC_SHA256_V2' === $v ) ? 'HMAC_SHA256_V2' : 'HMAC_SHA256_V1';
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
	 * Checks for CONVOCA_GATEWAY_SECRET_KEY constant first (recommended).
	 * Decrypts DB value if needed.
	 */
	public static function secret_key(): string {
		if ( defined( 'CONVOCA_GATEWAY_SECRET_KEY' ) ) {
			return (string) CONVOCA_GATEWAY_SECRET_KEY;
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
		if ( ! get_option( 'convoca_gateway_secret_needs_reentry' ) ) {
			update_option( 'convoca_gateway_secret_needs_reentry', 1 );
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
		if ( defined( 'CONVOCA_GATEWAY_MERCHANT_CODE' ) ) {
			return CONVOCA_GATEWAY_MERCHANT_CODE;
		}

		$s = self::settings();
		return $s['merchant_code'] ?? '';
	}

	/**
	 * Get the Bizum merchant code.
	 */
	public static function bizum_merchant_code(): string {
		if ( defined( 'CONVOCA_GATEWAY_BIZUM_MERCHANT_CODE' ) ) {
			return CONVOCA_GATEWAY_BIZUM_MERCHANT_CODE;
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
		$merchant_code = ! empty( $params['is_bizum'] ) ? ( self::bizum_merchant_code() ?: self::merchant_code() ) : self::merchant_code();

		$data = array(
			'DS_MERCHANT_AMOUNT'             => (string) ( $params['amount_cents'] ?? 0 ),
			'DS_MERCHANT_ORDER'              => $params['order_id'],
			'DS_MERCHANT_MERCHANTCODE'       => $merchant_code,
			'DS_MERCHANT_CURRENCY'           => self::CURRENCY_EUR,
			'DS_MERCHANT_TRANSACTIONTYPE'    => self::TXTYPE_AUTH,
			'DS_MERCHANT_TERMINAL'           => self::terminal(),
			'DS_MERCHANT_MERCHANTURL'        => $params['url_notify'] ?? '',
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
	 * (convoca_secret_needs_reentry) and block payments until re-entered.
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
			update_option( 'convoca_gateway_secret_needs_reentry', current_time( 'mysql' ), 'no' );
			return false;
		}

		if ( empty( $decrypted ) ) {
			\Convoca\Core\Logger::warning( 'Aviso: La clave secreta de Redsys se descifró pero está vacía.', 'Gateway/Redsys' );
		}

		// Clear the re-entry flag on successful decryption.
		delete_option( 'convoca_gateway_secret_needs_reentry' );

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
			'<form id="conv-redsys-form" method="POST" action="%s">
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
                Por favor, configura el plugin Convoca Gateway en el administrador.
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

		// Decodificar base64url (Redsys puede usar '-' y '_') con modo estricto.
		$decoded = json_decode( base64_decode( strtr( $mp_b64, '-_', '+/' ), true ), true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}

		$order_id = $decoded['Ds_Order'] ?? '';
		if ( empty( $order_id ) ) {
			return false;
		}

		// Versión de firma: NO confiar en el input del atacante para elegir algoritmo.
		// Solo se acepta la versión configurada por el comercio; lo demás se rechaza.
		$configured = self::signature_version();
		if ( $signature_version !== $configured ) {
			\Convoca\Core\Logger::error(
				"Versión de firma inesperada: '$signature_version' (configurada: '$configured').",
				'Gateway/Redsys'
			);
			return false;
		}

		if ( 'HMAC_SHA256_V1' === $configured ) {
			$expected = self::sign( $mp_b64, $order_id );
		} else {
			$expected = self::sign_v2( $mp_b64, $order_id );
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
	 * Check if a Redsys response code indicates an approved purchase.
	 *
	 * Solo '0000' (autorización de compra) marca el pago como realizado.
	 * El rango 0-99 incluye códigos de otras operaciones (devoluciones,
	 * anulaciones) que no deben completar un cobro de cuota.
	 */
	public static function is_approved( string $response_code ): bool {
		return $response_code === '0000';
	}

	/**
	 * Get a human-readable message for a Redsys response code.
	 */
	public static function get_response_message( string $response_code ): string {
		$code = (int) $response_code;
		if ( $code >= 0 && $code <= 99 ) {
			return __( 'Transacción autorizada', 'convoca-gateway' );
		}

		$messages = array(
			101  => __( 'Tarjeta caducada', 'convoca-gateway' ),
			102  => __( 'Tarjeta en excepción transitoria o bajo sospecha de fraude', 'convoca-gateway' ),
			106  => __( 'Intentos de PIN excedidos', 'convoca-gateway' ),
			125  => __( 'Tarjeta no operativa', 'convoca-gateway' ),
			129  => __( 'Código de seguridad (CVV2/CVC2) incorrecto', 'convoca-gateway' ),
			180  => __( 'Tarjeta ajena al servicio', 'convoca-gateway' ),
			184  => __( 'Error en la autenticación del titular', 'convoca-gateway' ),
			190  => __( 'Denegación sin especificar motivo', 'convoca-gateway' ),
			191  => __( 'Fecha de caducidad errónea', 'convoca-gateway' ),
			202  => __( 'Tarjeta en excepción transitoria o bajo sospecha de fraude con retirada de tarjeta', 'convoca-gateway' ),
			904  => __( 'Comercio no registrado en FUC', 'convoca-gateway' ),
			909  => __( 'Error de sistema', 'convoca-gateway' ),
			912  => __( 'Emisor no disponible', 'convoca-gateway' ),
			913  => __( 'Pedido repetido', 'convoca-gateway' ),
			944  => __( 'Sesión caducada', 'convoca-gateway' ),
			950  => __( 'Operación de devolución no permitida', 'convoca-gateway' ),
			9912 => __( 'Emisor no disponible (9912)', 'convoca-gateway' ),
			9914 => __( 'Confirmación denegada', 'convoca-gateway' ),
			9915 => __( 'Usuario ha cancelado el pago', 'convoca-gateway' ),
			9928 => __( 'Anulación de autorización en curso', 'convoca-gateway' ),
			9929 => __( 'Anulación después de 15 minutos', 'convoca-gateway' ),
			9997 => __( 'Transacción simultánea en curso', 'convoca-gateway' ),
			9998 => __( 'Operación en proceso de solicitud de datos de tarjeta', 'convoca-gateway' ),
			9999 => __( 'Operación interrumpida o redirigida al emisor para autenticar', 'convoca-gateway' ),
		);

		return $messages[ $code ] ?? sprintf( __( 'Denegación o error desconocido (Código: %s)', 'convoca-gateway' ), $response_code );
	}
}
