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
	// WordPress guarda el evento: el arnés lo apunta para poder afirmar qué cron queda programado.
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		$GLOBALS['__gw_cron_programados'][] = array( (string) $hook, (string) $recurrence );
		return true;
	}

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
	function apply_filters( $tag, $value, ...$args ) {
		if ( empty( $GLOBALS['__gw_filtros'][ $tag ] ) ) {
			return $value;
		}
		$registrados = $GLOBALS['__gw_filtros'][ $tag ];
		ksort( $registrados );
		foreach ( $registrados as $lista ) {
			foreach ( (array) $lista as $callback ) {
				$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
			}
		}
		return $value;
	}
	function __return_true() { return true; }
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
		// La versión sale de la cabecera real: clavarla a mano hace que cada subida de versión ponga la suite en
		// rojo por un dato del arnés y no por el producto.
		$convoca_gateway_cabecera = (string) file_get_contents( dirname( __DIR__ ) . '/convoca-gateway.php' );
		preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', $convoca_gateway_cabecera, $convoca_gateway_m );
		define( 'CONVOCA_GATEWAY_VERSION', $convoca_gateway_m[1] ?? '0.0.0' );
		unset( $convoca_gateway_cabecera, $convoca_gateway_m );
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

namespace {

	// ── Dobles del arranque del plugin ───────────────────────────────
	// El fichero principal y el desinstalador estaban FUERA del <source> hasta el 24-10:
	// sus 160 statements no se medían. Para medirlos hacen falta estas funciones de
	// WordPress, que el arnés no tenía porque ninguna prueba cargaba el arranque.

	if ( ! function_exists( 'get_file_data' ) ) {
		/**
		 * Cabecera del plugin, como WordPress (aquí, la versión del fichero real).
		 *
		 * @param string $file    Fichero.
		 * @param array  $headers Cabeceras pedidas.
		 * @param string $context Contexto.
		 * @return array<string, string>
		 */
		function get_file_data( $file, $headers = array(), $context = '' ) {
			unset( $file, $context );
			$salida = array();
			foreach ( array_keys( (array) $headers ) as $clave ) {
				$salida[ $clave ] = $GLOBALS['__gw_file_data'][ $clave ] ?? '';
			}
			return $salida;
		}
	}

	if ( ! function_exists( 'is_admin' ) ) {
		/**
		 * Si la petición es del panel. La prueba lo fija con `__gw_is_admin`.
		 *
		 * @return bool
		 */
		function is_admin() {
			return (bool) ( $GLOBALS['__gw_is_admin'] ?? false );
		}
	}

	if ( ! function_exists( 'plugin_dir_path' ) ) {
		/**
		 * Ruta del plugin.
		 *
		 * @param string $file Fichero.
		 * @return string
		 */
		function plugin_dir_path( $file ) {
			return rtrim( dirname( (string) $file ), '/' ) . '/';
		}
	}

	if ( ! function_exists( 'plugin_dir_url' ) ) {
		/**
		 * URL del plugin.
		 *
		 * @param string $file Fichero.
		 * @return string
		 */
		function plugin_dir_url( $file ) {
			unset( $file );
			return 'https://example.com/wp-content/plugins/convoca-gateway/';
		}
	}

	if ( ! function_exists( 'plugin_basename' ) ) {
		/**
		 * Nombre del plugin relativo a plugins/.
		 *
		 * @param string $file Fichero.
		 * @return string
		 */
		function plugin_basename( $file ) {
			return 'convoca-gateway/' . basename( (string) $file );
		}
	}

