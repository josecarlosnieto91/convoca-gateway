<?php
/**
 * Un enlace de cuota o inscripción se cierra cuando su pago se cobra.
 *
 * Regla del usuario: un enlace caduca **solo** si tiene fecha de expiración; ahora bien,
 * los pagos de cuota e inscripción (Members y Enroll) sí dejan su enlace cerrado tras un
 * pago **confirmado y correcto** —y si el intento es erróneo, no—, porque ese enlace existe
 * para cobrar una sola cosa. Los enlaces del generador (donativos y plantillas) no se
 * cierran así: siguen vivos y solo caducan por fecha.
 *
 * El cierre se engancha al hook de pago completado, nunca al de fallo: lo que gasta un
 * enlace es un cobro aprobado.
 */

namespace {
	if ( ! function_exists( 'wp_date' ) ) {
		function wp_date( $format, $timestamp = null ) {
			return date( $format, $timestamp ?? time() );
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text, $domain = '' ) {
			return $text;
		}
	}
}

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class LinkClosedAfterPaymentTest extends TestCase {

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
			$GLOBALS['__gw_meta']    = array();
			$GLOBALS['__gw_options'] = array(
				'convoca_gateway_settings' => array(
					'merchant_code' => '999999999',
					'secret_key'    => 'clave-de-prueba',
					'iban'          => 'ES00 0000 0000 0000 0000 0000',
					'beneficiary'   => 'Entidad',
				),
			);
		}

		/** Un pago con el token de su enlace. */
		private function pago( int $id, string $origen, string $estado = 'paid', array $extra = array() ): int {
			$GLOBALS['__gw_meta'][ $id ] = array_merge(
				array(
					'_convoca_status'     => $estado,
					'_convoca_origin'     => $origen,
					'_convoca_link_key'   => 'token-de-prueba',
					'_convoca_expires_at' => 0,
					'_convoca_amount_cents' => 2500,
					'_convoca_product_desc' => 'Cuota anual',
				),
				$extra
			);

			return $id;
		}

		private function privado( string $metodo, array $args ) {
			$ref = new \ReflectionMethod( \Convoca\Gateway\Payment_Handler::class, $metodo );
			$ref->setAccessible( true );
			$handler = ( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->newInstanceWithoutConstructor();

			return $ref->invokeArgs( $handler, $args );
		}

		// — El cierre del enlace ————————————————————————————————

		public function test_una_cuota_cobrada_cierra_su_enlace(): void {
			$id = $this->pago( 900, 'members' );

			$this->assertTrue( \Convoca\Gateway\Link_Expiry::close_after_payment( $id, 'members', 1700000000 ) );
			$this->assertTrue( \Convoca\Gateway\Link_Expiry::is_closed( $id ) );
			$this->assertSame( 1700000000, \Convoca\Gateway\Link_Expiry::closed_at( $id ) );
		}

		public function test_una_inscripcion_cobrada_cierra_su_enlace(): void {
			$id = $this->pago( 901, 'enroll' );

			$this->assertTrue( \Convoca\Gateway\Link_Expiry::close_after_payment( $id, 'enroll' ) );
			$this->assertTrue( \Convoca\Gateway\Link_Expiry::is_closed( $id ) );
		}

		public function test_el_enlace_de_donativo_no_se_cierra(): void {
			$id = $this->pago( 902, 'link_payment' );

			$this->assertFalse( \Convoca\Gateway\Link_Expiry::close_after_payment( $id, 'link_payment' ) );
			$this->assertFalse( \Convoca\Gateway\Link_Expiry::is_closed( $id ), 'Una plantilla de donativo sigue viva: solo caduca por fecha.' );
		}

		public function test_cerrar_dos_veces_no_mueve_la_fecha(): void {
			$id = $this->pago( 903, 'members' );

			\Convoca\Gateway\Link_Expiry::close_after_payment( $id, 'members', 1700000000 );
			\Convoca\Gateway\Link_Expiry::close_after_payment( $id, 'members', 1800000000 );

			$this->assertSame( 1700000000, \Convoca\Gateway\Link_Expiry::closed_at( $id ) );
		}

		public function test_el_hook_de_pago_completado_cierra_el_enlace(): void {
			$id = $this->pago( 904, 'members' );

			// Exactamente como lo llama el gateway al confirmar el pago.
			\Convoca\Gateway\Link_Expiry::on_payment_completed( $id, 'members', array() );

			$this->assertTrue( \Convoca\Gateway\Link_Expiry::is_closed( $id ) );
		}

		public function test_el_fallo_no_esta_enganchado_al_cierre(): void {
			// Un intento erróneo no puede cerrar nada: el plugin solo registra el
			// cierre en el hook de pago completado, nunca en el de fallo.
			$fuente = (string) file_get_contents( dirname( __DIR__, 2 ) . '/convoca-gateway.php' );

			$this->assertStringContainsString( "'convoca_gateway_payment_completed', array( \\Convoca\\Gateway\\Link_Expiry::class, 'on_payment_completed' )", $fuente );
			$this->assertStringNotContainsString( "'convoca_gateway_payment_failed', array( \\Convoca\\Gateway\\Link_Expiry", $fuente );
		}

		// — Lo que ve quien abre el enlace ———————————————————————

		public function test_un_enlace_cobrado_avisa_y_no_deja_pagar(): void {
			$id = $this->pago( 905, 'members', 'paid', array( '_convoca_link_closed_at' => 1700000000 ) );

			$salida = (string) $this->privado( 'render_link_payment_page', array( $id, 'token-de-prueba', 0 ) );

			$this->assertStringContainsString( 'ya se ha cobrado', $salida );
			$this->assertStringNotContainsString( 'convoca_gateway_method', $salida, 'Un enlace cerrado no ofrece cómo pagar.' );
			$this->assertStringNotContainsString( 'Solicitar nuevo enlace', $salida, 'Y no ofrece regenerar: el pago ya está hecho.' );
		}

		public function test_un_intento_fallido_sigue_dejando_pagar(): void {
			$id = $this->pago( 906, 'members', 'failed' );

			$salida = (string) $this->privado( 'render_link_payment_page', array( $id, 'token-de-prueba', 0 ) );

			$this->assertStringContainsString( 'convoca_gateway_method', $salida, 'Tras un fallo, el enlace debe seguir sirviendo.' );
			$this->assertStringNotContainsString( 'ya se ha cobrado', $salida );
		}

		public function test_no_se_regenera_el_enlace_de_un_pago_cobrado(): void {
			$id = $this->pago( 907, 'members', 'paid', array( '_convoca_link_closed_at' => 1700000000 ) );

			$resultado = \Convoca\Gateway\Link_Expiry::regenerate_link( $id );

			$this->assertInstanceOf( \WP_Error::class, $resultado );
			$this->assertSame( 'link_closed', $resultado->get_error_code() );
		}
	}
}
