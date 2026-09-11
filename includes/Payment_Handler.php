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
 * Payment handler: creates payments, renders payment page, processes Redsys notifications.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payment_Handler {

	/**
	 * Get a persistent salt for hashing payment links.
	 * This avoids links breaking when AUTH_SALT is changed in wp-config.php.
	 */
	private static function get_persistent_salt(): string {
		$salt = get_option( 'convoca_gateway_persistent_salt' );
		if ( ! $salt ) {
			$salt = wp_generate_password( 64, true, true );
			update_option( 'convoca_gateway_persistent_salt', $salt, false );
		}
		return $salt;
	}


	private string $upload_error = '';
	private bool $upload_success = false;

	public function __construct() {
		// Assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Payment page shortcode.
		add_shortcode( 'convoca_pago', array( $this, 'render_payment_page' ) );

		// Return pages.
		add_shortcode( 'convoca_pago_ok', array( $this, 'render_ok_page' ) );
		add_shortcode( 'convoca_pago_ko', array( $this, 'render_ko_page' ) );
	}

	public function register_assets(): void {
		wp_register_script(
			'conv-redsys',
			CONVOCA_GATEWAY_URL . 'assets/js/redsys.js',
			array(),
			CONVOCA_GATEWAY_VERSION,
			true
		);
	}

	/* ── Public API ────────────────────────────── */

	/**
	 * Get the stored recurring token for a member.
	 */
	public static function get_member_token( int $member_id ): string {
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
	public static function auto_renew_charge( int $member_id, int $amount_cents, string $product_desc ): array|\WP_Error {
		$token = self::get_member_token( $member_id );
		if ( empty( $token ) ) {
			return new \WP_Error( 'no_token', 'El socio no tiene token de tarjeta almacenado.' );
		}

		// 1. Create the payment (same as manual renewal).
		$payment = self::create_payment(
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
		self::apply_charge_result( $pago_id, $order_id, $charge );

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
	 * @param array  $charge   Result of Redsys_Client::charge_token().
	 * @return true
	 */
	private static function apply_charge_result( int $pago_id, string $order_id, array $charge ): true {
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
	 * @param array $data {
	 *     @type int    $amount_cents   Amount in cents. (> 0)
	 *     @type string $method         tarjeta | bizum (optional, user chooses).
	 *     @type string $origin         enroll | members.
	 *     @type int    $origin_id      Post ID of inscription/miembro.
	 *     @type string $product_desc   Bank statement description (max 125).
	 *     @type bool   $tokenize       Whether to request card tokenization (for recurring).
	 * }
	 * @return array{pago_id: int, payment_url: string}|\WP_Error
	 */
	public static function create_payment( array $data ): array|\WP_Error {
		// Validation.
		$amount = (int) ( $data['amount_cents'] ?? 0 );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_amount', 'El importe debe ser mayor que cero.' );
		}

		$origin = $data['origin'] ?? '';
		if ( ! in_array( $origin, array( 'enroll', 'members' ), true ) ) {
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

		$payment_url = self::get_payment_link( $pago_id, $token, $expires_ts );

		return array(
			'pago_id'     => $pago_id,
			'payment_url' => $payment_url,
		);
	}

	/**
	 * Get the URL of the payment page.
	 * Looks for a page with [convoca_pago] shortcode, or falls back to a default.
	 */
	public static function get_payment_page_url(): string {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$page_id  = (int) ( $settings['payment_page_id'] ?? 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
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
	 * Generate a signed payment link for an existing payment.
	 *
	 * @param int    $pago_id    The payment post ID.
	 * @param string $token      Optional token (for link payment).
	 * @param int    $expires_ts Optional expiration timestamp.
	 * @return string The signed URL.
	 */
	public static function get_payment_link( int $pago_id, string $token = '', ?int $expires_ts = null ): string {
		$base_url = self::get_payment_page_url();

		$args = array( 'convoca_gateway_pago' => $pago_id );

		if ( $token ) {
			$args['convoca_gateway_key'] = $token;
			// No longer exposing expiration in URL for security/clarity.
		} else {
			$ts                          = time();
			$args['convoca_gateway_t']   = $ts;
			$args['convoca_gateway_key'] = hash_hmac( 'sha256', $pago_id . '|' . $ts . '_convoca_payment', self::get_persistent_salt() );
		}

		return add_query_arg( $args, $base_url );
	}

	/* ── Payment page rendering ────────────────── */

	/**
	 * Render the payment method selection + Redsys redirect.
	 */
	public function render_payment_page( $atts ): string {
		// Regenerate an expired/failed link keeping the same payment (D21c/D22c).
		if ( isset( $_POST['convoca_gateway_regenerate'] ) && check_admin_referer( 'convoca_gateway_regenerate_action', 'convoca_gateway_regenerate_nonce' ) ) {
			return $this->handle_regenerate_link();
		}

		// Handle manual form submission first.
		if ( isset( $_POST['convoca_gateway_manual_payment'] ) && check_admin_referer( 'convoca_gateway_manual_payment_action', 'convoca_gateway_manual_nonce' ) ) {
			return $this->handle_manual_payment_submission();
		}

		$pago_id = (int) ( wp_unslash( $_GET['convoca_gateway_pago'] ?? 0 ) );
		$key     = sanitize_text_field( wp_unslash( $_GET['convoca_gateway_key'] ?? '' ) );

		// Handle proof of payment upload.
		if ( isset( $_POST['convoca_gateway_upload_proof'] ) && check_admin_referer( 'convoca_gateway_proof_upload_action', 'convoca_gateway_proof_nonce' ) ) {
			$upload_result = $this->handle_proof_upload( $pago_id );
			if ( is_wp_error( $upload_result ) ) {
				$this->upload_error = $upload_result->get_error_message();
			} else {
				$this->upload_success = true;
			}
		}

		if ( ! $pago_id || ! $key ) {
			return $this->render_manual_form();
		}

		$expires_param = wp_unslash( $_GET['convoca_gateway_expires'] ?? null );
		$legacy_ts     = (int) ( wp_unslash( $_GET['convoca_gateway_t'] ?? 0 ) );

		// 1. Check if it is a legacy link (uses conv_t).
		if ( $legacy_ts > 0 ) {
			return $this->render_legacy_payment_page( $pago_id, $legacy_ts, $key );
		}

		// 2. Otherwise, treat as a new link payment (link generator).
		// The expiration is optional in the URL (it's 0 for 'never'),.
		// we'll use the param if present or 0 otherwise.
		return $this->render_link_payment_page( $pago_id, $key, (int) ( $expires_param ?? 0 ) );
	}

	/**
	 * Render payment page for link-generated payments (new system).
	 */
	private function render_link_payment_page( int $pago_id, string $key, int $expires_ts ): string {
		$post = get_post( $pago_id );
		if ( ! $post || $post->post_type !== 'pago' ) {
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html__( 'Pago no encontrado.', 'convoca-gateway' ) . '</div>';
		}

		$stored_key = get_post_meta( $pago_id, '_convoca_link_key', true );
		if ( ! $stored_key || ! hash_equals( $stored_key, $key ) ) {
			\Convoca\Core\Logger::warning( "Intento de acceso con token inválido. Pago ID: $pago_id", 'Gateway/LinkPayment', $pago_id );
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html__( 'Enlace de pago inválido.', 'convoca-gateway' ) . '</div>';
		}

		$stored_expires = get_post_meta( $pago_id, '_convoca_expires_at', true );
		if ( $stored_expires && $stored_expires < time() ) {
			return $this->render_expired_notice( $pago_id );
		}

		// Cobrado: el enlace de una cuota o una inscripción se cierra al confirmarse
		// su pago y ya no admite más. Los enlaces de donativo y las plantillas no se
		// cierran así (siguen vivos hasta su fecha, si la tienen).
		if ( Link_Expiry::is_closed( $pago_id ) ) {
			return $this->render_collected_notice( $pago_id );
		}

		$meta = CPT_Pago::get_meta( $pago_id );

		// Enlace de donativo: es reutilizable (un pago no lo consume) y el importe lo
		// elige quien aporta, así que se sirve su propio formulario. Cada aportación
		// crea un pago independiente, de modo que el recibo y la conciliación son por
		// donativo y no por enlace.
		if ( ! empty( $meta['open_amount'] ) ) {
			// Se despacha por el nonce del propio formulario: el de donativo es el
			// único que lo trae, y el handler lo verifica antes de nada.
			$aportando = isset( $_POST['convoca_donation_nonce'] );

			return $aportando
				? $this->handle_donation_submission( $pago_id )
				: $this->render_donation_form( $pago_id, $meta );
		}

		if ( $meta['status'] === 'paid' ) {
			$paid_at = ! empty( $meta['paid_at'] ) ? wp_date( 'd/m/Y H:i', strtotime( $meta['paid_at'] ) ) : '';
			return '<div class="convoca-alert convoca-alert--success">✅ Este pago ya ha sido completado' . ( $paid_at ? ' el ' . $paid_at : '' ) . '.</div>';
		}

		$product_desc     = get_post_meta( $pago_id, '_convoca_product_desc', true );
		$amount_cents     = (int) get_post_meta( $pago_id, '_convoca_amount_cents', true );
		$suggested_method = get_post_meta( $pago_id, '_convoca_method', true );
		$recipient_email  = get_post_meta( $pago_id, '_convoca_recipient_email', true );
		$params           = get_post_meta( $pago_id, '_convoca_params', true );
		$params           = is_array( $params ) ? $params : array();

		// Un enlace es una plantilla, no un cobro: al usarlo emite un registro de pago
		// propio y sigue siendo enlace para el siguiente uso. Antes el enlace se
		// convertía él mismo en el cobro y quedaba gastado al primer pago.
		if ( 'link_payment' === ( $meta['origin'] ?? '' ) ) {
			return $this->handle_link_use( $pago_id, $meta, $product_desc, $amount_cents, $suggested_method, $recipient_email, $params );
		}

		// El campo «Email de notificación» del formulario no se leía en ningún sitio: se
		// guarda como email de quien paga, que es lo que usa el recibo.
		$payer_email = sanitize_email( wp_unslash( $_GET['convoca_gateway_email'] ?? '' ) );
		if ( '' !== $payer_email && filter_var( $payer_email, FILTER_VALIDATE_EMAIL ) ) {
			update_post_meta( $pago_id, '_convoca_payer_email', $payer_email );
		}

		$selected_method = sanitize_text_field( wp_unslash( $_GET['convoca_gateway_method'] ?? '' ) );
		if ( $selected_method && in_array( $selected_method, array( 'tarjeta', 'bizum' ), true ) ) {
			update_post_meta( $pago_id, '_convoca_method', $selected_method );
			return $this->render_redsys_redirect( $pago_id, $meta, $selected_method );
		}

		if ( $selected_method === 'transferencia' ) {
			update_post_meta( $pago_id, '_convoca_method', 'transferencia' );
			return $this->render_transfer_instructions( $pago_id, $meta );
		}

		// No es plantilla (un pago de socio, de inscripción o una aportación): los
		// métodos son enlaces que llevan el método en la URL, como siempre.
		return $this->render_link_form( $pago_id, $meta, $product_desc, $amount_cents, $suggested_method, $recipient_email, $params, false );
	}

	/**
	 * Uso de un enlace: emite el cobro y lleva al pago.
	 *
	 * El enlace no se toca (sigue con su estado, su caducidad y su token), así que se
	 * puede usar tantas veces como haga falta: cada uso deja su propio registro en
	 * «Todos los Pagos». El cobro se emite al enviar el formulario, no al abrir la
	 * página: una visita (o un rastreador) no deja registros sueltos.
	 *
	 * @param int    $enlace_id        ID del enlace (plantilla).
	 * @param array  $meta             Metadatos del enlace.
	 * @param string $product_desc     Concepto del enlace.
	 * @param int    $amount_cents     Importe del enlace en céntimos.
	 * @param string $suggested_method Método sugerido.
	 * @param string $recipient_email  Email de notificación guardado en el enlace.
	 * @param array  $params           Datos adicionales del enlace.
	 */
	private function handle_link_use( int $enlace_id, array $meta, string $product_desc, int $amount_cents, string $suggested_method, string $recipient_email, array $params ): string {
		if ( ! isset( $_POST['convoca_link_nonce'] ) ) {
			// Paso 1: resumen, correo y métodos (botones que envían el formulario).
			return $this->render_link_form( $enlace_id, $meta, $product_desc, $amount_cents, $suggested_method, $recipient_email, $params );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['convoca_link_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'convoca_link_' . $enlace_id ) ) {
			\Convoca\Core\Logger::warning( "Uso de enlace con nonce inválido. Enlace ID: $enlace_id", 'Gateway/LinkPayment', $enlace_id );

			return $this->render_payment_alert( __( 'La sesión ha caducado. Vuelve a cargar el enlace.', 'convoca-gateway' ) );
		}

		$method = sanitize_text_field( wp_unslash( $_POST['convoca_gateway_method'] ?? '' ) );
		if ( ! in_array( $method, array( 'tarjeta', 'bizum', 'transferencia' ), true ) ) {
			return $this->render_payment_alert( __( 'Elige un método de pago.', 'convoca-gateway' ) );
		}

		$payer_email = sanitize_email( wp_unslash( $_POST['convoca_gateway_email'] ?? $recipient_email ) );

		$cobro = $this->emitir_cobro_del_enlace( $enlace_id, $meta, $method, $payer_email );
		if ( is_wp_error( $cobro ) ) {
			return $this->render_payment_alert( $cobro->get_error_message() );
		}

		$meta_cobro = CPT_Pago::get_meta( $cobro );

		return ( 'transferencia' === $method )
			? $this->render_transfer_instructions( $cobro, $meta_cobro )
			: $this->render_redsys_redirect( $cobro, $meta_cobro, $method );
	}

	/**
	 * Emite el registro de pago de un uso del enlace.
	 *
	 * @param int    $enlace_id   ID del enlace.
	 * @param array  $meta        Metadatos del enlace.
	 * @param string $method      Método elegido.
	 * @param string $payer_email Email de quien paga (recibo), si lo dejó.
	 * @return int|\WP_Error ID del cobro emitido.
	 */
	private function emitir_cobro_del_enlace( int $enlace_id, array $meta, string $method, string $payer_email = '' ): int|\WP_Error {
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
	 * Aviso sencillo dentro de la página de pago.
	 */
	private function render_payment_alert( string $mensaje ): string {
		return $this->compact_html(
			'<div class="conv-payment-wrapper"><div class="convoca-alert convoca-alert--danger">' . esc_html( $mensaje ) . '</div></div>'
		);
	}

	/**
	 * Render payment form for link-generated payments.
	 */
	private function render_link_form( int $pago_id, array $meta, string $product_desc, int $amount_cents, string $suggested_method, string $recipient_email, array $params, bool $es_plantilla = true ): string {
		$amount_display = CPT_Pago::format_amount( $amount_cents );
		$base_url       = self::get_payment_link( $pago_id, (string) get_post_meta( $pago_id, '_convoca_link_key', true ), (int) get_post_meta( $pago_id, '_convoca_expires_at', true ) );

		$settings         = get_option( 'convoca_gateway_settings', array() );
		$transfer_enabled = ! empty( $settings['iban'] );
		$bizum_enabled    = ! empty( Redsys_Client::merchant_code() );

		// Suggested badge: honor the payment's own method only if that method
		// is actually available (e.g. don't suggest transferencia without IBAN).
		$suggested = '';
		if ( $suggested_method === 'tarjeta' ) {
			$suggested = 'tarjeta';
		} elseif ( $suggested_method === 'bizum' && $bizum_enabled ) {
			$suggested = 'bizum';
		} elseif ( $suggested_method === 'transferencia' && $transfer_enabled ) {
			$suggested = 'transferencia';
		}

		ob_start();
		?>
		<div class="conv-payment-wrapper convoca-form" role="region" aria-label="Formulario de pago">
			<div class="conv-payment-summary">
				<h3>Resumen del pago</h3>
				<div class="conv-amount"><?php echo esc_html( $amount_display ); ?></div>
				<?php if ( $product_desc ) : ?>
					<p class="conv-desc"><?php echo esc_html( $product_desc ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $params ) ) : ?>
			<div class="conv-params">
				<h4>Datos adicionales</h4>
				<ul class="conv-params-list">
					<?php foreach ( $params as $k => $v ) : ?>
					<li><span class="conv-param-key"><?php echo esc_html( $k ); ?>:</span> <span class="conv-param-value"><?php echo esc_html( $v ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<form method="post" action="" class="conv-link-form">
				<?php if ( $es_plantilla ) : ?>
					<?php wp_nonce_field( 'convoca_link_' . $pago_id, 'convoca_link_nonce' ); ?>
				<?php endif; ?>

				<div class="conv-email-field">
					<label for="convoca_gateway_email"><?php esc_html_e( 'Email de notificación', 'convoca-gateway' ); ?></label>
					<input type="email" name="convoca_gateway_email" id="convoca_gateway_email" value="<?php echo esc_attr( $recipient_email ); ?>" class="regular-text">
					<p class="conv-help"><?php esc_html_e( 'Si lo indicas, te enviamos el recibo del pago a este correo.', 'convoca-gateway' ); ?></p>
				</div>

				<?php
				// Tarjetas compartidas: tarjeta y Bizum en paralelo, transferencia a lo ancho.
				// En un enlace son botones que envían el formulario: el cobro se emite al
				// pulsar, no al abrir la página (una visita no deja registros de pago).
				echo $this->render_method_picker( $base_url, __( 'Selecciona un método de pago', 'convoca-gateway' ), array(), $suggested, $es_plantilla ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Marcado propio, ya escapado.
				?>
			</form>
		</div>
		<style>
			.conv-payment-wrapper {
				max-width: 600px;
				margin: 2rem auto;
				background: #fff;
				border-radius: 16px;
				box-shadow: 0 10px 25px rgba(0,0,0,0.05);
				padding: 2.5rem 2rem;
				border: 1px solid #f0f0f0;
			}
			.conv-payment-summary {
				text-align: center;
				margin-bottom: 2rem;
				padding-bottom: 1.5rem;
				border-bottom: 1px solid #f0f0f0;
			}
			.conv-amount {
				font-size: 2.5rem;
				font-weight: 800;
				color: var(--wp--preset--color--naranja, #ff8700);
				margin: 0.5rem 0;
			}
			.conv-desc {
				color: #666;
				font-size: 1.1rem;
			}
			.conv-params { 
				margin: 1.5rem 0; 
				padding: 1.25rem; 
				background: #f8f9fa; 
				border-radius: 12px; 
				border: 1px solid #eee;
			}
			.conv-params h4 { margin-top: 0; font-size: 1rem; color: #333; }
			.conv-params-list { margin: 0; padding-left: 1.25rem; list-style-type: square; color: #555; }
			.conv-param-key { font-weight: 700; color: #333; }
			
			.conv-email-field {
				margin-bottom: 2rem;
			}
			.conv-email-field label {
				display: block;
				font-weight: 700;
				margin-bottom: 0.5rem;
				color: #333;
				text-align: left;
			}
			.conv-email-field input {
				width: 100%;
				padding: 12px 16px;
				border: 2px solid #e0e0e0;
				border-radius: 8px;
				font-size: 1rem;
			}
		</style>
		<?php
		return $this->compact_html( (string) ob_get_clean() );
	}

	/** Evita repetir el mismo bloque de CSS en una página. */
	private static bool $methods_css_printed = false;

	/*
	 * ── Selector de método de pago (compartido) ────────────────────────────
	 *
	 * Un único sitio define qué métodos hay y cómo se pintan, para que el
	 * enlace de pago y el de donativo no se separen con el tiempo.
	 */

	/**
	 * Métodos de pago disponibles según la configuración del sitio.
	 *
	 * Tarjeta y Bizum dependen de Redsys; la transferencia, del IBAN.
	 *
	 * @return array<string, array{icon: string, label: string, desc: string, wide: bool}>
	 */
	private function enabled_methods(): array {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$redsys   = '' !== Redsys_Client::merchant_code() && '' !== Redsys_Client::secret_key();
		$transfer = ! empty( $settings['iban'] );

		$methods = array();

		if ( $redsys ) {
			$methods['tarjeta'] = array(
				'icon'  => '💳',
				'label' => __( 'Tarjeta', 'convoca-gateway' ),
				'desc'  => __( 'Visa, Mastercard, etc.', 'convoca-gateway' ),
				'wide'  => false,
			);
			$methods['bizum']   = array(
				'icon'  => '📱',
				'label' => __( 'Bizum', 'convoca-gateway' ),
				'desc'  => __( 'Pago instantáneo con tu móvil', 'convoca-gateway' ),
				'wide'  => false,
			);
		}

		if ( $transfer ) {
			$methods['transferencia'] = array(
				'icon'  => '🍀',
				'label' => __( 'Transferencia', 'convoca-gateway' ),
				'desc'  => __( 'Ingresa desde tu banco', 'convoca-gateway' ),
				'wide'  => true,
			);
		}

		return $methods;
	}

	/**
	 * Método elegido en la petición actual, ya validado contra los disponibles.
	 *
	 * Llega por el enlace de la tarjeta (GET) en el paso 1 y por el campo
	 * oculto del formulario (POST) en el paso 2. Un slug desconocido o un
	 * método no disponible se tratan como "sin elegir".
	 */
	private function requested_method(): string {
		$raw = $_GET['convoca_gateway_method'] ?? ( $_POST['convoca_gateway_method'] ?? '' );
		$raw = is_string( $raw ) ? wp_unslash( $raw ) : '';

		if ( '' === $raw ) {
			return '';
		}

		return array_key_exists( $raw, $this->enabled_methods() ) ? $raw : '';
	}

	/**
	 * Tarjetas grandes de método de pago: tarjeta y Bizum en paralelo,
	 * transferencia a lo ancho debajo.
	 *
	 * @param string $base_url  URL del paso 1 (sin convoca_gateway_method).
	 * @param array  $args      Argumentos extra que añadir a cada enlace.
	 * @param string $suggested Slug a destacar con la etiqueta «Recomendado».
	 */
	private function render_method_cards( string $base_url, array $args = array(), string $suggested = '', bool $enviar_formulario = false ): string {
		$methods = $this->enabled_methods();

		if ( empty( $methods ) ) {
			return '<div class="convoca-alert convoca-alert--warning">' .
				esc_html__( 'No hay ningún método de pago disponible. Contacta con la entidad.', 'convoca-gateway' ) .
				'</div>' . $this->methods_css();
		}

		ob_start();
		?>
		<div class="conv-methods">
			<?php foreach ( $methods as $slug => $method ) : ?>
				<?php
				$url   = add_query_arg( array_merge( array( 'convoca_gateway_method' => $slug ), $args ), $base_url );
				$class = 'conv-method conv-method-card conv-method--' . $slug;
				if ( $method['wide'] ) {
					$class .= ' conv-method-card--wide';
				}
				if ( $slug === $suggested ) {
					$class .= ' conv-method--suggested';
				}
				?>
				<?php if ( $enviar_formulario ) : ?>
					<button type="submit" name="convoca_gateway_method" value="<?php echo esc_attr( $slug ); ?>" class="<?php echo esc_attr( $class ); ?>">
						<span class="conv-method-icon"><?php echo esc_html( $method['icon'] ); ?></span>
						<span class="conv-method-text">
							<span class="conv-method-label"><?php echo esc_html( $method['label'] ); ?></span>
							<span class="conv-method-desc"><?php echo esc_html( $method['desc'] ); ?></span>
						</span>
						<?php if ( $slug === $suggested ) : ?>
							<span class="conv-method-badge"><?php esc_html_e( 'Recomendado', 'convoca-gateway' ); ?></span>
						<?php endif; ?>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
						<span class="conv-method-icon"><?php echo esc_html( $method['icon'] ); ?></span>
						<span class="conv-method-text">
							<span class="conv-method-label"><?php echo esc_html( $method['label'] ); ?></span>
							<span class="conv-method-desc"><?php echo esc_html( $method['desc'] ); ?></span>
						</span>
						<?php if ( $slug === $suggested ) : ?>
							<span class="conv-method-badge"><?php esc_html_e( 'Recomendado', 'convoca-gateway' ); ?></span>
						<?php endif; ?>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
		echo $this->methods_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS estático del propio plugin.
		return $this->compact_html( (string) ob_get_clean() );
	}

	/**
	 * Selector completo: título + tarjetas.
	 *
	 * @param string $base_url  URL del paso 1 (sin convoca_gateway_method).
	 * @param string $heading   Título del selector.
	 * @param array  $args      Argumentos extra que añadir a cada enlace.
	 * @param string $suggested Slug a destacar, si procede.
	 */
	private function render_method_picker( string $base_url, string $heading, array $args = array(), string $suggested = '', bool $enviar_formulario = false ): string {
		return $this->compact_html(
			'<h4 class="conv-methods-heading">' . esc_html( $heading ) . '</h4>' .
			$this->render_method_cards( $base_url, $args, $suggested, $enviar_formulario )
		);
	}

	/**
	 * Bloque del método ya elegido, con enlace para cambiarlo (paso 2).
	 *
	 * @param string $method   Slug elegido.
	 * @param string $base_url URL del paso 1, para volver a elegir.
	 */
	private function render_chosen_method( string $method, string $base_url ): string {
		$methods = $this->enabled_methods();

		if ( ! isset( $methods[ $method ] ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="conv-chosen-method">
			<span class="conv-chosen-icon"><?php echo esc_html( $methods[ $method ]['icon'] ); ?></span>
			<span class="conv-chosen-label"><?php echo esc_html( $methods[ $method ]['label'] ); ?></span>
			<a class="conv-chosen-change" href="<?php echo esc_url( remove_query_arg( 'convoca_gateway_method', $base_url ) ); ?>">
				<?php esc_html_e( 'Cambiar método', 'convoca-gateway' ); ?>
			</a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Quita los saltos de línea entre etiquetas del HTML generado.
	 *
	 * WordPress pasa el contenido por wpautop: un salto entre dos etiquetas se
	 * convierte en <br> (o en <p>) y desmonta la rejilla de las tarjetas. Pasó
	 * en la página de pago de demo, que usa el editor clásico; en Lugg, con
	 * bloques, no se reproducía.
	 */
	private function compact_html( string $html ): string {
		return (string) preg_replace( '/>\s+</', '><', $html );
	}

	/**
	 * CSS de las tarjetas de método. Se imprime una sola vez por petición.
	 */
	private function methods_css(): string {
		if ( self::$methods_css_printed ) {
			return '';
		}

		self::$methods_css_printed = true;

		return '<style>
			.conv-methods {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 1.25rem;
				margin: 1rem 0 1.5rem;
			}
			.conv-method-card {
				display: flex;
				flex-direction: column;
				align-items: center;
				justify-content: center;
				padding: 1.5rem;
				border: 2px solid #eee;
				border-radius: 12px;
				text-decoration: none;
				color: #333 !important;
				background: #fafafa;
				transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
				position: relative;
			}
			.conv-method-card:hover,
			.conv-method-card:focus-visible {
				border-color: var(--wp--preset--color--naranja, #ff8700);
				background: #fff;
				transform: translateY(-4px);
				box-shadow: 0 8px 20px rgba(255, 135, 0, 0.12);
			}
			button.conv-method-card {
				width: 100%;
				font: inherit;
				font-family: inherit;
				text-align: center;
				cursor: pointer;
				appearance: none;
			}
			.conv-method-card--wide {
				grid-column: 1 / -1;
				flex-direction: row;
				gap: 1.25rem;
				padding: 1.75rem 1.5rem;
			}
			.conv-method-card--wide .conv-method-icon {
				font-size: 3rem;
				margin-bottom: 0;
			}
			.conv-method-card--wide .conv-method-label {
				font-size: 1.35rem;
			}
			.conv-method-card--wide .conv-method-text {
				align-items: flex-start;
			}
			.conv-method-text {
				display: flex;
				flex-direction: column;
				align-items: center;
			}
			.conv-method--suggested {
				border-color: var(--wp--preset--color--naranja, #ff8700);
				background: rgba(255, 135, 0, 0.03);
			}
			.conv-method-icon {
				font-size: 2.5rem;
				margin-bottom: 0.75rem;
				filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
			}
			.conv-method-label {
				font-weight: 700;
				font-size: 1.1rem;
				margin-bottom: 0.25rem;
			}
			.conv-method-desc {
				font-size: 0.85rem;
				color: #777;
				text-align: left;
				line-height: 1.4;
			}
			.conv-method-badge {
				position: absolute;
				top: -12px;
				background: var(--wp--preset--color--naranja, #ff8700);
				color: #fff;
				padding: 4px 12px;
				border-radius: 20px;
				font-size: 0.7rem;
				font-weight: 800;
				text-transform: uppercase;
				letter-spacing: 0.8px;
				box-shadow: 0 2px 8px rgba(255, 135, 0, 0.3);
			}
			.conv-methods-heading {
				margin-bottom: 0;
			}
			.conv-chosen-method {
				display: flex;
				align-items: center;
				gap: 0.6rem;
				margin: 0 0 1.5rem;
				padding: 0.9rem 1.1rem;
				border: 2px solid #eee;
				border-radius: 12px;
				background: #fafafa;
				text-align: left;
			}
			.conv-chosen-icon {
				font-size: 1.6rem;
				line-height: 1;
			}
			.conv-chosen-label {
				font-weight: 700;
			}
			.conv-chosen-change {
				margin-left: auto;
				font-size: 0.85rem;
				white-space: nowrap;
			}
			@media (max-width: 480px) {
				.conv-methods {
					grid-template-columns: 1fr;
				}
				.conv-method-card--wide {
					flex-direction: column;
					gap: 0;
				}
				.conv-method-card--wide .conv-method-icon {
					margin-bottom: 0.5rem;
				}
				.conv-method-card--wide .conv-method-text {
					align-items: center;
				}
				.conv-method-desc {
					text-align: center;
				}
			}
		</style>';
	}

	/**
	 * Formulario de donativo: importe libre, email opcional y método de pago.
	 *
	 * Se rellena en dos pantallas: primero el método (paso 1, enlaces) y luego
	 * el importe y el correo (paso 2, envío). Es reutilizable: cada envío crea
	 * un pago propio (ver handle_donation_submission), de modo que el enlace
	 * sirve para tantas aportaciones como quiera hacer la gente.
	 *
	 * @param int    $pago_id Enlace de donativo (registro plantilla).
	 * @param array  $meta    Metadatos del enlace.
	 * @param string $error   Mensaje de error a mostrar.
	 * @param string $amount  Importe tecleado, para repoblarlo si hubo error.
	 * @param string $email   Email tecleado, para repoblarlo si hubo error.
	 * @return string HTML.
	 */
	private function render_donation_form( int $pago_id, array $meta, string $error = '', string $amount = '', string $email = '' ): string {
		$concepto = get_post_meta( $pago_id, '_convoca_product_desc', true );
		$concepto = $concepto ?: __( 'Donativo', 'convoca-gateway' );

		$params = get_post_meta( $pago_id, '_convoca_params', true );
		$params = is_array( $params ) ? $params : array();

		$method  = $this->requested_method();
		$methods = $this->enabled_methods();
		$token   = (string) get_post_meta( $pago_id, '_convoca_link_key', true );
		$base    = $token ? self::get_payment_link( $pago_id, $token ) : remove_query_arg( 'convoca_gateway_method' );

		ob_start();
		?>
		<div class="conv-payment-wrapper convoca-form" role="region" aria-label="<?php esc_attr_e( 'Formulario de donativo', 'convoca-gateway' ); ?>">
			<div class="conv-payment-summary">
				<h3><?php echo esc_html( $concepto ); ?></h3>
			</div>

			<?php if ( '' !== $error ) : ?>
				<div class="convoca-alert convoca-alert--danger"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $params ) ) : ?>
			<div class="conv-params">
				<h4><?php esc_html_e( 'Datos adicionales', 'convoca-gateway' ); ?></h4>
				<ul class="conv-params-list">
					<?php foreach ( $params as $k => $v ) : ?>
					<li><span class="conv-param-key"><?php echo esc_html( $k ); ?>:</span> <span class="conv-param-value"><?php echo esc_html( $v ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<?php if ( '' === $method ) : ?>
				<?php
				// Paso 1: método de pago. El importe y el correo llegan después.
				echo $this->render_method_picker( $base, __( 'Selecciona un método de pago', 'convoca-gateway' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Marcado propio, ya escapado.
				?>
			<?php else : ?>
				<?php echo $this->render_chosen_method( $method, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Marcado propio, ya escapado. ?>

				<form method="post" action="" class="conv-link-form conv-donation-form">
					<?php wp_nonce_field( 'convoca_donation_' . $pago_id, 'convoca_donation_nonce' ); ?>
					<input type="hidden" name="convoca_gateway_method" value="<?php echo esc_attr( $method ); ?>">

					<div class="conv-field">
						<label for="convoca_donation_amount"><?php esc_html_e( 'Importe (€)', 'convoca-gateway' ); ?> *</label>
						<input type="number" name="convoca_donation_amount" id="convoca_donation_amount"
								class="regular-text" step="0.01" min="0.50" required
								value="<?php echo esc_attr( $amount ); ?>" placeholder="0.00">
						<p class="conv-help"><?php esc_html_e( 'Mínimo 0,50 €.', 'convoca-gateway' ); ?></p>
					</div>

					<div class="conv-email-field">
						<label for="convoca_donation_email"><?php esc_html_e( 'Email para el recibo (opcional)', 'convoca-gateway' ); ?></label>
						<input type="email" name="convoca_donation_email" id="convoca_donation_email"
								class="regular-text" value="<?php echo esc_attr( $email ); ?>">
						<p class="conv-help"><?php esc_html_e( 'Si lo indicas, te enviamos el recibo de tu aportación.', 'convoca-gateway' ); ?></p>
					</div>

					<div class="form-actions">
						<button type="submit" class="wp-block-button__link">
							<?php
							printf(
								/* translators: %s: payment method label (Tarjeta, Bizum o Transferencia). */
								esc_html__( 'Continuar con %s', 'convoca-gateway' ),
								esc_html( $methods[ $method ]['label'] )
							);
							?>
							&rarr;
						</button>
					</div>
				</form>
			<?php endif; ?>

			<p class="conv-security-note">
				<?php if ( 'transferencia' === $method ) : ?>
					🍀 <?php esc_html_e( 'Al continuar te mostraremos los datos para hacer el ingreso.', 'convoca-gateway' ); ?>
				<?php else : ?>
					🔒 <?php esc_html_e( 'Pago seguro gestionado por Redsys. No almacenamos tus datos bancarios.', 'convoca-gateway' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<style>
			.conv-donation-form .conv-field,
			.conv-donation-form .conv-email-field { margin-bottom: 1.25rem; }
			.conv-donation-form .conv-field label,
			.conv-donation-form .conv-email-field label { display: block; font-weight: 600; margin-bottom: .35rem; }
			.conv-donation-form input[type="number"] { max-width: 180px; font-size: 1.25rem; padding: .6rem .75rem; }
			.conv-donation-form .form-actions { margin-top: 1.5rem; }
			.conv-help { margin: .35rem 0 0; font-size: .85rem; opacity: .75; }
		</style>
		<?php
		return $this->compact_html( (string) ob_get_clean() );
	}

	/**
	 * Procesa el formulario de donativo: crea un pago con el importe elegido.
	 *
	 * El registro de pago se crea aquí y no en el enlace, para que cada aportación
	 * tenga su propia orden de Redsys, su recibo y su fila en el listado.
	 *
	 * @param int $pago_id Enlace de donativo.
	 * @return string HTML (redirección al pago, o el formulario con el error).
	 */
	private function handle_donation_submission( int $pago_id ): string {
		$nonce = sanitize_text_field( wp_unslash( $_POST['convoca_donation_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'convoca_donation_' . $pago_id ) ) {
			return $this->render_donation_form( $pago_id, CPT_Pago::get_meta( $pago_id ), __( 'La sesión ha caducado. Vuelve a intentarlo.', 'convoca-gateway' ) );
		}

		$amount = (float) str_replace( ',', '.', (string) wp_unslash( $_POST['convoca_donation_amount'] ?? '' ) );
		$email  = sanitize_email( wp_unslash( $_POST['convoca_donation_email'] ?? '' ) );
		$method = $this->requested_method();

		$raw_amount = (string) wp_unslash( $_POST['convoca_donation_amount'] ?? '' );

		if ( $amount < 0.50 ) {
			return $this->render_donation_form(
				$pago_id,
				CPT_Pago::get_meta( $pago_id ),
				__( 'El importe mínimo es de 0,50 €.', 'convoca-gateway' ),
				$raw_amount,
				$email
			);
		}

		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return $this->render_donation_form(
				$pago_id,
				CPT_Pago::get_meta( $pago_id ),
				__( 'El email no es válido.', 'convoca-gateway' ),
				$raw_amount,
				$email
			);
		}

		if ( '' === $method ) {
			return $this->render_donation_form(
				$pago_id,
				CPT_Pago::get_meta( $pago_id ),
				__( 'Elige un método de pago.', 'convoca-gateway' ),
				$raw_amount,
				$email
			);
		}

		$concepto = get_post_meta( $pago_id, '_convoca_product_desc', true );
		$concepto = $concepto ?: __( 'Donativo', 'convoca-gateway' );

		$pago = CPT_Pago::create_link_payment(
			array(
				'amount'         => $amount,
				'concepto'       => $concepto,
				'method'         => $method,
				'payer_email'    => $email,
				// Donativo: el recibo se envía siempre que haya email, sin depender
				// del aviso general de confirmaciones del plugin.
				'receipt_always' => ( '' !== $email ) ? '1' : '',
				'es_donacion'    => '1',
				'expires_at'     => 'never',
				'origin'         => 'donativo',
				'origin_id'      => $pago_id,
			)
		);

		if ( is_wp_error( $pago ) ) {
			return $this->render_donation_form( $pago_id, CPT_Pago::get_meta( $pago_id ), $pago->get_error_message(), $raw_amount, $email );
		}

		\Convoca\Core\Logger::info(
			sprintf(
				'Donativo desde el enlace #%d: pago #%d por %s€ (%s).',
				$pago_id,
				$pago,
				number_format( $amount, 2, ',', '.' ),
				$method
			),
			'Gateway/Donation',
			$pago
		);

		$token = get_post_meta( $pago, '_convoca_link_key', true );
		$url   = add_query_arg( array( 'convoca_gateway_method' => $method ), self::get_payment_link( $pago, $token ) );

		return '<script>window.location.href="' . esc_url_raw( $url ) . '";</script>' .
			'<div class="convoca-alert convoca-alert--info">' .
			esc_html__( 'Redirigiendo al pago seguro... Si no eres redirigido,', 'convoca-gateway' ) .
			' <a href="' . esc_url( $url ) . '">' . esc_html__( 'haz clic aquí', 'convoca-gateway' ) . '</a>.</div>';
	}

	/**
	 * Render bank transfer instructions.
	 */
	private function render_transfer_instructions( int $pago_id, array $meta ): string {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$iban     = $settings['iban'] ?? '';

		if ( empty( $iban ) ) {
			return '<div class="convoca-form convoca-card" style="max-width:500px; margin: 2rem auto; text-align:center;">
                <div class="convoca-alert convoca-alert--danger">
                    <h4 style="margin-top:0">⚠️ Método no disponible</h4>
                    <p>Lo sentimos, el pago por transferencia no está configurado correctamente en este momento (falta el IBAN de destino).</p>
                    <div style="margin-top:1.5rem">
                        <a href="' . esc_url( remove_query_arg( 'convoca_gateway_method' ) ) . '" class="wp-block-button__link">Volver a elegir método</a>
                    </div>
                </div>
            </div>';
		}

		$beneficiary    = $settings['beneficiary'] ?? 'Asociación Convoca';
		$instructions   = $settings['instructions'] ?? '';
		$amount_display = CPT_Pago::format_amount( (int) $meta['amount_cents'] );
		$order_id       = $meta['order_id'];

		ob_start();
		?>
		<div class="conv-payment-wrapper convoca-form conv-transfer-view" role="region" aria-label="Instrucciones de transferencia">
			<div class="conv-payment-summary">
				<div class="conv-success-icon">🍀</div>
				<h3>Pago por Transferencia</h3>
				<p>Por favor, realiza el ingreso con los siguientes datos:</p>
			</div>

			<div class="conv-transfer-details">
				<div class="conv-detail-row">
					<span class="conv-detail-label">Importe:</span>
					<span class="conv-detail-value conv-highlight"><?php echo esc_html( $amount_display ); ?></span>
				</div>
				<div class="conv-detail-row">
					<span class="conv-detail-label">IBAN:</span>
					<span class="conv-detail-value conv-copyable" id="conv-iban"><?php echo esc_html( $iban ); ?></span>
				</div>
				<div class="conv-detail-row">
					<span class="conv-detail-label">Beneficiario:</span>
					<span class="conv-detail-value"><?php echo esc_html( $beneficiary ); ?></span>
				</div>
				<div class="conv-detail-row">
					<span class="conv-detail-label">Concepto (MUY IMPORTANTE):</span>
					<span class="conv-detail-value conv-highlight conv-copyable" id="conv-concept"><?php echo esc_html( $order_id ); ?></span>
				</div>
			</div>

			<?php if ( $instructions ) : ?>
			<div class="conv-transfer-instructions">
				<h4>Instrucciones adicionales</h4>
				<p><?php echo nl2br( esc_html( $instructions ) ); ?></p>
			</div>
			<?php endif; ?>

			<div class="convoca-alert convoca-alert--info">
				<p>Tu inscripción quedará como <strong>pendiente</strong> hasta que verifiquemos el ingreso (suele tardar 24-48h hábiles).</p>
			</div>

			<div class="conv-proof-upload">
				<h4>Adjuntar justificante de pago</h4>
				<p class="text-muted">Si adjuntas el justificante en PDF, podremos validar tu pago mucho más rápido.</p>

				<?php if ( $this->upload_success ) : ?>
					<div class="convoca-alert convoca-alert--success">
						✅ Justificante enviado correctamente. Revisaremos tu pago pronto.
					</div>
				<?php elseif ( $this->upload_error ) : ?>
					<div class="convoca-alert convoca-alert--danger">
						❌ <?php echo esc_html( $this->upload_error ); ?>
					</div>
				<?php endif; ?>

				<?php
				$proof_file = get_post_meta( $pago_id, '_convoca_proof_file', true );
				if ( ! $this->upload_success && ! $proof_file ) :
					?>
				<form method="post" enctype="multipart/form-data" class="conv-upload-form">
					<?php wp_nonce_field( 'convoca_gateway_proof_upload_action', 'convoca_gateway_proof_nonce' ); ?>
					<input type="hidden" name="convoca_gateway_upload_proof" value="1">
					<div class="form-group">
						<input type="file" name="convoca_gateway_proof_file" accept=".pdf,image/*" required>
						<button type="submit" class="wp-block-button__link">Enviar justificante</button>
					</div>
				</form>
				<?php elseif ( $proof_file ) : ?>
					<div class="conv-proof-exists">
						📄 Ya has enviado un justificante. Si necesitas cambiarlo, contacta con nosotros.
					</div>
				<?php endif; ?>
			</div>

			<div class="conv-actions">
				<a href="<?php echo esc_url( remove_query_arg( 'convoca_gateway_method' ) ); ?>" class="conv-back-link">
					&larr; Volver a elegir método
				</a>
				<button type="button" class="wp-block-button__link" onclick="window.print()">
					🖨️ Imprimir instrucciones
				</button>
			</div>
		</div>
		<style>
			.conv-transfer-view .conv-success-icon { font-size: 3rem; margin-bottom: 1rem; }
			.conv-transfer-details { 
				background: #fcfcfc; 
				border: 1px solid #eee; 
				border-radius: 12px; 
				padding: 1.5rem; 
				margin: 1.5rem 0;
			}
			.conv-detail-row { 
				display: flex; 
				justify-content: space-between; 
				padding: 0.75rem 0; 
				border-bottom: 1px solid #f0f0f0; 
			}
			.conv-detail-row:last-child { border-bottom: none; }
			.conv-detail-label { font-weight: 700; color: #666; font-size: 0.9rem; }
			.conv-detail-value { font-family: monospace; font-size: 1.1rem; color: #333; }
			.conv-highlight { color: var(--wp--preset--color--naranja, #ff8700); font-weight: 800; }
			.conv-copyable { cursor: pointer; position: relative; }
			.conv-copyable:hover { text-decoration: underline; }
			.conv-transfer-instructions { 
				text-align: left; 
				margin: 1.5rem 0; 
				padding: 1rem; 
				background: #fff8f0; 
				border-radius: 8px; 
			}
			.conv-transfer-instructions h4 { margin-top: 0; color: #a65d00; }
			.conv-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; }
			.conv-back-link { font-size: 0.9rem; color: #888; text-decoration: none; }
			.conv-back-link:hover { color: #333; }
			.conv-proof-upload { 
				margin-top: 2rem; 
				padding: 1.5rem; 
				background: #f8f9fa; 
				border-radius: 12px; 
				border: 1px dashed #ced4da; 
				text-align: left;
			}
			.conv-proof-upload h4 { margin-top: 0; }
			.conv-upload-form .form-group { display: flex; gap: 1rem; align-items: center; margin-top: 1rem; }
			.conv-upload-form input[type="file"] { flex-grow: 1; font-size: 0.9rem; }
			.conv-proof-exists { color: #28a745; font-weight: 600; padding: 0.5rem 0; }
		</style>
		<script>
			document.querySelectorAll('.conv-copyable').forEach(el => {
				el.addEventListener('click', () => {
					const text = el.innerText;
					navigator.clipboard.writeText(text).then(() => {
						const originalText = el.innerText;
						el.innerText = '¡Copiado!';
						setTimeout(() => el.innerText = originalText, 1000);
					});
				});
			});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle proof of payment upload.
	 */
	private function handle_proof_upload( int $pago_id ): bool|\WP_Error {
		if ( empty( $_FILES['convoca_gateway_proof_file']['name'] ) ) {
			return new \WP_Error( 'no_file', 'No se ha seleccionado ningún archivo.' );
		}

		// Validate size (5MB limit).
		$max_size = 5 * 1024 * 1024;
		if ( $_FILES['convoca_gateway_proof_file']['size'] > $max_size ) {
			return new \WP_Error( 'file_too_large', 'El archivo es demasiado grande. El límite es de 5MB.' );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Use the smaller of our limit and the server's max upload size.
		$max_size = min( 5 * 1024 * 1024, wp_max_upload_size() );
		if ( $_FILES['convoca_gateway_proof_file']['size'] > $max_size ) {
			$max_mb = $max_size / 1024 / 1024;
			/* translators: %s: Maximum file size in megabytes */
			return new \WP_Error( 'file_too_large', sprintf( esc_html__( 'El archivo es demasiado grande. El límite es de %sMB.', 'convoca-gateway' ), $max_mb ) );
		}

		$uploaded_file    = $_FILES['convoca_gateway_proof_file'];
		$upload_overrides = array( 'test_form' => false );

		// Validate file type using WordPress's built-in function (handles mime_content_type fallback).
		$filetype = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'] );
		if ( ! $filetype['type'] || ! $filetype['ext'] ) {
			return new \WP_Error( 'invalid_type', 'El tipo de archivo no está permitido. Solo se aceptan PDF, JPG y PNG.' );
		}

		$allowed_types = array( 'application/pdf', 'image/jpeg', 'image/png' );
		if ( ! in_array( $filetype['type'], $allowed_types ) ) {
			return new \WP_Error( 'invalid_mime', 'El contenido del archivo no coincide con una extensión permitida.' );
		}

		// Regenerate filename with UUID to prevent path traversal via original name.
		$uploaded_file['name'] = wp_generate_uuid4() . '.' . $filetype['ext'];

		// Validate real MIME type (Task 41).
		if ( function_exists( 'mime_content_type' ) ) {
			$real_mime     = mime_content_type( $uploaded_file['tmp_name'] );
			$allowed_mimes = array( 'application/pdf', 'image/jpeg', 'image/png' );
			if ( ! in_array( $real_mime, $allowed_mimes ) ) {
				return new \WP_Error( 'invalid_mime', 'El contenido del archivo no coincide con su extensión o no es un tipo permitido.' );
			}
		}

		// Delete previous file if exists.
		$old_url = get_post_meta( $pago_id, '_convoca_proof_file', true );
		if ( $old_url ) {
			$upload_dir = wp_upload_dir();
			$old_path   = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $old_url );
			if ( file_exists( $old_path ) ) {
				@wp_delete_file( $old_path );
			}
		}

		$movefile = wp_handle_upload( $uploaded_file, $upload_overrides );

		if ( $movefile && ! isset( $movefile['error'] ) ) {
			update_post_meta( $pago_id, '_convoca_proof_file', $movefile['url'] );

			// Protect the upload directory against script execution.
			$upload_dir    = wp_upload_dir();
			$htaccess_path = $upload_dir['basedir'] . '/.htaccess';
			if ( ! file_exists( $htaccess_path ) ) {
				file_put_contents( $htaccess_path, "Options -ExecCGI\nphp_flag engine off\n<Files *.php>\n    deny from all\n</Files>\n" );
			}

			// Add a note to the payment.
			$notes  = get_post_meta( $pago_id, '_convoca_notes', true );
			$notes .= "\n\n[USER] Justificante de pago adjuntado el " . wp_date( 'd/m/Y H:i' ) . ': ' . $movefile['url'];
			update_post_meta( $pago_id, '_convoca_notes', $notes );

			\Convoca\Core\Logger::info(
				"Justificante de pago subido para Pago #$pago_id",
				'Gateway/ProofUpload',
				$pago_id
			);

			return true;
		} else {
			return new \WP_Error( 'upload_error', $movefile['error'] );
		}
	}


	/**
	 * Render a manual payment form when no link is provided.
	 */
	private function render_manual_form( string $error = '' ): string {
		$method  = $this->requested_method();
		$methods = $this->enabled_methods();
		$base    = (string) ( get_permalink() ?: self::get_payment_page_url() );

		ob_start();
		?>
		<div class="conv-payment-wrapper convoca-form convoca-card card-glass">
			<div class="conv-payment-summary">
				<h3 class="text-gradient"><?php esc_html_e( 'Emitir Pago Nuevo', 'convoca-gateway' ); ?></h3>
				<p><?php esc_html_e( 'Introduce los datos para realizar un pago seguro.', 'convoca-gateway' ); ?></p>
			</div>

			<?php if ( $error ) : ?>
				<div class="convoca-alert convoca-alert--danger"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<?php if ( '' === $method ) : ?>
				<?php
				// Paso 1: método de pago. Los datos del pago llegan después.
				echo $this->render_method_picker( $base, __( 'Selecciona un método de pago', 'convoca-gateway' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Marcado propio, ya escapado.
				?>
			<?php else : ?>
				<?php echo $this->render_chosen_method( $method, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Marcado propio, ya escapado. ?>

				<form method="post" action="" class="conv-manual-form">
					<?php wp_nonce_field( 'convoca_gateway_manual_payment_action', 'convoca_gateway_manual_nonce' ); ?>
					<input type="hidden" name="convoca_gateway_manual_payment" value="1">
					<input type="hidden" name="convoca_gateway_method" value="<?php echo esc_attr( $method ); ?>">

					<div class="form-group">
						<label for="amount"><?php esc_html_e( 'Importe (€)', 'convoca-gateway' ); ?></label>
						<input type="number" name="amount" id="amount" step="0.01" min="0.50" placeholder="0.00" required>
					</div>

					<div class="form-group">
						<label for="description"><?php esc_html_e( 'Concepto o Actividad', 'convoca-gateway' ); ?></label>
						<input type="text" name="description" id="description" placeholder="<?php esc_attr_e( 'Ej: Inscripción Taller Aves', 'convoca-gateway' ); ?>" required>
					</div>

					<div class="form-group">
						<label for="email"><?php esc_html_e( 'Email para el recibo', 'convoca-gateway' ); ?></label>
						<input type="email" name="email" id="email" placeholder="tu@email.com" required>
					</div>

					<div class="form-actions">
						<button type="submit" class="wp-block-button__link">
							<?php
							printf(
								/* translators: %s: payment method label (Tarjeta, Bizum o Transferencia). */
								esc_html__( 'Continuar con %s', 'convoca-gateway' ),
								esc_html( $methods[ $method ]['label'] )
							);
							?>
							&rarr;
						</button>
					</div>
				</form>
			<?php endif; ?>

			<p class="conv-security-note">
				<?php if ( 'transferencia' === $method ) : ?>
					🍀 <?php esc_html_e( 'Al continuar te mostraremos los datos para hacer el ingreso.', 'convoca-gateway' ); ?>
				<?php else : ?>
					🔒 <?php esc_html_e( 'Pago seguro gestionado por Redsys. No almacenamos tus datos bancarios.', 'convoca-gateway' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
		return $this->compact_html( (string) ob_get_clean() );
	}

	/**
	 * Handle the manual form submission.
	 */
	private function handle_manual_payment_submission(): string {
		$amount = (float) str_replace( ',', '.', wp_unslash( $_POST['amount'] ?? 0 ) );
		$desc   = sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) );
		$email  = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$method = $this->requested_method();

		if ( '' === $method ) {
			return $this->render_manual_form( __( 'Elige un método de pago.', 'convoca-gateway' ) );
		}

		if ( $amount < 0.50 ) {
			return $this->render_manual_form( 'El importe mínimo es de 0,50€.' );
		}

		if ( empty( $desc ) || empty( $email ) ) {
			return $this->render_manual_form( 'Todos los campos son obligatorios.' );
		}

		$pago_id = CPT_Pago::create_link_payment(
			array(
				'amount'     => $amount,
				'concepto'   => $desc,
				'email'      => $email,
				'method'     => $method,
				'expires_at' => 'never',
				// Un cobro del formulario no es un enlace: si no se distingue, aparece
				// en el listado de enlaces y desaparece del de pagos.
				'origin'     => 'manual',
			)
		);

		if ( is_wp_error( $pago_id ) ) {
			return $this->render_manual_form( $pago_id->get_error_message() );
		}

		$token = get_post_meta( $pago_id, '_convoca_link_key', true );
		$url   = add_query_arg( array( 'convoca_gateway_method' => $method ), self::get_payment_link( $pago_id, $token ) );

		return '<script>window.location.href="' . esc_url_raw( $url ) . '";</script>' .
				'<div class="convoca-alert convoca-alert--info">Generando orden de pago... Si no eres redirigido, <a href="' . esc_url( $url ) . '">haz clic aquí</a>.</div>';
	}

	/**
	 * Render payment page for legacy system (24h expiration).
	 */
	private function render_legacy_payment_page( int $pago_id, int $ts, string $key ): string {
		$persistent_hash = hash_hmac( 'sha256', $pago_id . '|' . $ts . '_convoca_payment', self::get_persistent_salt() );
		$legacy_hash     = wp_hash( $pago_id . '|' . $ts . '_convoca_payment' );

		if ( ! hash_equals( $persistent_hash, $key ) && ! hash_equals( $legacy_hash, $key ) ) {
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html__( 'Enlace de pago inválido o corrupto.', 'convoca-gateway' ) . '</div>';
		}

		if ( $ts < ( time() - DAY_IN_SECONDS ) ) {
			return '<div class="convoca-alert convoca-alert--warning">' . esc_html__( 'El enlace de pago ha caducado. Por favor, solicita uno nuevo.', 'convoca-gateway' ) . '</div>';
		}

		$meta = CPT_Pago::get_meta( $pago_id );
		if ( $meta['status'] === 'paid' ) {
			return '<div class="convoca-alert convoca-alert--success">' . esc_html__( '✅ Este pago ya ha sido completado.', 'convoca-gateway' ) . '</div>';
		}

		$selected_method = sanitize_text_field( wp_unslash( $_GET['convoca_gateway_method'] ?? '' ) );

		if ( $selected_method && in_array( $selected_method, array( 'tarjeta', 'bizum' ), true ) ) {
			update_post_meta( $pago_id, '_convoca_method', $selected_method );
			return $this->render_redsys_redirect( $pago_id, $meta, $selected_method );
		}

		if ( $selected_method === 'transferencia' ) {
			update_post_meta( $pago_id, '_convoca_method', 'transferencia' );
			return $this->render_transfer_instructions( $pago_id, $meta );
		}

		return $this->render_method_selector( $pago_id, $meta );
	}

	/**
	 * Render payment method selection screen.
	 */
	private function render_method_selector( int $pago_id, array $meta ): string {
		$amount_display = CPT_Pago::format_amount( (int) $meta['amount_cents'] );
		$base_url       = self::get_payment_link( $pago_id );

		$settings         = get_option( 'convoca_gateway_settings', array() );
		$transfer_enabled = ! empty( $settings['iban'] );
		$bizum_enabled    = ! empty( Redsys_Client::merchant_code() );

		ob_start();
		?>
		<div class="conv-payment-wrapper convoca-form" role="region" aria-label="Selección de método de pago">
			<div class="conv-payment-summary">
				<h3>Resumen del pago</h3>
				<div class="conv-amount">
					<?php echo esc_html( $amount_display ); ?>
				</div>
				<?php if ( $meta['product_desc'] ) : ?>
					<p class="conv-desc">
						<?php echo esc_html( $meta['product_desc'] ); ?>
					</p>
				<?php endif; ?>
			</div>

			<?php
			// Tarjetas compartidas con el resto de flujos de pago.
			echo $this->render_method_picker( self::get_payment_link( $pago_id ), __( 'Selecciona un método de pago', 'convoca-gateway' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Marcado propio, ya escapado.
			?>

			<p class="conv-security-note">
				<?php
				/**
				 * Filter the bank/entity name shown in the payment security note.
				 *
				 * @param string $entity Translatable entity label.
				 */
				$convoca_entity = apply_filters( 'convoca_gateway_bank_entity', __( 'tu entidad bancaria', 'convoca-gateway' ) );
				?>
				🔒
				<?php
				/* translators: %s: name of the user's bank entity (filterable). */
				echo esc_html( sprintf( __( 'Pago seguro gestionado por Redsys (%s).', 'convoca-gateway' ), $convoca_entity ) );
				?>
				<?php esc_html_e( 'Tus datos bancarios nunca pasan por nuestro servidor.', 'convoca-gateway' ); ?>
			</p>
		</div>
		<style>
			.conv-payment-wrapper {
				max-width: 480px;
				margin: 2rem auto;
				text-align: center;
			}

			.conv-payment-summary {
				background: var(--wp--preset--color--gris-suave, #f5f5f5);
				border-radius: 12px;
				padding: 1.5rem;
				margin-bottom: 1.5rem;
			}

			.conv-amount {
				font-size: 2rem;
				font-weight: 700;
				color: var(--wp--preset--color--naranja, #E86833);
			}

			.conv-desc {
				color: #666;
				margin-top: .5rem;
			}

			.conv-security-note {
				font-size: .8rem;
				color: #999;
				margin-top: 1rem;
			}
		</style>
		<?php
		return $this->compact_html( (string) ob_get_clean() );
	}

	/**
	 * Render the Redsys auto-submit redirect.
	 */
	private function render_redsys_redirect( int $pago_id, array $meta, string $method ): string {
		// Check if already paid to prevent double payment attempts.
		if ( ( $meta['status'] ?? '' ) === 'paid' ) {
			return '<div class="convoca-alert convoca-alert--success">' . esc_html__( '✅ Este pago ya ha sido completado correctamente. No es necesario realizarlo de nuevo.', 'convoca-gateway' ) . '</div>';
		}

		$amount_cents = (int) ( $meta['amount_cents'] ?? 0 );

		if ( $amount_cents <= 0 ) {
			\Convoca\Core\Logger::error( "Intento de pago con importe zero. Pago ID: $pago_id", 'Gateway/Redsys', $pago_id );
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html__( 'Error: El importe del pago no es válido (0.00€).', 'convoca-gateway' ) . '</div>';
		}

		$pay_method = ( $method === 'bizum' ) ? Redsys_Client::METHOD_BIZUM : Redsys_Client::METHOD_CARD;

		$settings = get_option( 'convoca_gateway_settings', array() );
		$ok_page  = (int) ( $settings['ok_page_id'] ?? 0 );
		$ko_page  = (int) ( $settings['ko_page_id'] ?? 0 );

		$url_ok = $ok_page ? add_query_arg( 'convoca_gateway_pago', $pago_id, get_permalink( $ok_page ) ) : home_url( '/pago-completado/?convoca_gateway_pago=' . $pago_id );
		$url_ko = $ko_page ? add_query_arg( 'convoca_gateway_pago', $pago_id, get_permalink( $ko_page ) ) : home_url( '/pago-error/?convoca_gateway_pago=' . $pago_id );

		$notify_url = get_rest_url( null, 'convoca-gateway/v1/notify' );

		// Validate configuration to prevent Redsys error.
		if ( empty( Redsys_Client::merchant_code() ) || empty( Redsys_Client::secret_key() ) ) {
			return '<div class="convoca-alert convoca-alert--danger">
                <h4>➠️ Error de configuración de pagos</h4>
                <p>No se han configurado las claves de Redsys (FUC o Clave Secreta).<br>
                Por favor, contacta con el administrador del sitio para revisar los ajustes de <em>Convoca Gateway</em>.</p>
            </div>';
		}

		wp_enqueue_script( 'conv-redsys' );

		$tokenize = get_post_meta( $pago_id, '_convoca_tokenize', true ) === '1';

		$form = Redsys_Client::build_form(
			array(
				'order_id'     => $meta['order_id'],
				'amount_cents' => $amount_cents,
				'product_desc' => $meta['product_desc'],
				'pay_methods'  => $pay_method,
				'url_ok'       => $url_ok,
				'url_ko'       => $url_ko,
				'url_notify'   => $notify_url,
				'is_bizum'     => ( $method === 'bizum' ),
				'tokenize'     => $tokenize,
			)
		);

		return '<div class="conv-redirect-wrapper">
            <p class="convoca-text-center" style="padding:2rem">⏳ Redirigiendo a la pasarela de pago seguro...</p>'
			. $form .
			'</div>';
	}

	/**
	 * Check if the notification comes from a known Redsys IP.
	 */
	private function is_redsys_ip(): bool {
		$ip = wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( empty( $ip ) ) {
			return false;
		}

		// Loopback SOLO en entornos no productivos (nunca ligado a WP_DEBUG).
		$is_prod = ( Redsys_Client::settings()['environment'] ?? 'test' ) === 'production';
		if ( ! $is_prod && in_array( $ip, array( '127.0.0.1', '::1', 'localhost' ), true ) ) {
			return true;
		}

		// Common Redsys IP ranges (Europe).
		// Official docs mention specific IPs, but they often use 195.76.9.0/24.
		if ( str_starts_with( $ip, '195.76.9.' ) ) {
			return true;
		}

		$allowed_ips = array(
			'193.16.243.33',
			'195.76.9.187',
			'195.76.9.182',
			'195.76.9.222',
		);

		// Allow extending the IP list via filter (e.g., if Redsys changes their ranges).
		$allowed_ips = apply_filters( 'convoca_gateway_redsys_allowed_ips', $allowed_ips );

		return in_array( $ip, $allowed_ips, true );
	}

	/**
	 * Core processing logic for Redsys notifications.
	 *
	 * @param array $post_data Data from Redsys POST.
	 * @return bool|\WP_Error True on success, error otherwise.
	 */
	public function process_notification( array $post_data ): bool|\WP_Error {
		if ( ! $this->is_redsys_ip() ) {
			\Convoca\Core\Logger::error( 'Notificación rechazada: IP de origen no autorizada (' . ( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ) . ').', 'Gateway/Notification' );
			return new \WP_Error( 'unauthorized_ip', 'Unauthorized IP' );
		}

		$data = Redsys_Client::verify_notification( $post_data );

		if ( $data === false ) {
			$order_ref = $post_data['Ds_MerchantParameters'] ?? '';
			// Decodificar Ds_Order si es posible para trazabilidad sin romper el flujo.
			$order_id_trace = '';
			if ( is_string( $order_ref ) && '' !== $order_ref ) {
				$raw = base64_decode( strtr( $order_ref, '-_', '+/' ), true );
				if ( false !== $raw ) {
					$decoded = json_decode( $raw, true );
					if ( is_array( $decoded ) && ! empty( $decoded['Ds_Order'] ) ) {
						$order_id_trace = ' Order ' . sanitize_text_field( $decoded['Ds_Order'] );
					}
				}
			}
			\Convoca\Core\Logger::error( 'Notificación rechazada: firma Ds_Signature no válida.' . $order_id_trace, 'Gateway/Notification' );
			return new \WP_Error( 'invalid_signature', 'Invalid signature' );
		}

		$order_id      = $data['Ds_Order'] ?? '';
		$response_code = $data['Ds_Response'] ?? '9999';
		$auth_code     = $data['Ds_AuthorisationCode'] ?? '';

		global $wpdb;

		// Use savepoints for pseudo-nested transactions instead of static blocking.
		static $savepoint_depth = 0;
		$savepoint_name         = 'convoca_gateway_sp_' . $savepoint_depth;

		if ( $savepoint_depth === 0 ) {
			$wpdb->query( 'START TRANSACTION' );
		} else {
			$wpdb->query( "SAVEPOINT $savepoint_name" );
		}
		++$savepoint_depth;
		$outer_transaction = false;

		// 2. Find and LOCK the payment in a single step to prevent race conditions
		$pago_id = CPT_Pago::find_by_order_locked( $order_id );

		if ( ! $pago_id ) {
			--$savepoint_depth;
			if ( $savepoint_depth === 0 ) {
				$wpdb->query( 'ROLLBACK' );
			} else {
				$wpdb->query( "ROLLBACK TO $savepoint_name" );
			}
			return new \WP_Error( 'order_not_found', 'Order not found' );
		}

		// 3. Re-check status AFTER acquiring the lock
		$current_status = get_post_meta( $pago_id, '_convoca_status', true );
		if ( $current_status === 'paid' ) {
			--$savepoint_depth;
			if ( $savepoint_depth === 0 ) {
				$wpdb->query( 'COMMIT' );
			}
			\Convoca\Core\Logger::info( "Notificación duplicada para Order {$order_id} ignorada (ya pagado).", 'Gateway/Notification', $pago_id );
			return true;
		}

		// 3b. Validación de integridad financiera: importe, moneda y merchant code
		// deben coincidir con lo esperado para este pago (la firma protege el origen,
		// pero no que la notificación corresponda al importe real de la cuota).
		$expected_cents = (int) get_post_meta( $pago_id, '_convoca_amount_cents', true );
		$notif_cents    = isset( $data['Ds_Amount'] ) ? (int) $data['Ds_Amount'] : -1;
		if ( $notif_cents !== $expected_cents ) {
			\Convoca\Core\Logger::error( "Importe de notificación ($notif_cents) no coincide con el pago esperado ($expected_cents) para Order $order_id.", 'Gateway/Notification', $pago_id );
			--$savepoint_depth;
			if ( $savepoint_depth === 0 ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new \WP_Error( 'amount_mismatch', 'Amount mismatch' );
		}
		if ( (string) ( $data['Ds_Currency'] ?? '' ) !== Redsys_Client::CURRENCY_EUR ) {
			\Convoca\Core\Logger::error( "Moneda de notificación inesperada para Order $order_id: " . ( $data['Ds_Currency'] ?? 'N/A' ) . '.', 'Gateway/Notification', $pago_id );
			--$savepoint_depth;
			if ( $savepoint_depth === 0 ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new \WP_Error( 'currency_mismatch', 'Currency mismatch' );
		}
		if ( ! in_array( (string) ( $data['Ds_MerchantCode'] ?? '' ), array( Redsys_Client::merchant_code() ), true ) ) {
			\Convoca\Core\Logger::error( "Merchant code de notificación no coincide para Order $order_id: " . ( $data['Ds_MerchantCode'] ?? 'N/A' ) . '.', 'Gateway/Notification', $pago_id );
			--$savepoint_depth;
			if ( $savepoint_depth === 0 ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new \WP_Error( 'merchant_mismatch', 'Merchant code mismatch' );
		}

		$is_approved = Redsys_Client::is_approved( $response_code );
		$new_status  = $is_approved ? 'paid' : 'failed';

		try {
			update_post_meta( $pago_id, '_convoca_status', $new_status );
			update_post_meta( $pago_id, '_convoca_redsys_response', $response_code );
			update_post_meta( $pago_id, '_convoca_redsys_auth_code', $auth_code );
			update_post_meta( $pago_id, '_convoca_redsys_full_log', wp_json_encode( $data ) );

			if ( ! empty( $data['Ds_Merchant_Identifier'] ) ) {
				update_post_meta( $pago_id, '_convoca_redsys_merchant_id', sanitize_text_field( $data['Ds_Merchant_Identifier'] ) );
			} elseif ( ! empty( $data['Ds_MerchantIdentifier'] ) ) {
				update_post_meta( $pago_id, '_convoca_redsys_merchant_id', sanitize_text_field( $data['Ds_MerchantIdentifier'] ) );
			}

			if ( $is_approved ) {
				update_post_meta( $pago_id, '_convoca_paid_at', current_time( 'mysql' ) );

				// Get fresh meta for the hooks.
				$meta = CPT_Pago::get_meta( $pago_id );
				\Convoca\Core\Utils::do_action( 'convoca_gateway_payment_completed', 'convoca_payment_completed', $pago_id, $meta['origin'], (int) $meta['origin_id'], $meta );
			} else {
				\Convoca\Core\Utils::do_action( 'convoca_gateway_payment_failed', 'convoca_payment_failed', $pago_id, $response_code );
			}

			// Sin este decremento la profundidad se quedaba en 1, el COMMIT no se
			// ejecutaba nunca y la transacción se descartaba al cerrar la conexión:
			// la notificación se daba por buena («OK») y el pago seguía pendiente.
			--$savepoint_depth;
			if ( $savepoint_depth === 0 ) {
				$wpdb->query( 'COMMIT' );
			}
		} catch ( \Throwable $e ) {
			--$savepoint_depth;
			if ( $savepoint_depth === 0 ) {
				$wpdb->query( 'ROLLBACK' );
			} else {
				$wpdb->query( "ROLLBACK TO $savepoint_name" );
			}
			\Convoca\Core\Logger::error( 'Error al procesar notificación de pago: ' . $e->getMessage(), 'Gateway/Notification', $pago_id );
			return new \WP_Error( 'processing_error', 'Error al procesar el pago' );
		}

		return true;
	}

	/**
	 * Process the synchronous browser return from Redsys (GET /pago-ok).
	 *
	 * The notification server-to-server (process_notification) is the source of
	 * truth in production, but in sandbox/test environments it often never
	 * arrives — only the browser redirect with Ds_* params does. This method
	 * validates the HMAC signature of the return (same as a notification, but
	 * without IP restrictions since the user's browser IP is arbitrary) and, if
	 * the payment is approved and not yet paid, applies the confirmation.
	 *
	 * @param array $get_data Unsanitized $_GET.
	 */
	public function process_return( array $get_data ): true|\WP_Error {
		$data = Redsys_Client::verify_notification( $get_data );

		if ( $data === false ) {
			return new \WP_Error( 'invalid_signature', 'Invalid signature in payment return' );
		}

		$order_id      = $data['Ds_Order'] ?? '';
		$response_code = $data['Ds_Response'] ?? '9999';
		$auth_code     = $data['Ds_AuthorisationCode'] ?? '';
		$pago_id       = CPT_Pago::find_by_order( $order_id );

		if ( ! $pago_id ) {
			return new \WP_Error( 'order_not_found', 'Order not found in payment return' );
		}

		// Idempotente: si ya está pagado no re-procesar (evita duplicar hooks).
		if ( get_post_meta( $pago_id, '_convoca_status', true ) === 'paid' ) {
			return true;
		}

		// Validación de integridad financiera (importe y merchant), como en la notificación.
		$expected_cents = (int) get_post_meta( $pago_id, '_convoca_amount_cents', true );
		$notif_cents    = isset( $data['Ds_Amount'] ) ? (int) $data['Ds_Amount'] : -1;
		if ( $notif_cents !== $expected_cents ) {
			\Convoca\Core\Logger::error( "Importe del retorno ($notif_cents) no coincide con el pago esperado ($expected_cents) para Order $order_id.", 'Gateway/Return', $pago_id );
			return new \WP_Error( 'amount_mismatch', 'Amount mismatch in payment return' );
		}
		if ( ! in_array( (string) ( $data['Ds_MerchantCode'] ?? '' ), array( Redsys_Client::merchant_code() ), true ) ) {
			\Convoca\Core\Logger::error( "Merchant code del retorno no coincide para Order $order_id.", 'Gateway/Return', $pago_id );
			return new \WP_Error( 'merchant_mismatch', 'Merchant code mismatch in payment return' );
		}

		$is_approved = Redsys_Client::is_approved( $response_code );
		$new_status  = $is_approved ? 'paid' : 'failed';

		update_post_meta( $pago_id, '_convoca_status', $new_status );
		update_post_meta( $pago_id, '_convoca_redsys_response', $response_code );
		update_post_meta( $pago_id, '_convoca_redsys_auth_code', $auth_code );
		update_post_meta( $pago_id, '_convoca_redsys_full_log', wp_json_encode( $data ) );

		// Capturar la referencia de tarjeta (COF/tokenización) cuando Redsys la
		// devuelve en el retorno síncrono. El TPV la manda como
		// Ds_Merchant_Identifier; toleramos también la variante camelCase.
		if ( ! empty( $data['Ds_Merchant_Identifier'] ) ) {
			update_post_meta( $pago_id, '_convoca_redsys_merchant_id', sanitize_text_field( $data['Ds_Merchant_Identifier'] ) );
		} elseif ( ! empty( $data['Ds_MerchantIdentifier'] ) ) {
			update_post_meta( $pago_id, '_convoca_redsys_merchant_id', sanitize_text_field( $data['Ds_MerchantIdentifier'] ) );
		}

		if ( $is_approved ) {
			update_post_meta( $pago_id, '_convoca_paid_at', current_time( 'mysql' ) );

			// Get fresh meta for the hooks.
			$meta = CPT_Pago::get_meta( $pago_id );
			\Convoca\Core\Utils::do_action( 'convoca_gateway_payment_completed', 'convoca_payment_completed', $pago_id, $meta['origin'], (int) $meta['origin_id'], $meta );
			\Convoca\Core\Logger::info( "Pago confirmado por retorno síncrono (Order $order_id).", 'Gateway/Return', $pago_id );
		} else {
			\Convoca\Core\Utils::do_action( 'convoca_gateway_payment_failed', 'convoca_payment_failed', $pago_id, $response_code );
		}

		return true;
	}

	/* ── Return pages ──────────────────────────── */

	public function render_ok_page( $atts ): string {
		$pago_id = (int) ( wp_unslash( $_GET['convoca_gateway_pago'] ?? 0 ) );

		if ( ! $pago_id ) {
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html__( 'ID de pago no especificado.', 'convoca-gateway' ) . '</div>';
		}

		$post = get_post( $pago_id );
		if ( ! $post || $post->post_type !== 'pago' ) {
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html__( 'Pago no encontrado.', 'convoca-gateway' ) . '</div>';
		}

		$meta = CPT_Pago::get_meta( $pago_id );

		// E2E-5: si el pago aún no está confirmado pero el navegador vuelve con
		// parámetros Ds_* de Redsys (retorno síncrono tras el TPV/3DS), procesar
		// la confirmación verificando la firma HMAC — no depende de que la
		// notificación server-to-server haya llegado (no llega en sandbox/test).
		if ( ( $meta['status'] ?? '' ) !== 'paid' && ! empty( $_GET['Ds_MerchantParameters'] ) ) {
			$return_result = $this->process_return( wp_unslash( $_GET ) );
			if ( is_wp_error( $return_result ) ) {
				\Convoca\Core\Logger::warning( 'Retorno de pago no procesado en pago-ok: ' . $return_result->get_error_message(), 'Gateway/Return', $pago_id );
			}
			// Releer meta por si la confirmación acaba de aplicarse.
			$meta = CPT_Pago::get_meta( $pago_id );
		}

		// Safety check: if the payment is not paid, show an error and a link to retry.
		if ( ( $meta['status'] ?? '' ) !== 'paid' ) {
			$token = get_post_meta( $pago_id, '_convoca_link_key', true );
			// E2E-4: $expires puede ser string vacío; la firma exige ?int|null.
			$expires_raw = get_post_meta( $pago_id, '_convoca_expires_at', true );
			$expires     = is_numeric( $expires_raw ) ? (int) $expires_raw : null;
			$url         = self::get_payment_link( $pago_id, is_string( $token ) ? $token : '', $expires );

			return '<div class="convoca-alert convoca-alert--warning">
                <h4>⚠️ Pago aún no confirmado</h4>
                <p>El sistema no ha recibido la confirmación del pago todavía. Si acabas de realizarlo, espera unos minutos y recarga la página.</p>
                <div style="margin-top:1.5rem">
                    <a href="' . esc_url( $url ) . '" class="wp-block-button__link">Intentar completar el pago</a>
                </div>
            </div>';
		}

		ob_start();
		?>
		<div class="conv-result convoca-form" role="status" aria-live="polite">
			<div class="conv-result-icon">&#x1F389;</div>
			<h3>&iexcl;Pago completado!</h3>
			<p>Tu pago de <strong>
					<?php echo esc_html( CPT_Pago::format_amount( (int) ( $meta['amount_cents'] ?? 0 ) ) ); ?>
				</strong>
				ha sido procesado correctamente.</p>
			<?php if ( ! empty( $meta['product_desc'] ) ) : ?>
				<p class="conv-desc">
					<?php echo esc_html( $meta['product_desc'] ); ?>
				</p>
			<?php endif; ?>
			<p>Recibir&aacute;s un email de confirmaci&oacute;n en breve.</p>
			<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="convoca-btn convoca-btn-primary">&larr; Volver al inicio</a></p>
		</div>
		<style>
			.conv-result {
				max-width: 480px;
				margin: 2rem auto;
				text-align: center;
			}

			.conv-result-icon {
				font-size: 4rem;
				margin-bottom: 1rem;
			}
		</style>
		<?php
		return ob_get_clean();
	}

	public function render_ko_page( $atts ): string {
		$pago_id = (int) ( wp_unslash( $_GET['convoca_gateway_pago'] ?? 0 ) );

		$reason = '';
		if ( $pago_id ) {
			$response = (string) get_post_meta( $pago_id, '_convoca_redsys_response', true );
			if ( '' !== $response ) {
				$reason = Redsys_Client::get_response_message( $response );
			}
		}

		ob_start();
		?>
		<div class="conv-result convoca-form" role="alert">
			<div class="conv-result-icon">&#x1F61E;</div>
			<h3>Pago no completado</h3>
			<p>El pago no se ha podido procesar. Puede deberse a una cancelación o un problema con tu banco.</p>
			<?php if ( '' !== $reason ) : ?>
				<p class="conv-desc"><?php echo esc_html( $reason ); ?></p>
			<?php endif; ?>
			<?php if ( $pago_id ) : ?>
				<?php echo $this->render_regenerate_form( $pago_id, '🔄 Reintentar pago' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form HTML already escaped internally ?>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="convoca-btn convoca-btn-outline">&larr; Volver al inicio</a></p>
		</div>
		<style>
			.conv-result {
				max-width: 480px;
				margin: 2rem auto;
				text-align: center;
			}

			.conv-result-icon {
				font-size: 4rem;
				margin-bottom: 1rem;
			}
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Aviso de un enlace cerrado porque su pago ya se cobró.
	 *
	 * Sin formulario de «solicitar nuevo enlace» a propósito: el pago está hecho,
	 * no hay nada que volver a pagar.
	 */
	private function render_collected_notice( int $pago_id ): string {
		$closed_at = Link_Expiry::closed_at( $pago_id );
		$paid_at   = get_post_meta( $pago_id, '_convoca_paid_at', true );
		$fecha     = $closed_at > 0 ? wp_date( 'd/m/Y H:i', $closed_at ) : '';
		if ( '' === $fecha && ! empty( $paid_at ) ) {
			$fecha = wp_date( 'd/m/Y H:i', (int) strtotime( (string) $paid_at ) );
		}

		return '<div class="convoca-alert convoca-alert--success">'
			. esc_html__( '✅ Este enlace ya se ha cobrado', 'convoca-gateway' )
			. ( $fecha ? esc_html( ' (' . $fecha . ')' ) : '' )
			. '. ' . esc_html__( 'El enlace queda cerrado y no admite más pagos.', 'convoca-gateway' )
			. '</div>';
	}

	/**
	 * Render the expired-link notice with a "request a new link" action (D21c).
	 */
	private function render_expired_notice( int $pago_id ): string {
		$form = $this->render_regenerate_form( $pago_id, __( 'Solicitar nuevo enlace', 'convoca-gateway' ) );

		return '<div class="convoca-alert convoca-alert--warning">'
			. esc_html__( 'El enlace de pago ha caducado. Solicita un enlace nuevo para completar el pago.', 'convoca-gateway' )
			. $form
			. '</div>';
	}

	/**
	 * Build the form used to regenerate a payment link (D21c/D22c).
	 */
	private function render_regenerate_form( int $pago_id, string $label ): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( self::get_payment_page_url() ); ?>" style="margin-top:1rem;">
			<?php wp_nonce_field( 'convoca_gateway_regenerate_action', 'convoca_gateway_regenerate_nonce' ); ?>
			<input type="hidden" name="convoca_gateway_pago" value="<?php echo esc_attr( (string) $pago_id ); ?>">
			<button type="submit" name="convoca_gateway_regenerate" class="wp-block-button__link"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle a link regeneration request (keeps the same payment/order).
	 */
	private function handle_regenerate_link(): string {
		$pago_id = (int) ( wp_unslash( $_POST['convoca_gateway_pago'] ?? 0 ) );
		if ( ! $pago_id ) {
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html__( 'Pago no especificado.', 'convoca-gateway' ) . '</div>';
		}

		$url = Link_Expiry::regenerate_link( $pago_id );
		if ( is_wp_error( $url ) ) {
			return '<div class="convoca-alert convoca-alert--danger">' . esc_html( $url->get_error_message() ) . '</div>';
		}

		// Re-send the link to the recipient, if any.
		( new Email_Notifications() )->send_link_email( $pago_id );

		return '<script>window.location.href="' . esc_url_raw( $url ) . '";</script>'
			. '<div class="convoca-alert convoca-alert--info">' . esc_html__( 'Generando nuevo enlace… Si no eres redirigido, haz clic aquí.', 'convoca-gateway' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Continuar', 'convoca-gateway' ) . '</a></div>';
	}

	}