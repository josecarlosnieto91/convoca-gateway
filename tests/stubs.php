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
		// Las tablas que el codigo del plugin interpola de verdad (si faltan, PHP avisa
		// de "Undefined property" y el aviso se pierde entre el verde del CI).
		public $posts     = 'wp_posts';
		public $postmeta  = 'wp_postmeta';
		public $options   = 'wp_options';
		public function get_var( $query ) { return $GLOBALS['__gw_get_var'] ?? null; }
		public function get_results( $query ) { return $GLOBALS['__gw_results'] ?? array(); }
		public function get_col( $query ) { return $GLOBALS['__gw_get_col'] ?? array(); }
		public function query( $query ) { $GLOBALS['__gw_sql'][] = $query; return true; }
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
		public function __construct( $args = array() ) {
			$GLOBALS['__gw_list_table_args'][] = $args;
		}
		public function row_actions( $actions, $always = false ) {
			return '<span class="row-actions">' . implode( ' | ', $actions ) . '</span>';
		}
		public function get_pagenum() {
			return 1;
		}
		public function set_pagination_args( $args ) {
			$GLOBALS['__gw_paginacion'] = $args;
		}
		/** Caja de búsqueda: se apunta el texto para poder comprobar que el listado la pinta. */
		public function search_box( $text, $input_id ) {
			$GLOBALS['__gw_busquedas'][] = $text;
			echo '<input type="search" name="s" id="' . $input_id . '" value="">';
		}
		/** Render del listado: recorre las filas como hace el original. */
		public function display() {
			if ( method_exists( $this, 'get_columns' ) ) {
				$GLOBALS['__gw_columnas_pintadas'] = $this->get_columns();
			}
			echo '<table class="wp-list-table"><tbody>';
			foreach ( $this->items as $item ) {
				echo '<tr><td>' . ( is_object( $item ) ? $item->ID : (int) $item ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
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

	/**
	* Página de privacidad: el doble devuelve la URL de la que esté configurada como tal, igual que
	* WordPress. Sin página, cadena vacía — que es el caso que el diagnóstico tiene que avisar.
	*/
	function get_privacy_policy_url() {
	$id = (int) get_option( 'wp_page_for_privacy_policy' );
	return $id > 0 ? '/?page_id=' . $id : '';
	}
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
	/**
	 * Doble de `add_query_arg()` que soporta SUS DOS FORMAS.
	 *
	 * Antes solo entendía la de dos argumentos —`add_query_arg( array $args, $url )`— y con la de tres
	 * (`add_query_arg( $clave, $valor, $url )`, que usa medio plugin) devolvía el **valor** en lugar de la
	 * URL: `'tarjeta'` donde tenía que ir el enlace con su parámetro. Se destapó al añadir el método
	 * elegido a la URL de pago (gateway#9): una prueba empezó a comparar contra `'tarjeta'`. Y explica por
	 * qué otras rutas con la forma de tres argumentos tenían pruebas que no comprobaban la URL de verdad.
	 *
	 * @param string|array $args  Clave, o el arreglo de parámetros.
	 * @param string       $value Valor (forma de tres argumentos) o la URL (forma de dos).
	 * @param string       $url   URL (solo en la forma de tres argumentos).
	 * @return string
	 */
	function add_query_arg( $args, $value = '', $url = '' ) {
		if ( is_array( $args ) ) {
			$url      = (string) $value;
			$argumentos = $args;
		} else {
			$url        = (string) $url;
			$argumentos = array( (string) $args => $value );
		}

		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $argumentos );
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
		// Si la prueba ha puesto el post en el almacén, se devuelve **tal cual**: con su estado y su contenido. Antes
		// solo se miraba `__gw_meta`/`__gw_inserted` y una página en borrador o sin shortcode era indistinguible.
		if ( isset( $GLOBALS['_wp_stores']['posts'][ $id ] ) ) {
			return $GLOBALS['_wp_stores']['posts'][ $id ];
		}
		$existe = isset( $GLOBALS['__gw_meta'][ $id ] ) || isset( $GLOBALS['__gw_inserted'][ $id ] );
		return $existe ? new WP_Post( $id ) : null;
	}
	/**
	 * ¿El contenido lleva el shortcode? Como WordPress: busca `[tag` (admite atributos).
	 *
	 * @param string $content Contenido.
	 * @param string $tag     Shortcode.
	 * @return bool
	 */
	function has_shortcode( $content, $tag ) {
		return false !== strpos( (string) $content, '[' . $tag );
	}

	function get_post_type( $post = null ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['__gw_posts'][ $id ] ?? false;
	}
	function get_posts( $args = array() ) { return $GLOBALS['__gw_hijos'] ?? array(); }
	function post_type_exists( $tipo ) { return 'pago' === $tipo; }
	/**
	 * Busca una página por su slug, como WordPress: devuelve la que exista, sea cual sea su estado.
	 *
	 * @param string $slug Slug.
	 * @param string $output Sin usar.
	 * @param string $post_type Sin usar.
	 * @return object|null
	 */
	function get_page_by_path( $slug, $output = 'OBJECT', $post_type = 'page' ) {
		unset( $output, $post_type );
		foreach ( $GLOBALS['_wp_stores']['posts'] ?? array() as $post ) {
			if ( isset( $post->post_name ) && $post->post_name === $slug ) {
				return $post;
			}
		}
		return null;
	}

	function wp_insert_post( $data, $error = false ) {
		unset( $error );
		if ( ! empty( $GLOBALS['__gw_insert_falla'] ) ) {
			return 0;
		}
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
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['__gw_hooks'][] = array( $hook, $callback, (int) $priority );
		return true;
	}
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
		$GLOBALS['__gw_emails'][] = compact( 'to', 'subject', 'message' );
		return true;
	}
	function apply_filters( $tag, $value, ...$args ) { return $value; }
	// Registro de filtros: el gateway se registra en la fachada del núcleo (convoca_payment_*) y hay
	// que poder comprobar que los tres enganches están puestos sin un WordPress de verdad.
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['__gw_filtros'][ $hook ][ (int) $priority ][] = $callback;
		return true;
	}
	function has_filter( $hook, $callback = false ) {
		if ( empty( $GLOBALS['__gw_filtros'][ $hook ] ) ) {
			return false;
		}
		if ( false === $callback ) {
			return true;
		}
		foreach ( $GLOBALS['__gw_filtros'][ $hook ] as $callbacks ) {
			if ( in_array( $callback, $callbacks, true ) ) {
				return true;
			}
		}
		return false;
	}
	function get_theme_mod( $name, $default = false ) { return $default; }
	function wp_get_attachment_image_url( $id, $size = "thumbnail" ) { return ""; }
	function nocache_headers() { return true; }
	function status_header( $code ) { $GLOBALS["__gw_status"] = $code; return true; }
	// Por defecto concede el permiso (como antes); si una prueba escribe en
	// __gw_caps, manda lo que ella diga. Así se puede probar el caso sin permiso
	// sin cambiar lo que esperan las demás pruebas.
	function current_user_can( $cap, ...$args ) {
		if ( isset( $GLOBALS['__gw_caps'][ $cap ] ) ) {
			return (bool) $GLOBALS['__gw_caps'][ $cap ];
		}
		return true;
	}
	function wp_add_dashboard_widget( $id, $name, $cb, $control = null ) { $GLOBALS['__gw_widgets'][ $id ] = $cb; return true; }
	function get_edit_post_link( $id, $context = 'display' ) { return 'https://example.com/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
	function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
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
	// La hoja del front se encola desde la vista: se registra el handle para poder
	// comprobar en las pruebas QUE se encoló, no solo que no reviente.
	function wp_enqueue_style( ...$args ) {
		$GLOBALS['__gw_enqueued_styles'][] = $args[0] ?? '';
		return true;
	}
	function wp_register_style( ...$args ) {
		$GLOBALS['__gw_registered_styles'][ $args[0] ?? '' ] = $args[1] ?? '';
		return true;
	}
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
	/**
	 * Comprueba un nonce.
	 *
	 * Por defecto **acepta** (comportamiento histórico del arnés, del que dependen muchas pruebas). Una prueba que
	 * quiera medir el rechazo declara los válidos en `$GLOBALS['__gw_nonces']`; así el camino de «nonce inválido»
	 * deja de ser inalcanzable sin romper lo que ya había.
	 *
	 * @param string     $nonce  Nonce recibido.
	 * @param string|int $action Acción.
	 * @return int|false
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		unset( $action );
		$validos = $GLOBALS['__gw_nonces'] ?? null;
		if ( null === $validos ) {
			return 1;
		}
		return in_array( (string) $nonce, array_map( 'strval', (array) $validos ), true ) ? 1 : false;
	}
	function wp_nonce_field( $action, $name = '_wpnonce' ) {
		echo '<input type="hidden" name="' . $name . '">';
	}
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

	// --- Pantallas de administración (Admin_Payments / Admin_Links) ----------------------
	// `check_admin_referer` mata la petición en WordPress cuando el nonce no vale; aquí solo
	// devuelve lo que diga la prueba: por defecto, válido. Así se puede recorrer la pantalla
	// entera y, en otra prueba, el camino del nonce inválido.
	if ( ! function_exists( 'check_admin_referer' ) ) {
		function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
			return $GLOBALS['__gw_nonce_ok'] ?? true;
		}
	}
	if ( ! function_exists( 'check_ajax_referer' ) ) {
		/**
		 * Como `check_admin_referer`, pero para AJAX: corta si el nonce no vale.
		 *
		 * @param string|int $action Acción.
		 * @param string     $campo  Campo del nonce.
		 * @return true
		 */
		function check_ajax_referer( $action = -1, $campo = '_ajax_nonce' ) {
			unset( $action, $campo );
			if ( ! ( $GLOBALS['__gw_nonce_ok'] ?? true ) ) {
				throw new RuntimeException( 'wp_die: nonce inválido' );
			}
			return true;
		}
	}
	if ( ! function_exists( 'wp_send_json_error' ) ) {
		/**
		 * Respuesta AJAX de error: apunta y **corta**, como `wp_die` en WordPress.
		 *
		 * @param mixed $datos  Datos.
		 * @param int   $estado Estado HTTP.
		 * @return never
		 */
		function wp_send_json_error( $datos = null, $estado = null ) {
			$GLOBALS['__gw_json'][] = array( 'ok' => false, 'datos' => $datos, 'estado' => $estado );
			$mensaje                = is_array( $datos ) && isset( $datos['message'] ) ? (string) $datos['message'] : '';
			throw new RuntimeException( 'json_error: ' . $mensaje );
		}
	}
	if ( ! function_exists( 'wp_send_json_success' ) ) {
		/**
		 * Respuesta AJAX correcta: apunta y **corta** (en WordPress termina la petición).
		 *
		 * @param mixed $datos  Datos.
		 * @param int   $estado Estado HTTP.
		 * @return never
		 */
		function wp_send_json_success( $datos = null, $estado = null ) {
			$GLOBALS['__gw_json'][] = array( 'ok' => true, 'datos' => $datos, 'estado' => $estado );
			$mensaje                = is_array( $datos ) && isset( $datos['message'] ) ? (string) $datos['message'] : '';
			throw new RuntimeException( 'json_ok: ' . $mensaje );
		}
	}
	if ( ! function_exists( 'wp_create_nonce' ) ) {
		function wp_create_nonce( $action = -1 ) {
			return 'nonce-de-pruebas';
		}
	}
	if ( ! function_exists( 'wp_nonce_url' ) ) {
		function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
			return add_query_arg( $name, 'nonce-de-pruebas', (string) $actionurl );
		}
	}
	if ( ! function_exists( 'submit_button' ) ) {
		function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) {
			$GLOBALS['__gw_submit_buttons'][] = $text;
			echo '<button type="submit" name="' . $name . '">' . $text . '</button>';
		}
	}
	if ( ! function_exists( 'esc_js' ) ) {
		function esc_js( $text ) {
			return str_replace( "'", '&#039;', (string) $text );
		}
	}
	if ( ! function_exists( 'add_menu_page' ) ) {
		function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon = '', $position = null ) {
			$GLOBALS['__gw_menus'][ $menu_slug ] = compact( 'page_title', 'menu_title', 'capability', 'callback' );
			return 'hook-' . $menu_slug;
		}
	}
	if ( ! function_exists( 'add_submenu_page' ) ) {
		function add_submenu_page( $parent, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
			$GLOBALS['__gw_submenus'][ $menu_slug ] = compact( 'parent', 'page_title', 'capability', 'callback' );
			return 'hook-' . $menu_slug;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		// Se apunta lo lanzado: la pantalla de detalle avisa de un reenvío de correo y hay que
		// poder comprobar que el gancho salió, no solo que no reventó.
		function do_action( $hook, ...$args ) {
			$GLOBALS['__gw_actions'][] = array( $hook, $args );
			return null;
		}
	}
	if ( ! function_exists( 'wp_safe_redirect' ) ) {
		function wp_safe_redirect( $location, $status = 302 ) {
			$GLOBALS['__gw_redirect'] = $location;
			return true;
		}
	}
	// Los shortcodes del front se registran en el constructor del manejador de pagos: se apuntan
	// para poder comprobar que están puestos sin un WordPress de verdad.
	if ( ! function_exists( 'add_shortcode' ) ) {
		function add_shortcode( $tag, $callback ) {
			$GLOBALS['__gw_shortcodes'][ $tag ] = $callback;
			return true;
		}
	}
	if ( ! function_exists( 'get_current_screen' ) ) {
		function get_current_screen() {
			return null;
		}
	}
	// `WP_List_Table` pide `convert_to_screen()` para construir la pantalla; sin este doble,
	// el constructor intenta cargar wp-admin/includes/screen.php y la prueba se cae antes de
	// empezar (no hay WordPress en el runner).
	if ( ! function_exists( 'convert_to_screen' ) ) {
		function convert_to_screen( $hook_name = '' ) {
			return (object) array(
				'id'   => $hook_name,
				'base' => $hook_name,
			);
		}
	}
	if ( ! function_exists( 'number_format_i18n' ) ) {
		function number_format_i18n( $number, $decimals = 0 ) {
			return number_format( (float) $number, (int) $decimals, ',', '.' );
		}
	}
// --- Transients y caché de meta (los usa Dashboard_Widget) ---------------------------------
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		// Lo que la prueba haya dejado, o false (sin caché) como WordPress.
		return $GLOBALS['__gw_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['__gw_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__gw_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'update_meta_cache' ) ) {
	function update_meta_cache( $meta_type, $object_ids ) {
		// Sin base de datos que precargar: se apunta la llamada y ya.
		$GLOBALS['__gw_meta_cache'][] = array( $meta_type, $object_ids );
		return true;
	}
}

	// --- Bloques de Gutenberg (Block_Gateway) ------------------------------------------
	if ( ! defined( 'CONVOCA_GATEWAY_URL' ) ) {
		define( 'CONVOCA_GATEWAY_URL', 'https://example.test/wp-content/plugins/convoca-gateway/' );
	}
	if ( ! defined( 'CONVOCA_GATEWAY_VERSION' ) ) {
		define( 'CONVOCA_GATEWAY_VERSION', '2.13.22' );
	}
	if ( ! function_exists( 'wp_register_script' ) ) {
		function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
			$GLOBALS['__gw_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'footer' );
			return true;
		}
	}
	if ( ! function_exists( 'register_block_type' ) ) {
		function register_block_type( $name, $args = array() ) {
			// Se apunta con sus argumentos: sin render_callback un bloque sale vacío en el sitio.
			$GLOBALS['__gw_bloques'][ $name ] = $args;
			return true;
		}
	}

	// --- API de ajustes (Admin_Settings) -----------------------------------------------
	if ( ! function_exists( 'add_settings_error' ) ) {
		function add_settings_error( $setting, $code, $message, $type = 'error' ) {
			$GLOBALS['__gw_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
		}
	}
	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		function sanitize_textarea_field( $text ) {
			return trim( strip_tags( (string) $text ) );
		}
	}

	if ( ! function_exists( 'wp_salt' ) ) {
		// Clave de WordPress para cifrar la del TPV: en las pruebas, una fija.
		function wp_salt( $scheme = 'auth' ) {
			return 'salt-de-pruebas-suficientemente-largo-para-aes';
		}
	}

	// --- Registro de ajustes (Admin_Settings::register_settings) ------------------------
	if ( ! function_exists( 'register_setting' ) ) {
		function register_setting( $group, $name, $args = array() ) {
			$GLOBALS['__gw_registered_settings'][ $name ] = is_array( $args ) ? $args : array();
			return true;
		}
	}
	if ( ! function_exists( 'add_settings_section' ) ) {
		function add_settings_section( $id, $title, $callback, $page = '' ) {
			$GLOBALS['__gw_sections'][ $page ][ $id ] = compact( 'title', 'callback' );
			return true;
		}
	}
	if ( ! function_exists( 'add_settings_field' ) ) {
		function add_settings_field( $id, $title, $callback, $page = '', $section = '', $args = array() ) {
			$GLOBALS['__gw_fields'][ $id ] = array( 'title' => $title, 'callback' => $callback, 'page' => $page, 'section' => $section, 'args' => $args );
			return true;
		}
	}

	if ( ! function_exists( 'esc_textarea' ) ) {
		function esc_textarea( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}

	if ( ! function_exists( 'selected' ) ) {
		// Como WordPress: compara y devuelve el atributo ya montado.
		function selected( $a, $b = true, $echo = true ) {
			$r = ( (string) $a === (string) $b ) ? " selected='selected'" : '';
			if ( $echo ) {
				echo $r;
			}
			return $r;
		}
	}
}
