<?php
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
		'order_id',           // Redsys order ID (12 chars)
		'amount_cents',       // Amount in cents (integer)
		'currency',           // ISO 4217 numeric (978 = EUR)
		'method',             // tarjeta | bizum
		'status',             // pending | paid | failed | refunded
		'origin',             // enroll | members
		'origin_id',          // Post ID of inscription/miembro
		'redsys_response',    // Full Redsys response code
		'redsys_auth_code',   // Authorisation code from Redsys
		'product_desc',       // Description shown on bank statement
		'created_at',         // Timestamp of creation
		'paid_at',            // Timestamp of successful payment
		// Link payment fields
		'link_key',           // Token for payment link validation
		'expires_at',         // Link expiration timestamp
		'recipient_email',    // Email for payment link
		'params',             // Custom parameters (serialized)
		'link_generated_by',  // Admin user ID who generated the link
		// Recurring payment fields
		'redsys_merchant_id', // Tokenized card identifier for recurring payments
		'proof_file',         // ID or URL of uploaded payment receipt
	);

	/** Status labels. */
	public const STATUS = array(
		'pending'  => 'Pendiente',
		'paid'     => 'Pagado',
		'failed'   => 'Fallido',
		'refunded' => 'Reembolsado',
	);

	/** Status badge classes. */
	public const BADGE = array(
		'pending'  => 'biodevas-badge biodevas-badge--warning',
		'paid'     => 'biodevas-badge biodevas-badge--success',
		'failed'   => 'biodevas-badge biodevas-badge--danger',
		'refunded' => 'biodevas-badge biodevas-badge--info',
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
				wp_safe_redirect( admin_url( 'admin.php?page=bdg-payments-detail&id=' . $post_id ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=bdg-generador' ) );
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

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$meta = array(
			'order_id'         => $order_id,
			'amount_cents'     => $amount,
			'currency'         => Redsys_Client::CURRENCY_EUR,
			'method'           => sanitize_text_field( $data['method'] ?? 'tarjeta' ),
			'status'           => 'pending',
			'origin'           => sanitize_text_field( $data['origin'] ?? '' ),
			'origin_id'        => (int) ( $data['origin_id'] ?? 0 ),
			'product_desc'     => sanitize_text_field( $data['product_desc'] ?? '' ),
			'redsys_response'  => '',
			'redsys_auth_code' => '',
			'created_at'       => current_time( 'mysql' ),
			'paid_at'          => '',
		);

		foreach ( $meta as $key => $val ) {
			update_post_meta( $post_id, '_bdg_' . $key, $val );
		}

		return $post_id;
	}

	/**
	 * Get meta data for a payment.
	 */
	public static function get_meta( int $post_id ): array {
		$data = array();
		foreach ( self::META_KEYS as $key ) {
			$data[ $key ] = get_post_meta( $post_id, '_bdg_' . $key, true );
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
						'key'   => '_bdg_order_id',
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
             WHERE pm.meta_key = '_bdg_order_id' AND pm.meta_value = %s 
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
		$label = self::STATUS[ $status ] ?? $status;
		$class = self::BADGE[ $status ] ?? 'biodevas-badge';
		return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Format amount as EUR string.
	 */
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

		if ( $amount_cents < 50 ) {
			return new \WP_Error( 'invalid_amount', __( 'El importe mínimo es 0.50€', 'convoca-gateway' ) );
		}

		$order_id = Redsys_Client::generate_order_id();

		$title = sprintf(
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

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$meta = array(
			'order_id'         => $order_id,
			'amount_cents'     => $amount_cents,
			'currency'         => Redsys_Client::CURRENCY_EUR,
			'method'           => sanitize_text_field( $data['method'] ?? 'tarjeta' ),
			'status'           => 'pending',
			'origin'           => 'link_payment',
			'origin_id'        => 0,
			'product_desc'     => sanitize_text_field( $data['concepto'] ?? '' ),
			'redsys_response'  => '',
			'redsys_auth_code' => '',
			'created_at'       => current_time( 'mysql' ),
			'paid_at'          => '',
		);

		foreach ( $meta as $key => $val ) {
			update_post_meta( $post_id, '_bdg_' . $key, $val );
		}

		$expires_at = $data['expires_at'] ?? '';
		if ( $expires_at === 'never' ) {
			$expires_ts = 0;
		} else {
			$expires_ts = ! empty( $expires_at )
				? strtotime( $data['expires_at'] . ' 23:59:59' )
				: strtotime( '+7 days 23:59:59' );
		}

		// Use a persistent salt for payment links to prevent them from becoming invalid if WP_SALT changes.
		$persistent_salt = get_option( 'bdg_persistent_salt' );
		if ( ! $persistent_salt ) {
			$persistent_salt = wp_generate_password( 64, true, true );
			update_option( 'bdg_persistent_salt', $persistent_salt );
		}

		$token = hash_hmac( 'sha256', $post_id . '|' . $expires_ts, $persistent_salt );

		update_post_meta( $post_id, '_bdg_link_key', $token );
		update_post_meta( $post_id, '_bdg_expires_at', $expires_ts );
		update_post_meta( $post_id, '_bdg_recipient_email', sanitize_email( $data['email'] ?? '' ) );
		update_post_meta( $post_id, '_bdg_link_generated_by', get_current_user_id() );

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
		update_post_meta( $post_id, '_bdg_params', $params );

		$admin_user = wp_get_current_user();
		$admin_name = $admin_user->display_name ?? $admin_user->user_login ?? 'Admin';

		$notas = sprintf(
			"Enlace de pago generado por %s.\nConcepto: %s\nMétodo sugerido: %s\nCaduca: %s",
			$admin_name,
			$data['concepto'],
			$data['method'] ?? 'any',
			$expires_ts ? wp_date( 'd/m/Y H:i', $expires_ts ) : __( 'Nunca', 'convoca-gateway' )
		);
		update_post_meta( $post_id, '_bdg_notes', $notas );

		\Convoca\Core\Logger::info(
			"Enlace de pago generado: ID $post_id, Importe: {$data['amount']}€, Concepto: {$data['concepto']}",
			'Gateway/LinkGenerator',
			$post_id
		);

		return $post_id;
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
		$payment_page_id = get_option( 'bdg_payment_page_id', 0 );
		if ( $payment_page_id ) {
			$base_url = get_permalink( $payment_page_id );
		}

		if ( empty( $base_url ) ) {
			$base_url = home_url( '/pago' );
		}

		return add_query_arg(
			array(
				'bdg_pago' => $pago_id,
				'bdg_key'  => $token,
			),
			$base_url
		);
	}
}
