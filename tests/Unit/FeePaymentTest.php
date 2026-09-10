<?php
/**
 * Pagos que crean los otros plugins (cuota de socio, inscripción a actividad).
 *
 * Tres cosas que se fijan aquí: el pago guarda el correo de contacto que le pasa el
 * plugin, la URL que recibe lleva token con caducidad configurable (antes era una
 * firma de 24 h) y el recibo automático de cuotas es PRO con su propio interruptor.
 *
 * La licencia se sustituye por un doble: sin licencia el comportamiento no cambia.
 */

namespace Convoca\Core {
	if ( ! class_exists( 'Convoca\Core\License_Manager' ) ) {
		class License_Manager {
			public static $pro = false;
			public static function has_pro( $feature = '' ) {
				return (bool) self::$pro;
			}
		}
	}
}

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class FeePaymentTest extends TestCase {

		private static $cargado = false;

		protected function setUp(): void {
			if ( ! self::$cargado ) {
				foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Payment_Handler', 'Email_Notifications' ) as $class ) {
					$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
					if ( file_exists( $path ) ) {
						require_once $path;
					}
				}
				self::$cargado = true;
			}

			$_GET  = array();
			$_POST = array();
			$GLOBALS['__gw_meta']     = array();
			$GLOBALS['__gw_inserted'] = array();
			$GLOBALS['__gw_emails']   = array();
			$GLOBALS['__gw_options']  = array(
				'convoca_gateway_settings' => array(
					'merchant_code' => '999999999',
					'secret_key'    => 'clave-de-prueba',
					'iban'          => 'ES00 0000 0000 0000 0000 0000',
					'beneficiary'   => 'Entidad',
				),
			);

			\Convoca\Core\License_Manager::$pro = false;

			( new \ReflectionClass( \Convoca\Gateway\Redsys_Client::class ) )->setStaticPropertyValue( 'settings', null );
			( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->setStaticPropertyValue( 'methods_css_printed', false );
		}

		protected function tearDown(): void {
			\Convoca\Core\License_Manager::$pro = false;
		}

		/** Un socio/inscripción que ya existe en el harness. */
		private function origen( int $id = 700 ): int {
			$GLOBALS['__gw_meta'][ $id ] = array( '_convoca_email' => 'socio@example.test' );

			return $id;
		}

		private function crear( array $extra = array() ): array {
			return \Convoca\Gateway\Payment_Handler::create_payment(
				array_merge(
					array(
						'amount_cents' => 2500,
						'method'       => 'tarjeta',
						'origin'       => 'members',
						'origin_id'    => $this->origen(),
						'product_desc' => 'ENTIDAD CUOTA Bronce',
					),
					$extra
				)
			);
		}

		public function test_the_payment_keeps_the_contact_email_and_gets_a_token(): void {
			$res  = $this->crear( array( 'payer_email' => 'socio@example.test' ) );
			$pago = (int) $res['pago_id'];

			$this->assertSame( 'socio@example.test', get_post_meta( $pago, '_convoca_payer_email', true ), 'El correo es lo que permite enviar el recibo.' );
			$this->assertSame( 'socio@example.test', get_post_meta( $pago, '_convoca_recipient_email', true ), 'Y sirve también para el aviso de caducidad.' );
			$this->assertNotSame( '', (string) get_post_meta( $pago, '_convoca_link_key', true ), 'La URL va firmada con token.' );
			$this->assertGreaterThan( time(), (int) get_post_meta( $pago, '_convoca_expires_at', true ), 'Y caduca: no vale para siempre.' );

			$this->assertStringContainsString( 'convoca_gateway_key=', $res['payment_url'], 'La URL que recibe el socio lleva el token.' );
			$this->assertStringNotContainsString( 'convoca_gateway_t=', $res['payment_url'], 'Ya no se usa la firma antigua de 24 horas.' );
		}

		public function test_the_expiry_follows_the_setting(): void {
			$res  = $this->crear();
			$pago = (int) $res['pago_id'];
			$dias = ( (int) get_post_meta( $pago, '_convoca_expires_at', true ) - time() ) / 86400;

			$this->assertGreaterThan( 6, $dias, 'Por defecto, una semana.' );
			$this->assertLessThan( 8, $dias );

			$GLOBALS['__gw_options']['convoca_gateway_settings']['link_expiry_days'] = 3;
			$res  = $this->crear();
			$pago = (int) $res['pago_id'];
			$dias = ( (int) get_post_meta( $pago, '_convoca_expires_at', true ) - time() ) / 86400;

			$this->assertGreaterThan( 2, $dias, 'El ajuste manda: 3 días.' );
			$this->assertLessThan( 4, $dias );
		}

		public function test_without_the_contact_email_nothing_is_invented(): void {
			$res  = $this->crear();
			$pago = (int) $res['pago_id'];

			$this->assertSame( '', (string) get_post_meta( $pago, '_convoca_payer_email', true ) );
			$this->assertSame( '', (string) get_post_meta( $pago, '_convoca_recipient_email', true ) );
		}

		public function test_a_used_payment_stops_working(): void {
			$res    = $this->crear();
			$pago   = (int) $res['pago_id'];
			$creados = count( $GLOBALS['__gw_inserted'] );
			$token = (string) get_post_meta( $pago, '_convoca_link_key', true );
			update_post_meta( $pago, '_convoca_status', 'paid' );

			$ref = new \ReflectionMethod( \Convoca\Gateway\Payment_Handler::class, 'render_link_payment_page' );
			$ref->setAccessible( true );
			$handler = ( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->newInstanceWithoutConstructor();
			$out     = (string) $ref->invokeArgs( $handler, array( $pago, $token, 0 ) );

			$this->assertStringContainsString( 'completado', $out, 'Un pago ya cobrado no se puede volver a pagar.' );
			$this->assertSame( $creados, count( $GLOBALS['__gw_inserted'] ), 'Y no crea registros nuevos.' );
		}

		/** El recibo automático de cuotas: PRO y activado por defecto. */
		private function reciboDeCuota( int $pago, string $origen ): bool {
			update_post_meta( $pago, '_convoca_origin', $origen );

			$ref = new \ReflectionMethod( \Convoca\Gateway\Email_Notifications::class, 'envia_recibo_de_cuota' );
			$ref->setAccessible( true );

			return (bool) $ref->invoke( null, $pago );
		}

		public function test_the_fee_receipt_is_on_by_default_with_license(): void {
			\Convoca\Core\License_Manager::$pro = true;

			$this->assertTrue( $this->reciboDeCuota( 900, 'members' ), 'Con licencia, el recibo sale por defecto.' );
			$this->assertTrue( $this->reciboDeCuota( 901, 'enroll' ), 'También en las inscripciones.' );
		}

		public function test_the_fee_receipt_can_be_switched_off(): void {
			\Convoca\Core\License_Manager::$pro = true;
			$GLOBALS['__gw_options']['convoca_gateway_settings']['auto_receipt_fees'] = '0';

			$this->assertFalse( $this->reciboDeCuota( 900, 'members' ) );
		}

		public function test_without_license_there_is_no_automatic_fee_receipt(): void {
			$this->assertFalse( $this->reciboDeCuota( 900, 'members' ), 'Sin licencia no es automático.' );
		}

		public function test_only_fees_and_enrolments_get_this_receipt(): void {
			\Convoca\Core\License_Manager::$pro = true;

			$this->assertFalse( $this->reciboDeCuota( 900, 'donativo' ), 'Los donativos llevan su propia marca.' );
			$this->assertFalse( $this->reciboDeCuota( 901, 'enlace' ) );
			$this->assertFalse( $this->reciboDeCuota( 902, 'manual' ) );
		}
	}
}
