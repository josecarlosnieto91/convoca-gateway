<?php
/**
 * Dobles compartidos de WordPress para las pruebas unitarias del Gateway.
 *
 * Se carga UNA sola vez desde tests/bootstrap.php, siempre antes que los
 * ficheros de pruebas, de forma que cada fichero de tests/Unit puede
 * ejecutarse en solitario sin definir sus propios dobles.
 *
 * Regla: un solo sitio por doble. Los `function_exists`/`class_exists` de
 * seguridad no hacen falta aquí porque este fichero se incluye una única vez.
 */

namespace {

	// ── Almacenes en memoria del harness ──────────────────────────────
	if ( ! isset( $GLOBALS['__gw_meta'] ) ) {
		$GLOBALS['__gw_meta'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_posts'] ) ) {
		$GLOBALS['__gw_posts'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_inserted'] ) ) {
		$GLOBALS['__gw_inserted'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_emails'] ) ) {
		$GLOBALS['__gw_emails'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_hijos'] ) ) {
		$GLOBALS['__gw_hijos'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_deleted'] ) ) {
		$GLOBALS['__gw_deleted'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_options'] ) ) {
		$GLOBALS['__gw_options'] = array();
	}

	// ── Mock de $wpdb ─────────────────────────────────────────────────
	$GLOBALS['wpdb'] = new class {
		public $prefix = 'wp_';
		public function get_var( $query ) { return null; }
		public function get_results( $query ) { return array(); }
		public function query( $query ) { return true; }
		public function prepare( $query, ...$args ) { return $query; }
	};

	// ── Clases de WordPress ───────────────────────────────────────────
	class WP_Error {
		public $errors = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( $code ) { $this->errors[ $code ] = array( $message ); }
		}
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return empty( $codes ) ? '' : $codes[0];
		}
		public function get_error_message() {
			if ( empty( $this->errors ) ) { return ''; }
			$code = $this->get_error_code();
			return $this->errors[ $code ][0] ?? '';
		}
		public function add( $code, $message, $data = '' ) { $this->errors[ $code ][] = $message; }
	}

	class WP_Post {
		public $ID;
		public $post_type = 'pago';
		public function __construct( $id = 0 ) {
			$this->ID = (int) $id;
		}
	}

	class WP_List_Table {
		public $items = array();
		public function __construct( $args = array() ) {}
		public function row_actions( $actions, $always = false ) {
			return '<span class="row-actions">' . implode( ' | ', $actions ) . '</span>';
		}
		public function get_pagenum() {
			return 1;
		}
		public function set_pagination_args( $args ) {}
	}

	/** Guarda los argumentos de la consulta para poder comprobarlos. */
	class WP_Query {
		public static $ultimo = array();
		public $posts = array();
		public $found_posts = 0;
		public function __construct( $args = array() ) {
			self::$ultimo = $args;
		}
	}

	// ── Opciones y URL ────────────────────────────────────────────────
	function get_option( $key, $default = false ) {
		if ( isset( $GLOBALS['__gw_options'] ) && array_key_exists( $key, $GLOBALS['__gw_options'] ) ) {
			return $GLOBALS['__gw_options'][ $key ];
		}
		return $default;
	}
	function get_page_by_title( $title ) { return null; }
	function is_ssl() { return true; }
	function home_url( $path = '' ) { return "https://example.com$path"; }
	function __( $s, $domain ) { return $s; }
	function esc_html( $s ) { return $s; }
	function esc_attr( $s ) { return $s; }
	function esc_url( $s ) { return $s; }
	function admin_url( $path ) { return "/wp-admin/$path"; }
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__gw_options'][ $key ] = $value; return true; }
	function delete_option( $key ) { unset( $GLOBALS['__gw_options'][ $key ] ); return true; }
	function get_rest_url( $path = '', $scheme = 'rest' ) { return 'https://example.com/wp-json' . $path; }
	function set_url_scheme( $url, $scheme = null ) { return $url; }
	function get_permalink( $post = 0 ) { return 'https://example.com/pago/'; }
	function add_query_arg( $args, $url = '' ) {
		if ( ! is_array( $args ) ) {
			return $url;
		}
		return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
	}
	function remove_query_arg( $key, $url = '' ) {
		$parts = explode( '?', (string) $url, 2 );
		if ( count( $parts ) < 2 ) {
			return $url;
		}
		parse_str( $parts[1], $query );
		unset( $query[ $key ] );
		return empty( $query ) ? $parts[0] : $parts[0] . '?' . http_build_query( $query );
	}

	// ── Tiempo y cron ─────────────────────────────────────────────────
	function current_time( $format ) { return '2025-01-01 00:00:00'; }
	function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?? time() ); }
	function wp_next_scheduled( $hook, $args = array() ) { return false; }
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) { return true; }

	// ── Red / HTTP ────────────────────────────────────────────────────
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
	function wp_remote_post( $url, $args = array() ) { return new WP_Error( 'stub', 'no network in unit tests' ); }
	function wp_remote_retrieve_response_code( $response ) { return is_wp_error( $response ) ? 0 : 200; }
	function wp_remote_retrieve_body( $response ) { return is_wp_error( $response ) ? '' : ''; }
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

	// ── Posts y metadatos ─────────────────────────────────────────────
	function get_post( $post = null ) {
		$id = (int) $post;
		$existe = isset( $GLOBALS['__gw_meta'][ $id ] ) || isset( $GLOBALS['__gw_inserted'][ $id ] );
		return $existe ? new WP_Post( $id ) : null;
	}
	function get_post_type( $post = null ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['__gw_posts'][ $id ] ?? false;
	}
	function get_posts( $args = array() ) { return $GLOBALS['__gw_hijos'] ?? array(); }
	function post_type_exists( $tipo ) { return 'pago' === $tipo; }
	function wp_insert_post( $data, $error = false ) {
		$id = 9000 + count( $GLOBALS['__gw_inserted'] );
		$GLOBALS['__gw_inserted'][ $id ] = $data;
		return $id;
	}
	function wp_delete_post( $id, $force = false ) {
		$id = (int) $id;
		$GLOBALS['__gw_deleted'][] = array( 'id' => $id, 'force' => $force );
		unset( $GLOBALS['__gw_posts'][ $id ], $GLOBALS['__gw_meta'][ $id ] );
		return (object) array( 'ID' => $id );
	}
	function get_post_meta( $id, $key = '', $single = false ) {
		$vals = $GLOBALS['__gw_meta'][ (int) $id ][ $key ] ?? null;
		if ( $single ) {
			return $vals ?? '';
		}
		return null === $vals ? array() : ( is_array( $vals ) ? $vals : array( $vals ) );
	}
	function update_post_meta( $id, $key, $value ) { $GLOBALS['__gw_meta'][ (int) $id ][ $key ] = $value; return true; }
	function delete_post_meta( $id, $key ) { unset( $GLOBALS['__gw_meta'][ (int) $id ][ $key ] ); return true; }

	// ── Hooks y correo ────────────────────────────────────────────────
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$GLOBALS['__gw_emails'][] = compact( 'to', 'subject', 'message' );
		return true;
	}
	function apply_filters( $tag, $value, ...$args ) { return $value; }
	function get_theme_mod( $name, $default = false ) { return $default; }
	function wp_get_attachment_image_url( $id, $size = "thumbnail" ) { return ""; }
	function nocache_headers() { return true; }
	function status_header( $code ) { $GLOBALS["__gw_status"] = $code; return true; }
	function get_the_date( $format = '', $post = null ) { return '01/01/2026 12:00'; }
	function get_bloginfo( $show = 'name' ) { return 'Entidad de prueba'; }

