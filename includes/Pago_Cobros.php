<?php
/**
 * Las operaciones de cobro del gateway, separadas de la página de pago.
 *
 * Crear un cobro, renovarlo, aplicar el resultado del TPV, el token del socio y los enlaces de pago.
 * Todo era estático y no tocaba la pantalla; vivía mezclado con las 45 funciones del manejador de
 * página. `Payment_Handler` conserva su API pública como delegado de una línea, porque
 * `convoca_gateway_create_payment()` y otros plugins la llaman.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cobros.
 */
final class Pago_Cobros {

	/**
	 * Get the stored recurring token for a member.
	 */
	public static function token_de_socio( int $member_id ): string {
		global $wpdb;
		$token = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm_tok.meta_value
             FROM {$wpdb->postmeta} pm_orig
             JOIN {$wpdb->postmeta} pm_tok ON pm_orig.post_id = pm_tok.post_id AND pm_tok.meta_key = '_convoca_redsys_merchant_id'
             JOIN {$wpdb->postmeta} pm_stat ON pm_orig.post_id = pm_stat.post_id AND pm_stat.meta_key = '_convoca_status' AND pm_stat.meta_value = 'paid'
             WHERE pm_orig.meta_key = '_convoca_origin_id' AND pm_orig.meta_value = %d
               AND pm_tok.meta_value != ''
             ORDER BY pm_orig.post_id DESC LIMIT 1",
				$member_id
			)
		);
		return (string) ( $token ?: '' );
	}

	/**
	 * Execute a server-to-server token charge for a member's automatic renewal.
	 *
	 * Estrategia de renovación automática por tarjeta (decisión funcional 2026-09):
	 * cuando el miembro tiene pago_recurrente Y un merchant id almacenado, el cron
	 * de Members DEBE intentar el cargo real con el token (REST Redsys), no solo
	 * crear un enlace de pago. Si el cargo se aprueba, el pago se marca 'paid' y se
	 * dispara convoca_gateway_payment_completed (el listener de Members reactiva la
	 * cuota). Si no hay token o el cargo falla, devuelve WP_Error para que el cron
	 * decida reintentos / enlace manual.
	 *
	 * @param int    $member_id     Miembro a renovar.
	 * @param int    $amount_cents  Importe en céntimos.
	 * @param string $product_desc  Descripción para el extracto.
	 * @return array{pago_id:int, payment_url:string, status:string, response:string}|\WP_Error
	 */
	public static function renovar_socio( int $member_id, int $amount_cents, string $product_desc ): array|\WP_Error {
		$token = self::token_de_socio( $member_id );
		if ( empty( $token ) ) {
			return new \WP_Error( 'no_token', 'El socio no tiene token de tarjeta almacenado.' );
		}

		// 1. Create the payment (same as manual renewal).
		$payment = self::crear_pago(
			array(
				'origin'       => 'members',
				'origin_id'    => $member_id,
				'amount_cents' => $amount_cents,
				'product_desc' => mb_substr( $product_desc, 0, 125 ),
				'method'       => 'tarjeta',
				'tokenize'     => false,
			)
		);
		if ( is_wp_error( $payment ) ) {
			return $payment;
		}

		$pago_id  = $payment['pago_id'];
		$order_id = (string) get_post_meta( $pago_id, '_convoca_order_id', true );

		// 2. Charge the stored card via Redsys REST.
		$charge = Redsys_Client::charge_token(
			array(
				'order_id'     => $order_id,
				'amount_cents' => $amount_cents,
				'product_desc' => $product_desc,
				'merchant_id'  => $token,
			)
		);

		if ( is_wp_error( $charge ) ) {
			\Convoca\Core\Logger::warning(
				sprintf( 'Cargo automático por token fallido (miembro #%d, pago #%d): %s', $member_id, $pago_id, $charge->get_error_message() ),
				'Gateway/Recurring',
				$pago_id
			);
			return $charge;
		}

		// 3. Apply the charge result (same semantics as a Redsys notification).
		self::aplicar_cargo( $pago_id, $order_id, $charge );

		return array(
			'pago_id'     => $pago_id,
			'payment_url' => $payment['payment_url'],
			'status'      => $charge['approved'] ? 'paid' : 'failed',
			'response'    => $charge['response'],
		);
	}

	/**
	 * Apply an approved/failed REST charge to a payment post, firing the same
	 * hooks a Redsys notification would (so Members' Payment_Listener reacts).
	 *
	 * @param int    $pago_id  Payment ID.
	 * @param string $order_id Redsys order ID.
	 * @param array<string, mixed> $charge   Result of Redsys_Client::charge_token().
	 * @return true
	 */
	private static function aplicar_cargo( int $pago_id, string $order_id, array $charge ): true {
		$is_approved = ! empty( $charge['approved'] );
		$new_status  = $is_approved ? 'paid' : 'failed';
		$response    = (string) ( $charge['response'] ?? '9999' );
		$auth_code   = (string) ( $charge['auth_code'] ?? '' );
		$decoded     = $charge['decoded'] ?? array();

		// Idempotence: if already paid, do not re-fire hooks.
		if ( get_post_meta( $pago_id, '_convoca_status', true ) === 'paid' ) {
			return true;
		}

		update_post_meta( $pago_id, '_convoca_status', $new_status );
		update_post_meta( $pago_id, '_convoca_redsys_response', $response );
		update_post_meta( $pago_id, '_convoca_redsys_auth_code', $auth_code );
		update_post_meta( $pago_id, '_convoca_redsys_full_log', wp_json_encode( $decoded ) );

		if ( ! empty( $decoded['Ds_Merchant_Identifier'] ) ) {
			update_post_meta( $pago_id, '_convoca_redsys_merchant_id', sanitize_text_field( $decoded['Ds_Merchant_Identifier'] ) );
		} elseif ( ! empty( $decoded['Ds_MerchantIdentifier'] ) ) {
			update_post_meta( $pago_id, '_convoca_redsys_merchant_id', sanitize_text_field( $decoded['Ds_MerchantIdentifier'] ) );
		}

		if ( $is_approved ) {
			update_post_meta( $pago_id, '_convoca_paid_at', current_time( 'mysql' ) );

			$meta = CPT_Pago::get_meta( $pago_id );
			\Convoca\Core\Utils::do_action( 'convoca_gateway_payment_completed', 'convoca_payment_completed', $pago_id, $meta['origin'], (int) $meta['origin_id'], $meta );
			\Convoca\Core\Logger::info( "Cargo automático por token aprobado (Order $order_id).", 'Gateway/Recurring', $pago_id );
		} else {
			\Convoca\Core\Utils::do_action( 'convoca_gateway_payment_failed', 'convoca_payment_failed', $pago_id, $response );
			\Convoca\Core\Logger::warning( "Cargo automático por token rechazado (Order $order_id): código $response.", 'Gateway/Recurring', $pago_id );
		}

		return true;
	}

	/**
	 * Create a payment and return the URL to the payment page.
	 *
	 * @param array<string, mixed> $data {
	 *     @type int    $amount_cents   Amount in cents. (> 0)
	 *     @type string $method         tarjeta | bizum (optional, user chooses).
	 *     @type string $origin         enroll | members.
	 *     @type int    $origin_id      Post ID of inscription/miembro.
	 *     @type string $product_desc   Bank statement description (max 125).
	 *     @type bool   $tokenize       Whether to request card tokenization (for recurring).
	 * }
	 * @return array{pago_id: int, payment_url: string}|\WP_Error
	 */
	public static function crear_pago( array $data ): array|\WP_Error {
		// Validation.
		$amount = (int) ( $data['amount_cents'] ?? 0 );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_amount', 'El importe debe ser mayor que cero.' );
		}

		$origin = $data['origin'] ?? '';
		/**
		 * Orígenes de pago admitidos.
		 *
		 * La lista estaba cerrada a `enroll` y `members`, así que cualquier módulo nuevo que quisiera
		 * cobrar (por ejemplo `courses`) recibía `invalid_origin` sin más salida que tocar este
		 * fichero. El filtro deja añadir orígenes desde el módulo que los genera, sin acoplar el
		 * gateway a una lista que crece.
		 *
		 * @param array<int, string> $origins Orígenes admitidos.
		 */
		$origins = (array) apply_filters( 'convoca_gateway_valid_origins', array( 'enroll', 'members', 'courses' ) );
		if ( ! in_array( $origin, $origins, true ) ) {
			return new \WP_Error( 'invalid_origin', 'Origen de pago no válido.' );
		}

		$origin_id = (int) ( $data['origin_id'] ?? 0 );
		if ( ! $origin_id || ! get_post( $origin_id ) ) {
			return new \WP_Error( 'invalid_origin_id', 'ID de origen no válido.' );
		}

		if ( isset( $data['product_desc'] ) && strlen( $data['product_desc'] ) > 125 ) {
			$data['product_desc'] = substr( $data['product_desc'], 0, 122 ) . '...';
		}

		$pago_id = CPT_Pago::create( $data );
		if ( is_wp_error( $pago_id ) ) {
			return $pago_id;
		}

		if ( ! empty( $data['tokenize'] ) ) {
			update_post_meta( $pago_id, '_convoca_tokenize', '1' );
		}

		// El correo de contacto sirve para el recibo y para el aviso de caducidad: si
		// solo viene uno, se usa para las dos cosas.
		if ( '' === (string) get_post_meta( $pago_id, '_convoca_recipient_email', true )
			&& '' !== (string) get_post_meta( $pago_id, '_convoca_payer_email', true ) ) {
			update_post_meta( $pago_id, '_convoca_recipient_email', (string) get_post_meta( $pago_id, '_convoca_payer_email', true ) );
		}

		// URL con token y caducidad configurable (ajuste `link_expiry_days`, 7 días por
		// defecto) en lugar del esquema antiguo de 24 horas. Sigue caducando, y deja de
		// servir en cuanto el pago se completa.
		$expires_ts = isset( $data['expires_ts'] )
			? (int) $data['expires_ts']
			: Link_Expiry::compute_expiry_timestamp( Link_Expiry::default_expiry_days() );

		$token = CPT_Pago::generate_link_token( $pago_id, $expires_ts );
		update_post_meta( $pago_id, '_convoca_link_key', $token );
		update_post_meta( $pago_id, '_convoca_expires_at', $expires_ts );

		$payment_url = self::url_de_pago( $pago_id, $token, $expires_ts );

		// Si el pago nace con un método YA elegido —lo normal en el alta de socio o de inscripción—, el
		// enlace se lo lleva puesto. Sin esto la página de pago caía al selector genérico: quien había
		// elegido transferencia se encontraba tarjeta y Bizum, y con el IBAN sin configurar no veía ni la
		// transferencia ni una explicación (medido: gateway#9). El método «any» de un enlace de donación
		// se deja sin tocar: ahí elegir es cosa de quien paga.
		$method = sanitize_text_field( (string) ( $data['method'] ?? '' ) );
		if ( in_array( $method, array( 'tarjeta', 'bizum', 'transferencia' ), true ) ) {
			$payment_url = add_query_arg( 'convoca_gateway_method', $method, $payment_url );
		}

		return array(
			'pago_id'     => $pago_id,
			'payment_url' => $payment_url,
		);
	}

	/**
	 * Get the URL of the payment page.
	 * Looks for a page with [convoca_pago] shortcode, or falls back to a default.
	 */
	public static function url_de_la_pagina(): string {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$page_id  = (int) ( $settings['payment_page_id'] ?? 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( false === $url ) {
				$url = home_url( '/pago/' );
			}
		} else {
			$url = home_url( '/pago/' );
		}

		// Force HTTPS if available.
		if ( is_ssl() ) {
			$url = set_url_scheme( $url, 'https' );
		}

		return $url;
	}

	/**
	 * Construye la URL firmada de un pago que YA existe (issue convoca-core#16).
	 *
	 * Antes se llamaba `get_payment_link()`, el mismo nombre que `Core\Utils::get_payment_link()`, y eso hacía
	 * creer que las dos hacían lo mismo: aquella **crea** el pago y devuelve un array o un error, esta
	 * construye la URL de uno existente. Quien llamaba a una creyendo llamar a la otra no lo descubría hasta
	 * que fallaba. El nombre nuevo dice lo que hace.
	 *
	 * @param int    $pago_id    The payment post ID.
	 * @param string $token      Optional token (for link payment).
	 * @param int    $expires_ts Optional expiration timestamp.
	 * @return string The signed URL.
	 */
	public static function url_de_pago( int $pago_id, string $token = '', ?int $expires_ts = null ): string {
		$base_url = self::url_de_la_pagina();

		$args = array( 'convoca_gateway_pago' => $pago_id );

		if ( $token ) {
			$args['convoca_gateway_key'] = $token;
			// No longer exposing expiration in URL for security/clarity.
		} else {
			$ts                          = time();
			$args['convoca_gateway_t']   = $ts;
			$args['convoca_gateway_key'] = hash_hmac( 'sha256', $pago_id . '|' . $ts . '_convoca_payment', self::salt() );
		}

		return add_query_arg( $args, $base_url );
	}

	/**
	 * Alias de compatibilidad de `build_payment_url()` (issue convoca-core#16).
	 *
	 * El nombre viejo queda porque es API que otros plugins del ecosistema llaman desde antes: se cambia el
	 * nombre, no el comportamiento, y **la URL que genera es la misma byte a byte** (la migración está
	 * verificada contra la URL firmada de un pago real de la demo, antes y después del cambio).
	 *
	 * @deprecated 2.13.13 Usa `build_payment_url()`.
	 *
	 * @param int    $pago_id    The payment post ID.
	 * @param string $token      Optional token (for link payment).
	 * @param int    $expires_ts Optional expiration timestamp.
	 * @return string The signed URL.
	 */
	public static function enlace_de_pago( int $pago_id, string $token = '', ?int $expires_ts = null ): string {
		return self::url_de_pago( $pago_id, $token, $expires_ts );
	}

	/**
	 * Emite el registro de pago de un uso del enlace.
	 *
	 * @param int    $enlace_id   ID del enlace.
	 * @param array<string, mixed> $meta        Metadatos del enlace.
	 * @param string $method      Método elegido.
	 * @param string $payer_email Email de quien paga (recibo), si lo dejó.
	 * @return int|\WP_Error ID del cobro emitido.
	 */
	public static function emitir_del_enlace( int $enlace_id, array $meta, string $method, string $payer_email = '' ): int|\WP_Error {
		$cobro = CPT_Pago::create_link_payment(
			array(
				'amount'      => ( (int) ( $meta['amount_cents'] ?? 0 ) ) / 100,
				'concepto'    => (string) get_post_meta( $enlace_id, '_convoca_product_desc', true ),
				'method'      => $method,
				'expires_at'  => 'never',
				// Origen propio: el cobro aparece en «Todos los Pagos» y el enlace sigue
				// siendo enlace (el listado de enlaces filtra por link_payment).
				'origin'      => 'enlace',
				'origin_id'   => $enlace_id,
				'payer_email' => $payer_email,
			)
		);

		if ( is_wp_error( $cobro ) ) {
			return $cobro;
		}

		$params = get_post_meta( $enlace_id, '_convoca_params', true );
		if ( is_array( $params ) && ! empty( $params ) ) {
			update_post_meta( $cobro, '_convoca_params', $params );
		}

		\Convoca\Core\Logger::info(
			sprintf( 'Cobro emitido por el enlace #%d: pago #%d por %s (%s).', $enlace_id, $cobro, CPT_Pago::format_amount( (int) ( $meta['amount_cents'] ?? 0 ) ), $method ),
			'Gateway/LinkPayment',
			$cobro
		);

		return (int) $cobro;
	}
	/**
	 * La sal de firma de la pasarela (no es la de Core: otra opción, otro secreto).
	 */
	public static function salt(): string {
		$salt = get_option( 'convoca_gateway_persistent_salt' );
		if ( ! $salt ) {
			$salt = wp_generate_password( 64, true, true );
			update_option( 'convoca_gateway_persistent_salt', $salt, false );
		}
		return $salt;
	}
}