	if ( ! function_exists( 'wp_set_script_translations' ) ) {
		/**
		 * Traducciones de los scripts: se apunta la llamada.
		 *
		 * @param string $handle Nombre del script.
		 * @param string $domain Dominio.
		 * @param string $path   Ruta.
		 * @return bool
		 */
		function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) {
			$GLOBALS['__gw_traducciones_scripts'][] = array( $handle, $domain, $path );
			return true;
		}
	}

	if ( ! function_exists( 'load_plugin_textdomain' ) ) {
		/**
		 * Carga del textdomain: se apunta la llamada.
		 *
		 * @param string $domain Dominio.
		 * @param bool   $deprecated Sin uso.
		 * @param string $path   Ruta.
		 * @return bool
		 */
		function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {
			unset( $deprecated );
			$GLOBALS['__gw_textdomains'][] = array( $domain, $path );
			return true;
		}
	}

	if ( ! function_exists( 'register_activation_hook' ) ) {
		/**
		 * Engancha la activación y la apunta.
		 *
		 * @param string   $file     Fichero del plugin.
		 * @param callable $callback Devolución de llamada.
		 * @return void
		 */
		function register_activation_hook( $file, $callback ) {
			unset( $file );
			$GLOBALS['__gw_activacion'] = $callback;
		}
	}

	if ( ! function_exists( 'register_deactivation_hook' ) ) {
		/**
		 * Engancha la desactivación y la apunta.
		 *
		 * @param string   $file     Fichero del plugin.
		 * @param callable $callback Devolución de llamada.
		 * @return void
		 */
		function register_deactivation_hook( $file, $callback ) {
			unset( $file );
			$GLOBALS['__gw_desactivacion'] = $callback;
		}
	}

	if ( ! function_exists( 'register_rest_route' ) ) {
		/**
		 * Registra una ruta REST y guarda sus argumentos.
		 *
		 * @param string $namespace Espacio de nombres.
		 * @param string $route     Ruta.
		 * @param array  $args      Argumentos.
		 * @return bool
		 */
		function register_rest_route( $namespace, $route, $args = array() ) {
			$GLOBALS['__gw_rutas'][ $namespace . $route ] = $args;
			return true;
		}
	}

	if ( ! function_exists( 'gw_reset_settings_cache' ) ) {
		/**
		 * Vacía la caché estática de ajustes de `Redsys_Client`.
		 *
		 * `Redsys_Client::settings()` cachea `convoca_gateway_settings` en `private static ?array $settings` y no la
		 * invalida nunca. En producción es correcto —un proceso PHP atiende una petición y los ajustes se leen una
		 * vez— pero en las pruebas el proceso es el mismo para toda la suite: el primer caso que lee los ajustes deja
		 * el valor cacheado para los demás, y de ahí el `merchant_mismatch` de `PaymentConfirmationTest` según qué
		 * hubiera corrido antes (gateway#12).
		 *
		 * Se hace por REFLEXIÓN y no añadiendo un método al producto: la caché no está mal, lo que falta es poder
		 * reiniciarla desde una prueba. Si el producto necesitara invalidarla de verdad, sería otro cambio y otro
		 * issue.
		 *
		 * @return void
		 */
		function gw_reset_settings_cache(): void {
			if ( ! class_exists( '\\Convoca\\Gateway\\Redsys_Client' ) ) {
				return;
			}

			$prop = new ReflectionProperty( '\\Convoca\\Gateway\\Redsys_Client', 'settings' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
	}

	if ( ! function_exists( 'flush_rewrite_rules' ) ) {
		/**
		 * Refresco de reglas: se apunta.
		 *
		 * @return void
		 */
		function flush_rewrite_rules() {
			$GLOBALS['__gw_rewrites'] = ( $GLOBALS['__gw_rewrites'] ?? 0 ) + 1;
		}
	}

	if ( ! function_exists( 'add_option' ) ) {
		/**
		 * Añade una opción si no existe (como WordPress).
		 *
		 * @param string $key     Clave.
		 * @param mixed  $value   Valor.
		 * @param string $deprecated Sin uso.
		 * @param mixed  $autoload Autocarga.
		 * @return bool
		 */
		function add_option( $key, $value = '', $deprecated = '', $autoload = null ) {
			unset( $deprecated, $autoload );
			if ( array_key_exists( $key, $GLOBALS['__gw_options'] ) ) {
				return false;
			}
			$GLOBALS['__gw_options'][ $key ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
		/**
		 * Limpia un cron y lo apunta.
		 *
		 * @param string $hook Gancho.
		 * @param array  $args Argumentos.
		 * @return int
		 */
		function wp_clear_scheduled_hook( $hook, $args = array() ) {
			unset( $args );
			$GLOBALS['__gw_cron_limpiados'][] = $hook;
			return 1;
		}
	}

	if ( ! class_exists( 'WP_REST_Request' ) ) {
		/**
		 * Petición REST mínima: parámetros y cuerpo.
		 */
		class WP_REST_Request {

			/**
			 * Parámetros de la petición.
			 *
			 * @var array<string, mixed>
			 */
			public $params = array();

			/**
			 * Cuerpo crudo.
			 *
			 * @var string
			 */
			public $body = '';

			/**
			 * Construye la petición.
			 *
			 * @param array  $params Parámetros.
			 * @param string $body   Cuerpo.
			 */
			public function __construct( $params = array(), $body = '' ) {
				$this->params = (array) $params;
				$this->body   = (string) $body;
			}

			/**
			 * Parámetros de la petición.
			 *
			 * @return array<string, mixed>
			 */
			public function get_params() {
				return $this->params;
			}

			/**
			 * Cuerpo crudo.
			 *
			 * @return string
			 */
			public function get_body() {
				return $this->body;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response' ) ) {
		/**
		 * Respuesta REST mínima: datos y estado.
		 */
		class WP_REST_Response {

			/**
			 * Datos de la respuesta.
			 *
			 * @var mixed
			 */
			public $data;

			/**
			 * Código de estado.
			 *
			 * @var int
			 */
			public $status;

			/**
			 * Construye la respuesta.
			 *
			 * @param mixed $data   Datos.
			 * @param int   $status Estado.
			 */
			public function __construct( $data = null, $status = 200 ) {
				$this->data   = $data;
				$this->status = (int) $status;
			}

			/**
			 * Datos.
			 *
			 * @return mixed
			 */
			public function get_data() {
				return $this->data;
			}

			/**
			 * Estado.
			 *
			 * @return int
			 */
			public function get_status() {
				return $this->status;
			}
		}
	}

	if ( ! function_exists( '__return_true' ) ) {
		/**
		 * Devuelve true (lo usa WordPress para permisos abiertos).
		 *
		 * @return bool
		 */
		function __return_true() {
			return true;
		}
	}
}

namespace Convoca\Core {

	// El gateway hereda del gestor de actualizaciones del núcleo, que estos tests unitarios no cargan.
	// El doble copia el CONTRATO real (init/maybe_upgrade/force_version_check y las cuatro consultas
	// abstractas): antes ni existía, así que el subclase del gateway no se podía ni construir y sus
	// migraciones no se medían.
	if ( ! class_exists( 'Convoca\Core\Upgrade_Manager' ) ) {

		/**
		 * Gestor de actualizaciones (doble fiel del núcleo).
		 */
		abstract class Upgrade_Manager {

			/**
			 * Engancha la comprobación de versión.
			 *
			 * @return void
			 */
			public function init(): void {
				add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
				add_action( 'upgrader_process_complete', array( $this, 'force_version_check' ), 10, 2 );
			}

			/**
			 * Comprueba si toca migrar y ejecuta lo que falte.
			 *
			 * @return void
			 */
			public function maybe_upgrade(): void {
				$guardada = $this->get_saved_version();
				$actual   = $this->get_db_version();

				if ( version_compare( $guardada, $actual, '>=' ) ) {
					return;
				}

				foreach ( $this->get_upgrade_callbacks() as $version => $callback ) {
					if ( version_compare( (string) $version, $guardada, '>' ) && version_compare( (string) $version, $actual, '<=' ) ) {
						call_user_func( $callback );
					}
				}

				update_option( $this->get_option_name(), $actual );
			}

			/**
			 * Fuerza la comprobación (tras actualizar el plugin).
			 *
			 * @param mixed ...$args Argumentos del gancho.
			 * @return void
			 */
			public function force_version_check( ...$args ): void {
				unset( $args );
				$this->maybe_upgrade();
			}

			/**
			 * Versión guardada en la base.
			 *
			 * @return string
			 */
			protected function get_saved_version(): string {
				return (string) get_option( $this->get_option_name(), '0.0.0' );
			}

			/**
			 * Versión de la base que pide el plugin.
			 *
			 * @return string
			 */
			abstract protected function get_db_version(): string;

			/**
			 * Clave de la opción donde vive la versión.
			 *
			 * @return string
			 */
			abstract protected function get_option_name(): string;

			/**
			 * Prefijo de los transients del gestor.
			 *
			 * @return string
			 */
			abstract protected function get_transient_prefix(): string;

			/**
			 * Migraciones por versión.
			 *
			 * @return array<string, callable>
			 */
			abstract protected function get_upgrade_callbacks(): array;
		}
	}
}

namespace {

	if ( ! function_exists( 'register_post_type' ) ) {
		/**
		 * Registra un tipo de contenido y apunta lo registrado.
		 *
		 * @param string $tipo Tipo.
		 * @param array  $args Argumentos.
		 * @return object
		 */
		function register_post_type( $tipo, $args = array() ) {
			$GLOBALS['__gw_post_types'][ $tipo ] = (array) $args;
			return (object) array( 'name' => $tipo );
		}
	}

	if ( ! function_exists( 'register_taxonomy' ) ) {
		/**
		 * Registra una taxonomía y apunta lo registrado.
		 *
		 * @param string $taxonomia Taxonomía.
		 * @param array  $tipos     Tipos de contenido.
		 * @param array  $args      Argumentos.
		 * @return object
		 */
		function register_taxonomy( $taxonomia, $tipos = array(), $args = array() ) {
			$GLOBALS['__gw_taxonomias'][ $taxonomia ] = array( $tipos, (array) $args );
			return (object) array( 'name' => $taxonomia );
		}
	}

	if ( ! function_exists( 'remove_filter' ) ) {
		/**
		 * Quita un filtro (doble).
		 *
		 * @param string   $hook     Hook.
		 * @param callable $callback Callback.
		 * @param int      $priority Prioridad.
		 * @return bool
		 */
		function remove_filter( $hook, $callback, $priority = 10 ) {
			$prioridad = (int) $priority;

			if ( empty( $GLOBALS['__gw_filtros'][ $hook ][ $prioridad ] ) ) {
				return false;
			}

			foreach ( $GLOBALS['__gw_filtros'][ $hook ][ $prioridad ] as $i => $cb ) {
				if ( $cb === $callback ) {
					unset( $GLOBALS['__gw_filtros'][ $hook ][ $prioridad ][ $i ] );
					return true;
				}
			}

			return false;
		}
	}
}