	// ── Utilidades varias ─────────────────────────────────────────────
	function absint( $v ) { return abs( (int) $v ); }
	function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
	function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	function sanitize_email( $email ) { return filter_var( (string) $email, FILTER_SANITIZE_EMAIL ); }
	function is_email( $email ) { return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ); }
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function esc_html__( $text, $domain = null ) { return $text; }
	function esc_html_e( $text, $domain = null ) { echo $text; }
	function esc_attr__( $text, $domain = null ) { return $text; }
	function esc_attr_e( $text, $domain = null ) { echo $text; }
	function esc_url_raw( $url ) { return (string) $url; }
	function wp_kses_post( $s ) { return $s; }
	function wp_enqueue_script( ...$args ) { return true; }
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( str_repeat( 'a1B2c3D4e5F6g7H8', 8 ), 0, $length );
	}
	function get_current_user_id() { return 1; }
	function wp_get_current_user() {
		return (object) array(
			'ID'           => 1,
			'display_name' => 'Admin',
			'user_login'   => 'admin',
		);
	}
	function current_user_can( $cap, ...$args ) { return true; }
	function wp_verify_nonce( $nonce, $action = -1 ) { return 1; }
	function wp_nonce_field( $action, $name = '_wpnonce' ) {
		echo '<input type="hidden" name="' . $name . '">';
	}
	function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
	function checked( $checked, $current = true, $display = true ) {
		$r = ( $checked == $current ) ? " checked='checked'" : '';
		if ( $display ) {
			echo $r;
		}
		return $r;
	}
	function wp_die( $msg ) {
		throw new RuntimeException( 'wp_die: ' . ( is_scalar( $msg ) ? $msg : '?' ) );
	}
}
