<?php
/**
 * Flujo de pago en dos pantallas: primero el método, después los datos.
 *
 * Se comprueba el comportamiento real de los métodos de render del handler
 * (por reflexión, con los stubs del bootstrap) para que el rediseño no se
 * deshaga solo: el importe no debe aparecer hasta que hay método elegido y
 * las tarjetas deben ser las mismas en todos los flujos.
 */

namespace {
	if ( ! function_exists( 'remove_query_arg' ) ) {
		function remove_query_arg( $key, $url = '' ) {
			$parts = explode( '?', (string) $url, 2 );
			if ( count( $parts ) < 2 ) {
				return $url;
			}
			parse_str( $parts[1], $query );
			unset( $query[ $key ] );

			return empty( $query ) ? $parts[0] : $parts[0] . '?' . http_build_query( $query );
		}
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text, $domain = null ) {
			return $text;
		}
	}
	if ( ! function_exists( 'esc_html_e' ) ) {
		function esc_html_e( $text, $domain = null ) {
			echo $text;
		}
	}
	if ( ! function_exists( 'esc_attr_e' ) ) {
		function esc_attr_e( $text, $domain = null ) {
			echo $text;
		}
	}
	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( $url ) {
			return (string) $url;
		}
	}
	if ( ! function_exists( 'wp_nonce_field' ) ) {
		function wp_nonce_field( $action, $name ) {
			echo '<input type="hidden" name="' . $name . '">';
		}
	}
	if ( ! function_exists( 'wp_verify_nonce' ) ) {
		function wp_verify_nonce( $nonce, $action = -1 ) {
			return 1;
		}
	}
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public $ID;
			public $post_type = 'pago';
			public function __construct( $id = 0 ) {
				$this->ID = (int) $id;
			}
		}
	}
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $post = null ) {
			return new WP_Post( (int) $post );
		}
	}
	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( $post = 0 ) {
			return 'https://example.com/pago/';
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
}

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class PaymentFlowStepsTest extends TestCase {

		protected function setUp(): void {
			$this->loadClasses();
			$_GET  = array();
			$_POST = array();

			$GLOBALS['__gw_meta']     = array();
			$GLOBALS['__gw_inserted'] = array();
			$GLOBALS['__gw_options']  = array(
				'convoca_gateway_settings' => array(
					'merchant_code' => '999999999',
					'secret_key'    => 'clave-de-prueba',
					'iban'          => 'ES00 0000 0000 0000 0000 0000',
					'beneficiary'   => 'Entidad',
				),
			);

			$this->resetCaches();
		}

		protected function tearDown(): void {
			$_GET  = array();
			$_POST = array();
			$GLOBALS['__gw_options'] = array();
			$this->resetCaches();
		}

		private function loadClasses(): void {
			foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Payment_Handler' ) as $class ) {
				$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
		}

		/**
		 * Redsys cachea los ajustes y el CSS se imprime una vez: entre pruebas hay que soltarlos.
		 */
		private function resetCaches(): void {
			$redsys = new \ReflectionClass( \Convoca\Gateway\Redsys_Client::class );
			$redsys->setStaticPropertyValue( 'settings', null );

			$handler = new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class );
			$handler->setStaticPropertyValue( 'methods_css_printed', false );
		}

		private function handler(): \Convoca\Gateway\Payment_Handler {
			return ( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * @param array<int, mixed> $args Argumentos del método.
		 * @return mixed
		 */
		private function call( string $method, array $args = array() ) {
			$reflection = new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class );
			$callable   = $reflection->getMethod( $method );
			$callable->setAccessible( true );

			return $callable->invokeArgs( $this->handler(), $args );
		}

		// ── Qué métodos hay según la configuración ─────────────────────

		public function test_available_methods_follow_the_site_configuration(): void {
			$this->assertSame(
				array( 'tarjeta', 'bizum', 'transferencia' ),
				array_keys( $this->call( 'enabled_methods' ) ),
				'Con Redsys y IBAN deben ofrecerse los tres métodos.'
			);

			$GLOBALS['__gw_options']['convoca_gateway_settings'] = array( 'iban' => 'ES00 0000' );
			$this->resetCaches();
			$this->assertSame(
				array( 'transferencia' ),
				array_keys( $this->call( 'enabled_methods' ) ),
				'Sin credenciales de Redsys solo queda la transferencia.'
			);

			$GLOBALS['__gw_options']['convoca_gateway_settings'] = array( 'merchant_code' => '999999999', 'secret_key' => 'clave' );
			$this->resetCaches();
			$this->assertSame(
				array( 'tarjeta', 'bizum' ),
				array_keys( $this->call( 'enabled_methods' ) ),
				'Sin IBAN no debe ofrecerse la transferencia.'
			);

			$GLOBALS['__gw_options']['convoca_gateway_settings'] = array();
			$this->resetCaches();
			$this->assertSame( array(), $this->call( 'enabled_methods' ) );
		}

		public function test_transfer_card_is_the_wide_one(): void {
			$methods = $this->call( 'enabled_methods' );

			$this->assertTrue( $methods['transferencia']['wide'] );
			$this->assertFalse( $methods['tarjeta']['wide'] );
			$this->assertFalse( $methods['bizum']['wide'] );
		}

		// ── Método elegido ─────────────────────────────────────────────

		public function test_requested_method_accepts_the_choice_from_the_link_and_the_form(): void {
			$_GET['convoca_gateway_method']  = 'tarjeta';
			$this->assertSame( 'tarjeta', $this->call( 'requested_method' ), 'La elección del paso 1 viaja por la URL.' );

			$_GET  = array();
			$_POST = array( 'convoca_gateway_method' => 'bizum' );
			$this->assertSame( 'bizum', $this->call( 'requested_method' ), 'El paso 2 reenvía el método elegido.' );
		}

		public function test_requested_method_rejects_unknown_or_unavailable_methods(): void {
			$_GET['convoca_gateway_method'] = 'bitcoin';
			$this->assertSame( '', $this->call( 'requested_method' ) );

			// Sin IBAN la transferencia no está disponible aunque la pidan.
			$GLOBALS['__gw_options']['convoca_gateway_settings'] = array( 'merchant_code' => '999999999', 'secret_key' => 'clave' );
			$this->resetCaches();
			$_GET['convoca_gateway_method'] = 'transferencia';
			$this->assertSame( '', $this->call( 'requested_method' ) );

			// Tampoco acepta arrays ni inyecciones de clase.
			$_GET['convoca_gateway_method'] = array( 'tarjeta' );
			$this->assertSame( '', $this->call( 'requested_method' ) );
		}

		// ── Paso 1: solo el método ─────────────────────────────────────

		public function test_donation_form_first_step_only_asks_for_the_method(): void {
			update_post_meta( 500, '_convoca_product_desc', 'Donativo' );
			update_post_meta( 500, '_convoca_link_key', 'token-de-prueba' );

			$html = $this->call( 'render_donation_form', array( 500, \Convoca\Gateway\CPT_Pago::get_meta( 500 ) ) );

			$this->assertStringContainsString( 'Selecciona un método de pago', $html );
			$this->assertStringContainsString( 'conv-method--tarjeta', $html );
			$this->assertStringContainsString( 'conv-method--bizum', $html );
			$this->assertStringContainsString( 'conv-method-card--wide', $html, 'La transferencia va a lo ancho.' );
			$this->assertStringContainsString( 'convoca_gateway_pago=500', $html, 'Cada tarjeta conserva los parámetros del enlace.' );
			$this->assertStringNotContainsString( 'convoca_donation_amount', $html, 'El importe no se pide hasta elegir método.' );
			$this->assertStringNotContainsString( 'convoca_donation_email', $html );
		}

		public function test_donation_form_second_step_asks_amount_and_email(): void {
			update_post_meta( 501, '_convoca_product_desc', 'Donativo' );
			update_post_meta( 501, '_convoca_link_key', 'token-de-prueba' );
			$_GET['convoca_gateway_method'] = 'tarjeta';

			$html = $this->call( 'render_donation_form', array( 501, \Convoca\Gateway\CPT_Pago::get_meta( 501 ) ) );

			$this->assertStringContainsString( 'convoca_donation_amount', $html );
			$this->assertStringContainsString( 'convoca_donation_email', $html );
			$this->assertStringContainsString( 'name="convoca_gateway_method" value="tarjeta"', $html, 'El método elegido viaja en el formulario.' );
			$this->assertStringContainsString( 'Cambiar método', $html );
			$this->assertStringNotContainsString( 'class="conv-method conv-method-card', $html, 'En el paso 2 ya no se pintan las tarjetas.' );
			$this->assertStringContainsString( 'Mínimo 0,50', $html );
		}

		public function test_donation_form_keeps_the_chosen_method_after_a_validation_error(): void {
			update_post_meta( 502, '_convoca_product_desc', 'Donativo' );
			update_post_meta( 502, '_convoca_link_key', 'token-de-prueba' );
			$_POST['convoca_gateway_method'] = 'bizum';

			$html = $this->call( 'render_donation_form', array( 502, \Convoca\Gateway\CPT_Pago::get_meta( 502 ), 'El importe mínimo es de 0,50 €.', '0,10', 'dona@example.com' ) );

			$this->assertStringContainsString( 'El importe mínimo es de 0,50 €.', $html );
			$this->assertStringContainsString( 'name="convoca_gateway_method" value="bizum"', $html, 'Tras el error se sigue en el paso 2, con el método elegido.' );
			$this->assertStringContainsString( 'value="0,10"', $html, 'El importe tecleado se conserva.' );
			$this->assertStringContainsString( 'value="dona@example.com"', $html );
		}

		// ── Formulario manual: mismo flujo ─────────────────────────────

		public function test_manual_form_first_step_only_asks_for_the_method(): void {
			$html = $this->call( 'render_manual_form' );

			$this->assertStringContainsString( 'Selecciona un método de pago', $html );
			$this->assertStringContainsString( 'conv-method-card--wide', $html );
			$this->assertStringNotContainsString( 'name="amount"', $html, 'El importe llega en la segunda pantalla.' );
			$this->assertStringNotContainsString( 'name="description"', $html, 'El concepto también.' );
		}

		public function test_manual_form_second_step_asks_amount_concept_and_email(): void {
			$_GET['convoca_gateway_method'] = 'transferencia';

			$html = $this->call( 'render_manual_form' );

			$this->assertStringContainsString( 'name="amount"', $html );
			$this->assertStringContainsString( 'name="description"', $html, 'La página de pago define el concepto.' );
			$this->assertStringContainsString( 'name="email"', $html );
			$this->assertStringContainsString( 'name="convoca_gateway_method" value="transferencia"', $html );
			$this->assertStringContainsString( 'convoca_gateway_manual_payment', $html );
			$this->assertStringContainsString( 'los datos para hacer el ingreso', $html );
		}

		public function test_manual_submission_uses_the_chosen_method_and_goes_straight_to_it(): void {
			$_POST = array(
				'amount'                 => '25',
				'description'            => 'Cuota',
				'email'                  => 'socio@example.com',
				'convoca_gateway_method' => 'tarjeta',
			);

			$html = $this->call( 'handle_manual_payment_submission' );

			$this->assertStringContainsString( 'convoca_gateway_method=tarjeta', $html, 'No se vuelve a preguntar el método.' );
			$this->assertStringContainsString( 'window.location.href', $html );

			$creado = array_key_last( $GLOBALS['__gw_inserted'] );
			$this->assertSame( 'tarjeta', get_post_meta( $creado, '_convoca_method', true ) );
			$this->assertSame( 2500, (int) get_post_meta( $creado, '_convoca_amount_cents', true ) );
		}

		public function test_manual_submission_without_method_returns_to_the_picker(): void {
			$_POST = array(
				'amount'      => '25',
				'description' => 'Cuota',
				'email'       => 'socio@example.com',
			);

			$html = $this->call( 'handle_manual_payment_submission' );

			$this->assertStringContainsString( 'Elige un método de pago.', $html );
			$this->assertEmpty( $GLOBALS['__gw_inserted'], 'Sin método no debe crearse ningún pago.' );
		}

		// ── El enlace de pago normal usa las mismas tarjetas ───────────

		public function test_payment_link_form_reuses_the_shared_cards(): void {
			update_post_meta( 600, '_convoca_link_key', 'token-de-prueba' );
			update_post_meta( 600, '_convoca_amount_cents', 2500 );

			$html = $this->call( 'render_link_form', array( 600, \Convoca\Gateway\CPT_Pago::get_meta( 600 ), 'Cuota', 2500, 'bizum', '', array() ) );

			$this->assertStringContainsString( 'conv-method-card', $html );
			$this->assertStringContainsString( 'conv-method-card--wide', $html, 'La transferencia también va a lo ancho aquí.' );
			$this->assertStringContainsString( 'conv-method--suggested', $html, 'El método sugerido se sigue destacando.' );
			$this->assertStringContainsString( 'Recomendado', $html );
			$this->assertStringContainsString( 'convoca_gateway_email', $html, 'El email del enlace normal sigue disponible.' );
			$this->assertStringContainsString( 'a.conv-method', $html, 'El script que arrastra el email apunta a estas tarjetas.' );
		}

		public function test_shared_css_is_printed_only_once_per_page(): void {
			$css = $this->call( 'methods_css' );

			$this->assertStringContainsString( 'grid-template-columns: 1fr 1fr', $css, 'Tarjeta y Bizum en paralelo.' );
			$this->assertStringContainsString( 'grid-column: 1 / -1', $css, 'Transferencia a lo ancho.' );
			$this->assertStringContainsString( '@media (max-width: 480px)', $css );
			$this->assertSame( '', $this->call( 'methods_css' ), 'El bloque no debe repetirse en la misma página.' );
		}

		public function test_picker_without_methods_warns_instead_of_breaking(): void {
			$GLOBALS['__gw_options']['convoca_gateway_settings'] = array();
			$this->resetCaches();

			$html = $this->call( 'render_method_picker', array( 'https://example.com/pago/', 'Selecciona un método de pago' ) );

			$this->assertStringContainsString( 'No hay ningún método de pago disponible', $html );
			$this->assertStringNotContainsString( 'class="conv-method conv-method-card', $html, 'Sin métodos no debe haber tarjetas.' );
		}
		// ── wpautop no debe desmontar el diseño ────────────────────────

		public function test_generated_markup_keeps_tags_joined(): void {
			update_post_meta( 503, '_convoca_product_desc', 'Donativo' );
			update_post_meta( 503, '_convoca_link_key', 'token-de-prueba' );

			$picker = $this->call( 'render_method_picker', array( 'https://example.com/pago/', 'Selecciona un método de pago' ) );
			$this->assertDoesNotMatchRegularExpression( '/>\s+</', $picker, 'wpautop convierte el salto entre etiquetas en <br> y mete un hijo de más en la rejilla.' );

			$donativo = $this->call( 'render_donation_form', array( 503, \Convoca\Gateway\CPT_Pago::get_meta( 503 ) ) );
			$this->assertDoesNotMatchRegularExpression( '/>\s+</', $donativo );

			$_GET['convoca_gateway_method'] = 'tarjeta';
			$donativo2 = $this->call( 'render_donation_form', array( 503, \Convoca\Gateway\CPT_Pago::get_meta( 503 ) ) );
			$this->assertDoesNotMatchRegularExpression( '/>\s+</', $donativo2 );

			$manual = $this->call( 'render_manual_form' );
			$this->assertDoesNotMatchRegularExpression( '/>\s+</', $manual );
		}
		// ── El despacho de la donación no debe depender del nombre del campo ──

		public function test_donation_post_from_the_page_creates_the_payment(): void {
			update_post_meta( 700, '_convoca_open_amount', '1' );
			update_post_meta( 700, '_convoca_link_key', 'token-de-prueba' );
			update_post_meta( 700, '_convoca_product_desc', 'Donativo' );
			update_post_meta( 700, '_convoca_expires_at', 0 );

			$_POST = array(
				'convoca_donation_nonce'  => 'nonce-valido',
				'convoca_donation_amount' => '9,90',
				'convoca_donation_email'  => 'aporta@example.com',
				'convoca_gateway_method'  => 'tarjeta',
			);

			$html = $this->call(
				'render_link_payment_page',
				array( 700, 'token-de-prueba', 0 )
			);

			$this->assertStringContainsString( 'window.location.href', $html, 'El POST del donativo debe despacharse al handler.' );
			$this->assertCount( 1, $GLOBALS['__gw_inserted'], 'Cada aportación crea su pago.' );

			$hijo = array_key_last( $GLOBALS['__gw_inserted'] );
			$this->assertSame( 990, (int) get_post_meta( $hijo, '_convoca_amount_cents', true ) );
			$this->assertSame( 'donativo', get_post_meta( $hijo, '_convoca_origin', true ) );
			$this->assertSame( 700, (int) get_post_meta( $hijo, '_convoca_origin_id', true ) );
			$this->assertSame( 'aporta@example.com', get_post_meta( $hijo, '_convoca_payer_email', true ) );
			$this->assertSame( '1', get_post_meta( $hijo, '_convoca_receipt_always', true ) );
		}

		public function test_donation_page_without_post_still_shows_the_picker(): void {
			update_post_meta( 701, '_convoca_open_amount', '1' );
			update_post_meta( 701, '_convoca_link_key', 'token-de-prueba' );
			update_post_meta( 701, '_convoca_product_desc', 'Donativo' );
			update_post_meta( 701, '_convoca_expires_at', 0 );

			$html = $this->call( 'render_link_payment_page', array( 701, 'token-de-prueba', 0 ) );

			$this->assertStringContainsString( 'Selecciona un método de pago', $html );
			$this->assertEmpty( $GLOBALS['__gw_inserted'], 'Sin envío no debe crearse nada.' );
		}
	}
}
