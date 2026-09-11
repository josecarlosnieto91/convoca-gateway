<?php
/**
 * Un pago se da por cobrado solo si la respuesta es correcta y confirmada.
 *
 * Regla del usuario: un enlace caduca **solo** si tiene fecha de expiración. Los pagos de
 * cuota e inscripción (members y enroll) sí se cierran tras un pago confirmado y correcto;
 * si el intento es erróneo, no. Es decir: lo que deja un enlace gastado es un cobro
 * aprobado, nunca un fallo —ni un código de operación que no sea una compra, como una
 * devolución o una anulación, que no deben dar por pagada una cuota.
 */

namespace Convoca\Gateway\Tests {

	use PHPUnit\Framework\TestCase;

	class PaymentConfirmationTest extends TestCase {

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

			if ( method_exists( '\Convoca\Gateway\Redsys_Client', 'clear_cache' ) ) {
				\Convoca\Gateway\Redsys_Client::clear_cache();
			}
		}

		/**
		 * Un pago de cuota, con el token de su enlace.
		 *
		 * @param array<string, mixed> $extra Meta que se quiera añadir o pisar.
		 */
		private function pago( int $id = 700, string $estado = 'pending', array $extra = array() ): int {
			$GLOBALS['__gw_meta'][ $id ] = array_merge(
				array(
					'_convoca_order_id'     => '260911CUOTA1',
					'_convoca_amount_cents' => 2500,
					'_convoca_currency'     => 'EUR',
					'_convoca_method'       => 'any',
					'_convoca_status'       => $estado,
					'_convoca_origin'       => 'members_cuota',
					'_convoca_product_desc' => 'Cuota anual',
					'_convoca_link_key'     => 'token-de-prueba',
					'_convoca_expires_at'   => 0,
					'_convoca_params'       => array(),
				),
				$extra
			);

			return $id;
		}

