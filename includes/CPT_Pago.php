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
 * CPT: pago — internal payment record.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPT_Pago {


	/** Meta keys for payment records. */
	public const META_KEYS = array(
		'order_id',           // Redsys order ID (12 chars).
		'amount_cents',       // Amount in cents (integer).
		'currency',           // ISO 4217 numeric (978 = EUR).
		'method',             // tarjeta | bizum.
		'status',             // pending | paid | failed | refunded.
		'origin',             // enroll | members.
		'origin_id',          // Post ID of inscription/miembro.
		'redsys_response',    // Full Redsys response code.
		'redsys_auth_code',   // Authorisation code from Redsys.
		'product_desc',       // Description shown on bank statement.
		'created_at',         // Timestamp of creation.
		'paid_at',            // Timestamp of successful payment.
		// Link payment fields.
		'link_key',           // Token for payment link validation.
		'expires_at',         // Link expiration timestamp.
		'recipient_email',    // Email for payment link.
		'params',             // Custom parameters (serialized).
		'link_generated_by',  // Admin user ID who generated the link.
		'open_amount',        // '1' = enlace de donativo: el importe lo elige quien aporta.
		'payer_email',        // Email de quien paga (recibo). Puede venir del formulario.
		// Recurring payment fields.
		'redsys_merchant_id', // Tokenized card identifier for recurring payments.
		'proof_file',         // ID or URL of uploaded payment receipt.
		// Receipt / donation fields (D23/D24).
		'es_donacion',        // '1' when the payment is a donation.
		'receipt_number',     // Annual number (AÑO-NNN or D-AÑO-NNN).
		'receipt_year',       // Year the receipt number was assigned.
		'receipt_pdf',        // URL of the receipt/justification print page.
		'receipt_key',        // Unguessable access key for the receipt page.
	);

	/** Status labels. */
	public static function status(): array {
		return array(
			'pending'  => __( 'Pendiente', 'convoca-gateway' ),
			'paid'     => __( 'Pagado', 'convoca-gateway' ),
			'failed'   => __( 'Fallido', 'convoca-gateway' ),
			'refunded' => __( 'Reembolsado', 'convoca-gateway' ),
		);
	}

	/** Status badge classes. */
	public const BADGE = array(
		'pending'  => 'convoca-badge convoca-badge--warning',
		'paid'     => 'convoca-badge convoca-badge--success',
		'failed'   => 'convoca-badge convoca-badge--danger',
		'refunded' => 'convoca-badge convoca-badge--info',
	);

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'redirect_default_editor' ) );
		add_action( 'load-post.php', array( __CLASS__, 'redirect_default_editor' ) );
	}

	public static function redirect_default_editor(): void {
		global $typenow;
		if ( $typenow === 'pago' ) {
			$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
			if ( $post_id > 0 ) {
				wp_safe_redirect( admin_url( 'admin.php?page=conv-gateway-payments-detail&id=' . $post_id ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=conv-gateway-generador' ) );
			}
			exit;
		}
	}

	public static function register(): void {
		register_post_type(
			'pago',
			array(
				'labels'          => array(
					'name'          => __( 'Pagos', 'convoca-gateway' ),
					'singular_name' => __( 'Pago', 'convoca-gateway' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'has_archive'     => false,
				'rewrite'         => false,
			)
		);
	}

	/**
	 * Create a new payment record.
	 *
	 * @param array $data {
	 *     @type int    $amount_cents   Amount in cents.
	 *     @type string $method         Payment method (tarjeta|bizum).
	 *     @type string $origin         Origin plugin (enroll|members).
	 *     @type int    $origin_id      Post ID of the origin record.
	 *     @type string $product_desc   Bank statement description.
	 * }
	 * @return int|\WP_Error Payment post ID.
	 */
	public static function create( array $data ): int|\WP_Error {
		$order_id = Redsys_Client::generate_order_id();
		$amount   = (int) ( $data['amount_cents'] ?? 0 );

		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_amount', __( 'El importe debe ser mayor que 0.', 'convoca-gateway' ) );
		}

		$title = sprintf(
			'%s — %s€ — %s',
			$order_id,
			number_format( $amount / 100, 2, ',', '.' ),
			$data['origin'] ?? 'unknown'
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'pago',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( ! $post_id ) {
			// wp_insert_post devuelve 0 en error (no WP_Error en WP moderno).
			return new \WP_Error( 'insert_failed', __( 'No se pudo crear el registro de pago.', 'convoca-gateway' ) );
		}

		$meta = array(
			'order_id'         => $order_id,
			'amount_cents'     => $amount,
			'currency'         => Redsys_Client::CURRENCY_EUR,
			'method'           => sanitize_text_field( $data['method'] ?? Redsys_Client::default_method() ),
			'status'           => 'pending',
			'origin'           => sanitize_text_field( $data['origin'] ?? '' ),
			'origin_id'        => (int) ( $data['origin_id'] ?? 0 ),
			'product_desc'     => sanitize_text_field( $data['product_desc'] ?? '' ),
			'redsys_response'  => '',
			'redsys_auth_code' => '',
			'created_at'       => current_time( 'mysql' ),
			'created_ts'       => time(),
			'paid_at'          => '',
		);

		foreach ( $meta as $key => $val ) {
			update_post_meta( $post_id, '_convoca_' . $key, $val );
		}

		// Correo de contacto de quien paga. Lo entrega el plugin que crea el pago
		// (socio o inscripción ya lo tienen): es lo que permite enviarle el recibo y
		// avisarle de que su enlace caduca.
		$payer_email = sanitize_email( $data['payer_email'] ?? ( $data['email'] ?? '' ) );
		if ( '' !== $payer_email ) {
			update_post_meta( $post_id, '_convoca_payer_email', $payer_email );
		}
		if ( ! empty( $data['receipt_always'] ) ) {
			update_post_meta( $post_id, '_convoca_receipt_always', '1' );
		}

		$recipient = sanitize_email( $data['recipient_email'] ?? '' );
		if ( '' !== $recipient ) {
			update_post_meta( $post_id, '_convoca_recipient_email', $recipient );
		}

		return $post_id;
	}

	/**
	 * Token de una URL de pago, ligado al registro y a su caducidad.
	 *
	 * Va firmado con una sal persistente (no con WP_SALT) para que la URL no deje de
	 * valer si cambian las claves del sitio. Lo usan los enlaces generados a mano y
	 * los pagos que crean los demás plugins.
	 *
	 * @param int $post_id    ID del registro de pago.
	 * @param int $expires_ts Caducidad en marca de tiempo (0 = sin caducidad).
	 * @return string Token.
	 */
	public static function generate_link_token( int $post_id, int $expires_ts ): string {
		$salt = get_option( 'convoca_gateway_persistent_salt' );
		if ( ! $salt ) {
			$salt = wp_generate_password( 64, true, true );
			update_option( 'convoca_gateway_persistent_salt', $salt, false );
		}

		return hash_hmac( 'sha256', $post_id . '|' . $expires_ts, (string) $salt );
	}

	/**
	 * Get meta data for a payment.
	 */
	public static function get_meta( int $post_id ): array {
		$data = array();
		foreach ( self::META_KEYS as $key ) {
			$data[ $key ] = get_post_meta( $post_id, '_convoca_' . $key, true );
		}
		return $data;
	}

	/**
	 * Find payment by Redsys order ID.
	 */
	public static function find_by_order( string $order_id ): ?int {
		$posts = get_posts(
			array(
				'post_type'      => 'pago',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => '_convoca_order_id',
						'value' => $order_id,
					),
				),
			)
		);

		return ! empty( $posts ) ? $posts[0]->ID : null;
	}

	/**
	 * Find payment by Redsys order ID with row-level locking.
	 * MUST be called inside a transaction.
	 *
	 * @param string $order_id Redsys order ID.
	 * @return int|null Payment ID.
	 */
	public static function find_by_order_locked( string $order_id ): ?int {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_convoca_order_id' AND pm.meta_value = %s 
             AND p.post_type = 'pago'
             LIMIT 1 FOR UPDATE",
				$order_id
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Render status badge HTML.
	 */
	public static function badge( string $status ): string {
		$label = self::status()[ $status ] ?? $status;
		$class = self::BADGE[ $status ] ?? 'convoca-badge';
		return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Format amount as EUR string.
	 */
	/**
	 * Nombre legible de un origen de pago, para pantallas y correos.
	 *
	 * Fuente única: el listado de pagos y los avisos por correo usan la misma.
	 * Antes los correos enseñaban el nombre interno (`members_cuota`).
	 *
	 * @param string $origin Origen guardado en el pago.
	 * @return string
	 */
	/**
	 * Nombre legible de un método de pago, para pantallas, correos y recibos.
	 *
	 * @param string $method Método guardado en el pago (tarjeta, bizum, transferencia…).
	 * @return string
	 */
	public static function method_label( string $method ): string {
		return match ( $method ) {
			'tarjeta'       => __( 'Tarjeta', 'convoca-gateway' ),
			'bizum'         => __( 'Bizum', 'convoca-gateway' ),
			'transferencia' => __( 'Transferencia', 'convoca-gateway' ),
			'any'           => __( 'Cualquiera', 'convoca-gateway' ),
			default         => '' !== $method ? ucfirst( $method ) : '',
		};
	}

	public static function origin_label( string $origin ): string {
		return match ( $origin ) {
			'enroll'   => __( 'Inscripción', 'convoca-gateway' ),
			'members'  => __( 'Socio/a', 'convoca-gateway' ),
			'manual'   => __( 'Formulario web', 'convoca-gateway' ),
			'enlace'   => __( 'Cobro con enlace', 'convoca-gateway' ),
			'donativo' => __( 'Donación', 'convoca-gateway' ),
			default    => $origin,
		};
	}

	public static function format_amount( int $cents ): string {
		return number_format( $cents / 100, 2, ',', '.' ) . ' €';
	}

	/**
	 * Create a payment from the link generator.
	 *
	 * @param array $data {
	 *     @type float  $amount     Amount in euros.
	 *     @type string $concepto   Payment description.
	 *     @type string $method     Payment method (tarjeta|bizum|transferencia|any).
	 *     @type string $email      Recipient email.
	 *     @type string $params     Custom parameters (key=value per line).
	 *     @type string $expires_at Expiration date (Y-m-d).
	 * }
	 * @return int|\WP_Error Payment post ID or error.
	 */
	public static function create_link_payment( array $data ): int|\WP_Error {
		$amount_cents = (int) round( ( $data['amount'] ?? 0 ) * 100 );
		$open_amount  = ! empty( $data['open_amount'] );

		// Enlace de donativo: nace sin importe, porque lo decide quien aporta en la
		// página pública. Es el único caso en que no se exige el mínimo.
		if ( ! $open_amount && $amount_cents < 50 ) {
			return new \WP_Error( 'invalid_amount', __( 'El importe mínimo es 0.50€', 'convoca-gateway' ) );
		}

		$order_id = Redsys_Client::generate_order_id();

		$title = $open_amount
			? sprintf(
				'%s — %s — Enlace de donativo (importe libre)',
				$order_id,
				sanitize_text_field( $data['concepto'] ?? __( 'Donativo', 'convoca-gateway' ) )
			)
			: sprintf(
				'%s — %s€ — Enlace de pago',
				$order_id,
				number_format( $amount_cents / 100, 2, ',', '.' )
			);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'pago',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		if ( ! $post_id ) {
			// wp_insert_post devuelve 0 en error (no WP_Error en WP moderno).
			return new \WP_Error( 'insert_failed', __( 'No se pudo crear el registro de pago.', 'convoca-gateway' ) );
		}

		$meta = array(
			'order_id'         => $order_id,
			'amount_cents'     => $amount_cents,
			'currency'         => Redsys_Client::CURRENCY_EUR,
			'method'           => sanitize_text_field( $data['method'] ?? Redsys_Client::default_method() ),
			'status'           => 'pending',
			'origin'           => sanitize_text_field( $data['origin'] ?? 'link_payment' ),
			'origin_id'        => (int) ( $data['origin_id'] ?? 0 ),
			'open_amount'      => $open_amount ? '1' : '',
			'product_desc'     => sanitize_text_field( $data['concepto'] ?? '' ),
			'redsys_response'  => '',
			'redsys_auth_code' => '',
			'created_at'       => current_time( 'mysql' ),
			'created_ts'       => time(),
			'paid_at'          => '',
		);

		foreach ( $meta as $key => $val ) {
			update_post_meta( $post_id, '_convoca_' . $key, $val );
		}

		$expires_at = $data['expires_at'] ?? '';
		if ( $expires_at === 'never' ) {
			$expires_ts = 0;
		} else {
			// Default validity is configurable (convoca_gateway_link_expiry_days, 7 by default).
			$expires_ts = ! empty( $expires_at )
				? strtotime( $expires_at . ' 23:59:59' )
				: Link_Expiry::compute_expiry_timestamp( Link_Expiry::default_expiry_days() );
		}

		$token = self::generate_link_token( $post_id, (int) $expires_ts );

		update_post_meta( $post_id, '_convoca_link_key', $token );
		update_post_meta( $post_id, '_convoca_expires_at', $expires_ts );
		update_post_meta( $post_id, '_convoca_recipient_email', sanitize_email( $data['email'] ?? '' ) );
		update_post_meta( $post_id, '_convoca_link_generated_by', get_current_user_id() );

		// Email de quien paga (para el recibo) y, en donativos, orden de enviarlo
		// aunque el aviso general de confirmaciones esté apagado.
		$payer_email = sanitize_email( $data['payer_email'] ?? '' );
		if ( '' !== $payer_email ) {
			update_post_meta( $post_id, '_convoca_payer_email', $payer_email );
		}
		update_post_meta( $post_id, '_convoca_receipt_always', ! empty( $data['receipt_always'] ) ? '1' : '' );

		// Marca de donativo: activa la serie de numeración y el texto legal del recibo.
		if ( ! empty( $data['es_donacion'] ) ) {
			update_post_meta( $post_id, '_convoca_es_donacion', '1' );
		}

		$params = array();
		if ( ! empty( $data['params'] ) ) {
			foreach ( explode( "\n", $data['params'] ) as $line ) {
				$line = trim( $line );
				if ( strpos( $line, '=' ) !== false ) {
					[$key, $value]          = explode( '=', $line, 2 );
					$params[ trim( $key ) ] = trim( $value );
				}
			}
		}
		update_post_meta( $post_id, '_convoca_params', $params );

		$admin_user = wp_get_current_user();
		$admin_name = $admin_user->display_name ?? $admin_user->user_login ?? 'Admin';

		$notas = sprintf(
			"Enlace de pago generado por %s.\nConcepto: %s\nMétodo sugerido: %s\nCaduca: %s",
			$admin_name,
			$data['concepto'],
			$data['method'] ?? 'any',
			$expires_ts ? wp_date( 'd/m/Y H:i', $expires_ts ) : __( 'Nunca', 'convoca-gateway' )
		);
		update_post_meta( $post_id, '_convoca_notes', $notas );

		\Convoca\Core\Logger::info(
			"Enlace de pago generado: ID $post_id, Importe: {$data['amount']}€, Concepto: {$data['concepto']}",
			'Gateway/LinkGenerator',
			$post_id
		);

		return $post_id;
	}

	/**
	 * Cobros emitidos por un enlace: los pagos que apuntan a él.
	 *
	 * Un enlace es una plantilla; cada uso deja su propio registro de pago con
	 * `origin_id` apuntando al enlace. Esto es lo que cuenta «cuántas veces se ha
	 * usado», en lugar del estado del propio enlace (que no se gasta).
	 *
	 * @param int $enlace_id ID del enlace.
	 * @return int Número de cobros emitidos.
	 */
	public static function cobros_emitidos( int $enlace_id ): int {
		if ( $enlace_id <= 0 ) {
			return 0;
		}

		$pagos = get_posts(
			array(
				'post_type'   => 'pago',
				'numberposts' => -1,
				'fields'      => 'ids',
				'post_status' => 'any',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- consulta acotada por un meta concreto.
					array(
						'key'   => '_convoca_origin_id',
						'value' => (string) $enlace_id,
					),
				),
			)
		);

		return count( $pagos );
	}

	/**
	 * Build a payment link URL.
	 *
	 * @param int    $pago_id    Payment post ID.
	 * @param string $token      Payment link token.
	 * @param int    $expires_ts Optional. Expiration timestamp (not used in URL for security, kept for signature compatibility).
	 * @return string Full payment URL.
	 */
	public static function build_payment_link( int $pago_id, string $token, ?int $expires_ts = null ): string {
		$payment_page_id = get_option( 'convoca_gateway_payment_page_id', 0 );
		if ( $payment_page_id ) {
			$base_url = get_permalink( $payment_page_id );
		}

		if ( empty( $base_url ) ) {
			$base_url = home_url( '/pago' );
		}

		return add_query_arg(
			array(
				'convoca_gateway_pago' => $pago_id,
				'convoca_gateway_key'  => $token,
			),
			$base_url
		);
	}
}
