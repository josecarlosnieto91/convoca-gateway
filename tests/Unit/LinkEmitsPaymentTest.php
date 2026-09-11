<?php
/**
 * Un enlace de pago es una plantilla: al usarlo emite un cobro propio.
 *
 * Antes el enlace se convertía él mismo en el cobro y quedaba gastado al primer pago,
 * así que su importe no podía aparecer en «Todos los Pagos» sin confundirse con la
 * plantilla. Ahora cada uso deja su registro (uno o más por enlace), el enlace se
 * queda intacto y el listado de pagos solo muestra cobros.
 *
 * Los dobles de WordPress los pone tests/stubs.php (cargado por el bootstrap).
 */

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class LinkEmitsPaymentTest extends TestCase {

		private static $cargado = false;

		protected function setUp(): void {
			if ( ! self::$cargado ) {
				foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Payment_Handler' ) as $class ) {
					$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
					if ( file_exists( $path ) ) {
						require_once $path;
					}
				}
				self::$cargado = true;
			}

			$_GET                 = array();
			$_POST                = array();
			$GLOBALS['__gw_meta']     = array();
			$GLOBALS['__gw_inserted'] = array();
			$GLOBALS['__gw_hijos']    = array();
			$GLOBALS['__gw_options']  = array(
				'convoca_gateway_settings' => array(
					'merchant_code' => '999999999',
					'secret_key'    => 'clave-de-prueba',
					'iban'          => 'ES00 0000 0000 0000 0000 0000',
					'beneficiary'   => 'Entidad',
				),
			);

			if ( method_exists( '\\Convoca\\Gateway\\Redsys_Client', 'clear_cache' ) ) {
				\Convoca\Gateway\Redsys_Client::clear_cache();
			}
		}

		/** Enlace de 25,00 € con un dato adicional. */
		private function enlace( int $id = 500 ): int {
			$GLOBALS['__gw_meta'][ $id ] = array(
				'_convoca_order_id'     => '260911LINK01',
				'_convoca_amount_cents' => 2500,
				'_convoca_currency'     => 'EUR',
				'_convoca_method'       => 'any',
				'_convoca_status'       => 'pending',
				'_convoca_origin'       => 'link_payment',
				'_convoca_product_desc' => 'Cuota anual',
				'_convoca_link_key'     => 'token-de-prueba',
				'_convoca_expires_at'   => 0,
				'_convoca_params'       => array( 'Socio/a' => 'A-1' ),
			);

			return $id;
		}

		/** Llama a un método privado del manejador de pagos. */
		private function privado( string $metodo, array $args ) {
			$ref = new \ReflectionMethod( \Convoca\Gateway\Payment_Handler::class, $metodo );
			$ref->setAccessible( true );

			// Sin constructor: registraría hooks de WordPress que aquí no existen.
			$handler = ( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->newInstanceWithoutConstructor();

			return $ref->invokeArgs( $handler, $args );
		}

		private function usar( int $id ): string {
			return (string) $this->privado(
				'handle_link_use',
				array( $id, \Convoca\Gateway\CPT_Pago::get_meta( $id ), 'Cuota anual', 2500, 'any', '', array() )
			);
		}

		/** El paso 1 del enlace: los métodos son botones que envían el formulario. */
		public function test_the_link_screen_submits_the_form_instead_of_linking(): void {
			$form = $this->privado(
				'render_link_form',
				array( $this->enlace(), \Convoca\Gateway\CPT_Pago::get_meta( 500 ), 'Cuota anual', 2500, 'any', '', array(), true )
			);

			$this->assertStringContainsString( 'convoca_link_nonce', $form, 'Falta el nonce del formulario.' );
			$this->assertStringContainsString( 'name="convoca_gateway_method"', $form, 'Los métodos deben enviar el método elegido.' );
			$this->assertStringContainsString( 'type="submit"', $form );
			$this->assertStringNotContainsString( '<a href="https://example.com/pago/?convoca_gateway_method', $form, 'Un enlace no debe emitir cobros al abrir la página.' );

			// Un registro que no es plantilla mantiene el comportamiento de siempre.
			$legacy = $this->privado(
				'render_link_form',
				array( $this->enlace( 501 ), \Convoca\Gateway\CPT_Pago::get_meta( 501 ), 'Cuota anual', 2500, 'any', '', array(), false )
			);
			$this->assertStringNotContainsString( 'convoca_link_nonce', $legacy );
		}

		public function test_opening_the_page_does_not_emit_anything(): void {
			$id  = $this->enlace();
			$out = $this->usar( $id );

			$this->assertSame( array(), $GLOBALS['__gw_inserted'], 'Abrir el enlace no puede crear cobros.' );
			$this->assertStringContainsString( 'convoca_link_nonce', $out );
		}

		public function test_using_the_link_emits_its_own_payment(): void {
			$id       = $this->enlace();
			$_POST    = array(
				'convoca_link_nonce'     => 'nonce',
				'convoca_gateway_method' => 'transferencia',
				'convoca_gateway_email'  => 'quien@paga.test',
			);

			$out = $this->usar( $id );

			$this->assertCount( 1, $GLOBALS['__gw_inserted'], 'El uso del enlace debe emitir un cobro.' );
			$cobro = (int) array_key_first( $GLOBALS['__gw_inserted'] );
			$meta  = $GLOBALS['__gw_meta'][ $cobro ];

			$this->assertSame( 'enlace', $meta['_convoca_origin'], 'El cobro tiene origen propio, no el de la plantilla.' );
			$this->assertSame( (string) $id, (string) $meta['_convoca_origin_id'], 'El cobro apunta al enlace que lo emitió.' );
			$this->assertSame( 2500, (int) $meta['_convoca_amount_cents'] );
			$this->assertSame( 'Cuota anual', $meta['_convoca_product_desc'] );
			$this->assertSame( 'quien@paga.test', $meta['_convoca_payer_email'] );
			$this->assertSame( 'transferencia', $meta['_convoca_method'] );
			$this->assertSame( array( 'Socio/a' => 'A-1' ), $meta['_convoca_params'], 'Los datos adicionales viajan al cobro.' );
			$this->assertSame( 'pending', $meta['_convoca_status'] );

			// Se paga contra el cobro emitido, no contra la plantilla: si no, se cobraría
			// siempre el mismo registro y no habría uno por uso. La pantalla de
			// transferencia muestra la referencia del cobro, así que aquí se comprueba.
			$this->assertStringContainsString( (string) $meta['_convoca_order_id'], $out );
			$this->assertStringNotContainsString( '260911LINK01', $out, 'El cobro no debe llevar el pedido del enlace.' );
			$this->assertStringNotContainsString( 'convoca_link_nonce', $out, 'Ya no es la pantalla del enlace.' );
		}

		public function test_a_card_payment_goes_to_the_gateway_with_the_emitted_payment(): void {
			$id    = $this->enlace();
			$_POST = array(
				'convoca_link_nonce'     => 'nonce',
				'convoca_gateway_method' => 'tarjeta',
			);

			$out = $this->usar( $id );

			$this->assertCount( 1, $GLOBALS['__gw_inserted'], 'También con tarjeta se emite el cobro.' );
			$this->assertStringNotContainsString( 'convoca_link_nonce', $out, 'Ya no es la pantalla del enlace.' );
		}

		public function test_the_link_survives_and_can_emit_more_payments(): void {
			$id = $this->enlace();
			$_POST = array(
				'convoca_link_nonce'     => 'nonce',
				'convoca_gateway_method' => 'tarjeta',
			);
			$this->usar( $id );

			$this->assertSame( 'pending', $GLOBALS['__gw_meta'][ $id ]['_convoca_status'], 'El enlace no se gasta.' );
			$this->assertSame( 'any', $GLOBALS['__gw_meta'][ $id ]['_convoca_method'], 'El método sugerido del enlace no se pisa.' );
			$this->assertSame( 'token-de-prueba', $GLOBALS['__gw_meta'][ $id ]['_convoca_link_key'], 'El token del enlace no cambia.' );

			$_POST = array(
				'convoca_link_nonce'     => 'nonce',
				'convoca_gateway_method' => 'bizum',
			);
			$this->usar( $id );

			$this->assertCount( 2, $GLOBALS['__gw_inserted'], 'Un enlace puede emitir uno o más cobros.' );
			$this->assertSame( 'bizum', $GLOBALS['__gw_meta'][ (int) array_key_last( $GLOBALS['__gw_inserted'] ) ]['_convoca_method'] );
		}

		public function test_without_a_nonce_nothing_is_emitted(): void {
			$id    = $this->enlace();
			$_POST = array( 'convoca_gateway_method' => 'tarjeta' );

			$this->usar( $id );

			$this->assertSame( array(), $GLOBALS['__gw_inserted'] );
		}

		public function test_an_unknown_method_is_refused(): void {
			$id    = $this->enlace();
			$_POST = array(
				'convoca_link_nonce'     => 'nonce',
				'convoca_gateway_method' => 'bitcoin',
			);

			$this->usar( $id );

			$this->assertSame( array(), $GLOBALS['__gw_inserted'] );
		}

		/**
		 * El despacho de la página: un enlace va al camino que emite; un registro que
		 * no es plantilla sigue el de siempre. Sin esto, la prueba llamaría a
		 * handle_link_use directamente y se saltaría lo que hay que proteger.
		 */
		public function test_a_link_page_goes_through_the_emitting_path(): void {
			$id    = $this->enlace();
			$_POST = array(
				'convoca_link_nonce'     => 'nonce',
				'convoca_gateway_method' => 'transferencia',
			);

			$this->privado( 'render_link_payment_page', array( $id, 'token-de-prueba', 0 ) );

			$this->assertCount( 1, $GLOBALS['__gw_inserted'], 'Un enlace emite su cobro, no se convierte en él.' );
			$this->assertSame( 'pending', $GLOBALS['__gw_meta'][ $id ]['_convoca_status'], 'Y no se marca como pagado.' );
		}

		public function test_a_record_that_is_not_a_link_keeps_the_old_path(): void {
			$id = $this->enlace( 502 );
			$GLOBALS['__gw_meta'][ $id ]['_convoca_origin'] = 'donativo';
			$_GET  = array( 'convoca_gateway_method' => 'tarjeta' );
			$_POST = array();

			$this->privado( 'render_link_payment_page', array( $id, 'token-de-prueba', 0 ) );

			$this->assertSame( array(), $GLOBALS['__gw_inserted'], 'Lo que no es plantilla no emite.' );
			$this->assertSame( 'tarjeta', $GLOBALS['__gw_meta'][ $id ]['_convoca_method'], 'Sigue el camino de siempre.' );
		}

		/** Un enlace caducado o borrado deja de emitir: es la condición de JC. */
		public function test_an_expired_link_stops_emitting(): void {
			$id = $this->enlace();
			$GLOBALS['__gw_meta'][ $id ]['_convoca_expires_at'] = time() - 3600;

			$out = (string) $this->privado( 'render_link_payment_page', array( $id, 'token-de-prueba', time() - 3600 ) );

			$this->assertSame( array(), $GLOBALS['__gw_inserted'], 'Caducado no se emite nada.' );
			$this->assertStringContainsString( 'caduc', strtolower( $out ) );
		}

		public function test_a_deleted_link_cannot_emit(): void {
			$id = $this->enlace();
			$GLOBALS['__gw_meta'] = array(); // borrado: get_post devuelve null

			$out = (string) $this->privado( 'render_link_payment_page', array( $id, 'token-de-prueba', 0 ) );

			$this->assertSame( array(), $GLOBALS['__gw_inserted'] );
			$this->assertStringContainsString( 'no encontrado', strtolower( $out ) );
		}

		public function test_the_links_list_counts_the_emitted_payments(): void {
			$GLOBALS['__gw_hijos'] = array( 9001, 9002 );

			$this->assertSame( 2, \Convoca\Gateway\CPT_Pago::cobros_emitidos( 500 ) );

			$GLOBALS['__gw_hijos'] = array();

			$this->assertSame( 0, \Convoca\Gateway\CPT_Pago::cobros_emitidos( 500 ) );
		}
	}
}