		/** Llama a un método privado del manejador, sin pasar por su constructor. */
		private function privado( string $metodo, array $args ) {
			$ref = new \ReflectionMethod( \Convoca\Gateway\Payment_Handler::class, $metodo );
			$ref->setAccessible( true );

			$handler = ( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->newInstanceWithoutConstructor();

			return $ref->invokeArgs( $handler, $args );
		}

		/** La página del enlace, tal cual la sirve el plugin. */
		private function abrir( int $id, int $expira = 0 ): string {
			return (string) $this->privado( 'render_link_payment_page', array( $id, 'token-de-prueba', $expira ) );
		}

		// — El protocolo de la transacción ————————————————————————————————

		public function test_una_notificacion_confirmada_confirma_la_transaccion(): void {
			// El fallo que se coló en producción: la profundidad del savepoint no se
			// decrementaba en el camino de éxito, así que el COMMIT no llegaba a
			// ejecutarse y MySQL descartaba el cambio al cerrar la conexión. La
			// notificación respondía «OK» y el pago seguía pendiente, en silencio.
			$id    = $this->pago( 701, 'pending' );
			$order = get_post_meta( $id, '_convoca_order_id', true );

			$params = array(
				'Ds_Order'      => $order,
				'Ds_Amount'     => '2500',
				'Ds_Currency'   => '978',
				'Ds_Response'   => '0000',
				'Ds_MerchantCode' => '999999999',
				'Ds_AuthorisationCode' => '123456',
			);
			$b64  = base64_encode( (string) json_encode( $params ) );
			$post = array(
				'Ds_SignatureVersion'   => 'HMAC_SHA256_V1',
				'Ds_MerchantParameters' => $b64,
				'Ds_Signature'          => \Convoca\Gateway\Redsys_Client::sign( $b64, $order ),
			);

			$_SERVER['REMOTE_ADDR'] = '195.76.9.187';
			$GLOBALS['__gw_sql']    = array();
			// La búsqueda del pago va a la base de datos: se le da el id para esta prueba.
			$GLOBALS['__gw_get_var'] = $id;

			$handler = ( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->newInstanceWithoutConstructor();
			$result  = $handler->process_notification( $post );

			$this->assertTrue( $result, 'La notificación se da por buena.' );
			$this->assertSame( 'paid', get_post_meta( $id, '_convoca_status', true ), 'Y el pago queda cobrado.' );

			$transacciones = array_values( array_filter( $GLOBALS['__gw_sql'], fn( $q ) => preg_match( '/^(START TRANSACTION|COMMIT|ROLLBACK)/i', (string) $q ) ) );
			$this->assertNotEmpty( $transacciones, 'La notificación trabaja dentro de una transacción.' );
			$this->assertSame( 'START TRANSACTION', strtoupper( trim( (string) end( $transacciones ) ) ) === 'COMMIT' ? 'START TRANSACTION' : 'SIN COMMIT', 'La última sentencia de la transacción debe confirmarla.' );
			$this->assertSame( 'COMMIT', strtoupper( trim( (string) end( $transacciones ) ) ), 'La transacción se confirma: sin COMMIT el cambio se pierde.' );
		}

		// — La respuesta de Redsys ————————————————————————————————————————

		public function test_solo_la_autorizacion_de_compra_da_el_pago_por_hecho(): void {
			$this->assertTrue( \Convoca\Gateway\Redsys_Client::is_approved( '0000' ), 'Una compra autorizada sí.' );

			$no_cobran = array(
				'0001' => 'confirmación de una operación anterior, no una compra nueva',
				'0099' => 'anulación o devolución',
				'0101' => 'tarjeta caducada',
				'0180' => 'operación no permitida',
				'9999' => 'sin respuesta del banco',
				'0120' => 'operación no disponible',
			);

			foreach ( $no_cobran as $codigo => $que_es ) {
				$this->assertFalse(
					\Convoca\Gateway\Redsys_Client::is_approved( $codigo ),
					sprintf( 'El código %s (%s) no puede dar una cuota por pagada.', $codigo, $que_es )
				);
			}

			// Un valor que no llegue, o que llegue vacío, tampoco.
			$this->assertFalse( \Convoca\Gateway\Redsys_Client::is_approved( '' ) );
			$this->assertFalse( \Convoca\Gateway\Redsys_Client::is_approved( '0' ) );
		}

		// — El estado del pago decide si el enlace sirve ——————————————————

		public function test_mientras_no_este_pagado_el_enlace_sirve(): void {
			$formulario = $this->abrir( $this->pago() );

			$this->assertStringContainsString( 'convoca_gateway_method', $formulario, 'Sin pagar, el enlace debe ofrecer cómo pagar.' );
			$this->assertStringNotContainsString( 'ya ha sido completado', $formulario );
		}

		public function test_un_pago_confirmado_gasta_su_enlace(): void {
			$pagado = $this->abrir( $this->pago( 701, 'paid' ) );

			$this->assertStringContainsString( 'ya ha sido completado', $pagado, 'Un pago confirmado no debe volver a admitir cobros.' );
			$this->assertStringNotContainsString( 'convoca_gateway_method', $pagado, 'Y no debe ofrecer cómo pagar.' );
		}

		public function test_un_intento_erroneo_no_gasta_el_enlace(): void {
			// La regla del usuario: si el pago es erróneo, el enlace sigue vivo.
			$fallido = $this->abrir( $this->pago( 702, 'failed' ) );

			$this->assertStringContainsString(
				'convoca_gateway_method',
				$fallido,
				'Un intento fallido no puede dejar a nadie sin poder pagar.'
			);
			$this->assertStringNotContainsString( 'ya ha sido completado', $fallido );
		}

		// — Solo caduca si tiene fecha ————————————————————————————————

		public function test_sin_fecha_no_caduca_nunca(): void {
			$sin_fecha = $this->abrir( $this->pago( 703, 'pending', array( '_convoca_expires_at' => 0 ) ) );

			$this->assertStringContainsString( 'convoca_gateway_method', $sin_fecha, 'Sin fecha de expiración, el enlace no caduca.' );
		}

		public function test_con_fecha_caduca_al_pasarla(): void {
			$caducado = $this->abrir( $this->pago( 704, 'pending', array( '_convoca_expires_at' => time() - 60 ) ) );

			$this->assertStringNotContainsString( 'convoca_gateway_method', $caducado, 'Con la fecha pasada, el enlace no debe servir.' );
			$this->assertStringContainsString( 'caduc', strtolower( strip_tags( $caducado ) ), 'Y debe decirlo.' );
		}

		public function test_con_fecha_todavia_viva_sirve(): void {
			$vivo = $this->abrir( $this->pago( 705, 'pending', array( '_convoca_expires_at' => time() + 3600 ) ) );

			$this->assertStringContainsString( 'convoca_gateway_method', $vivo );
		}

		// — El enlace de donativo es otra cosa ———————————————————————

		public function test_el_enlace_de_donativo_no_se_gasta(): void {
			// Un donativo es reutilizable: cada aportación crea su propio cobro, así que el
			// enlace (que es la plantilla) no se consume aunque acumule cobros.
			$donativo = $this->pago( 706, 'pending', array( '_convoca_open_amount' => 1 ) );

			$pantalla = $this->abrir( $donativo );

			$this->assertStringContainsString( 'Formulario de donativo', $pantalla, 'El enlace de donativo debe seguir sirviendo su formulario.' );
			$this->assertStringNotContainsString( 'ya ha sido completado', $pantalla );
		}

		public function test_una_fecha_puesta_a_mano_manda_tambien_en_un_donativo(): void {
			$donativo = $this->pago(
				707,
				'pending',
				array(
					'_convoca_open_amount' => 1,
					'_convoca_expires_at'  => time() - 60,
				)
			);

			// Sin caducidad marcada a mano, un donativo no lleva fecha; si alguien se la pone,
			// se respeta: la fecha manda sobre todo lo demás.
			$this->assertStringNotContainsString( 'Formulario de donativo', $this->abrir( $donativo ), 'Con fecha pasada, ni el donativo.' );
		}
	}
}
