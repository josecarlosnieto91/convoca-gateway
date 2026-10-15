<?php
/**
 * El método de pago elegido viaja en el enlace (gateway#9).
 *
 * DEFECTO QUE FIJA ESTA PRUEBA (medido en la demo el 15-16/10/2026): quien elegía «Transferencia
 * bancaria» en el alta acababa en `/pago/?convoca_gateway_pago=…&convoca_gateway_key=…` — **sin el
 * método**—, así que la página caía al selector genérico y le ofrecía tarjeta y Bizum. Con el IBAN sin
 * configurar no veía ni la transferencia ni una explicación: el camino moría ahí.
 *
 * El alta ya le pasaba el método al crear el pago (`'method' => 'transferencia'`); lo que faltaba era que
 * la URL devuelta lo llevara puesto. Con eso, la página de pago entra por `render_transfer_instructions()`
 * —que ya existía— y muestra IBAN, beneficiario y concepto, o el aviso de que falta el IBAN.
 *
 * El método «any» (un enlace de donación) se queda sin parámetro: ahí elegir es cosa de quien paga.
 *
 * Los dobles de WordPress los pone tests/stubs.php (cargado por el bootstrap).
 */

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class MetodoEnElEnlaceTest extends TestCase {

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

			$GLOBALS['__gw_meta']     = array();
			$GLOBALS['__gw_inserted'] = array();
			$GLOBALS['__gw_hijos']    = array();
			// El arnés solo conoce los posts que están en estos almacenes: la ficha de origen del pago
			// tiene que estar, o `create_payment()` responde «ID de origen no válido» (medido al estrenar
			// esta prueba).
			$GLOBALS['__gw_meta'][900] = array( '_convoca_plan' => 'bronze' );
			$GLOBALS['__gw_options']  = array(
				'convoca_gateway_settings' => array(
					'iban'        => 'ES00 0000 0000 0000 0000 0000',
					'beneficiary' => 'Asociación de Ejemplo',
				),
			);

			if ( method_exists( '\Convoca\Gateway\Redsys_Client', 'clear_cache' ) ) {
				\Convoca\Gateway\Redsys_Client::clear_cache();
			}
		}

		/** Crea un pago con el método indicado y devuelve el enlace que recibe quien paga. */
		private function enlace( string $metodo ): string {
			$pago = \Convoca\Gateway\Payment_Handler::create_payment(
				array(
					'amount_cents' => 3000,
					'origin'       => 'members',
					'origin_id'    => 900,
					'product_desc' => 'Cuota anual',
					'method'       => $metodo,
				)
			);

			$this->assertIsArray( $pago, 'create_payment tiene que devolver el pago creado' );
			$this->assertArrayHasKey( 'payment_url', $pago );

			return (string) $pago['payment_url'];
		}

		/** Con método elegido, el enlace lo lleva: es lo que hace que la página muestre SUS instrucciones. */
		public function test_el_metodo_elegido_viaja_en_el_enlace(): void {
			$url = $this->enlace( 'transferencia' );

			$this->assertStringContainsString(
				'convoca_gateway_method=transferencia',
				$url,
				'Sin el método, la página de pago cae al selector y ofrece tarjeta y Bizum a quien eligió transferencia'
			);
			$this->assertStringContainsString( 'convoca_gateway_key=', $url, 'Y sigue llevando su token' );
		}

		/** Y con «any» (enlace de donación) no se impone nada: elige quien paga. */
		public function test_sin_metodo_elegido_el_enlace_no_lo_lleva(): void {
			$this->assertStringNotContainsString( 'convoca_gateway_method', $this->enlace( 'any' ) );
			$this->assertStringNotContainsString( 'convoca_gateway_method', $this->enlace( '' ) );
		}
	}
}
