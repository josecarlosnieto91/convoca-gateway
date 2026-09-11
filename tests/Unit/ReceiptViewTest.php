<?php
/**
 * El recibo es un documento propio: sin el tema, con la entidad, el pagador, el
 * método, el estado y el texto legal solo cuando es una donación.
 */

namespace Convoca\Gateway\Tests {

	use PHPUnit\Framework\TestCase;

	class ReceiptViewTest extends TestCase {

		private static $cargado = false;

		protected function setUp(): void {
			if ( ! self::$cargado ) {
				foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Payment_Handler', 'Email_Notifications', 'Receipt_Generator', 'Receipt_View' ) as $class ) {
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
					'org_name'    => 'Asociación de prueba',
					'org_cif'     => 'G-00000000',
					'org_address' => 'Calle de prueba 1',
				),
			);
		}

		private function pago( string $estado = 'paid', array $extra = array() ): int {
			$GLOBALS['__gw_meta'][ 3001 ] = array_merge(
				array(
					'_convoca_status'          => $estado,
					'_convoca_receipt_key'     => 'clave-recibo',
					'_convoca_receipt_number'  => '',
					'_convoca_amount_cents'    => 1250,
					'_convoca_currency'        => 'EUR',
					'_convoca_method'          => 'bizum',
					'_convoca_order_id'        => '260908MLSRTL',
					'_convoca_product_desc'    => 'Aportación para el taller',
					'_convoca_origin'          => 'enlace',
					'_convoca_paid_at'         => '2026-09-08 09:45:04',
					'_convoca_payer_email'     => 'socia@example.com',
					'_convoca_es_donacion'     => '',
				),
				$extra
			);

			return 3001;
		}

		// — Los datos ————————————————————————————————————

		public function test_sin_clave_valida_no_hay_recibo(): void {
			$this->pago();
			$this->assertArrayHasKey( 'error', \Convoca\Gateway\Receipt_View::data( 3001, 'otra-clave' ) );
			$this->assertArrayHasKey( 'error', \Convoca\Gateway\Receipt_View::data( 9999, 'clave-recibo' ) );
		}

		public function test_un_pago_sin_confirmar_no_tiene_recibo(): void {
			$this->pago( 'pending' );
			$r = \Convoca\Gateway\Receipt_View::data( 3001, 'clave-recibo' );
			$this->assertArrayHasKey( 'error', $r );
			$this->assertStringContainsString( 'una vez confirmado', $r['error'] );
		}

		public function test_el_recibo_lleva_los_datos_de_la_entidad_y_del_pago(): void {
			$this->pago();
			$d = \Convoca\Gateway\Receipt_View::data( 3001, 'clave-recibo' );

			$this->assertSame( 'Recibo de pago', $d['title'] );
			$this->assertSame( 'Asociación de prueba', $d['org']['name'] );
			$this->assertSame( 'G-00000000', $d['org']['cif'] );
			$this->assertSame( 'Calle de prueba 1', $d['org']['address'] );
			$this->assertSame( 'Aportación para el taller', $d['concept'] );
			$this->assertSame( '12,50 €', $d['amount'] );
			$this->assertSame( 'Bizum', $d['method'], 'El método se enseña legible, no como clave interna.' );
			$this->assertSame( '260908MLSRTL', $d['reference'] );
			$this->assertSame( 'Pagado', $d['status'] );
			$this->assertSame( '08/09/2026 09:45', $d['paid_at'] );
			$this->assertSame( 'socia@example.com', $d['payer_email'] );
			$this->assertSame( '', $d['legal'], 'Un pago normal no lleva texto de donativo.' );
			$this->assertNotSame( '', $d['number'], 'El recibo lleva número anual.' );
		}

		public function test_una_donacion_lleva_su_texto_legal_y_su_titulo(): void {
			$this->pago( 'paid', array( '_convoca_es_donacion' => '1' ) );
			$d = \Convoca\Gateway\Receipt_View::data( 3001, 'clave-recibo' );

			$this->assertSame( 'Justificante de donación', $d['title'] );
			$this->assertStringContainsString( 'Ley 49/2002', $d['legal'] );
		}

		// — El documento ————————————————————————————————

		public function test_el_documento_es_propio_y_no_depende_del_tema(): void {
			$this->pago();
			$html = \Convoca\Gateway\Receipt_View::html( \Convoca\Gateway\Receipt_View::data( 3001, 'clave-recibo' ) );

			$this->assertStringContainsString( '<!DOCTYPE html>', $html, 'Es un documento completo, no un fragmento dentro de la página.' );
			$this->assertStringContainsString( '@page', $html, 'Trae márgenes de impresión propios.' );
			$this->assertStringContainsString( 'G-00000000', $html );
			$this->assertStringContainsString( '12,50 €', $html );
			$this->assertStringContainsString( 'Bizum', $html );
			$this->assertStringContainsString( 'Pagado', $html );
			$this->assertStringContainsString( '260908MLSRTL', $html );
			$this->assertStringNotContainsString( '<header', $html, 'Nada del tema dentro del recibo.' );
			$this->assertStringNotContainsString( 'wp-block', $html );
		}

		public function test_el_boton_de_imprimir_no_sale_en_el_papel(): void {
			$this->pago();
			$html = \Convoca\Gateway\Receipt_View::html( \Convoca\Gateway\Receipt_View::data( 3001, 'clave-recibo' ) );

			$this->assertStringContainsString( 'window.print()', $html );

			// El botón no debe salir en el papel: la regla de impresión lo oculta.
			$impresion = substr( $html, (int) strpos( $html, '@media print' ) );
			$this->assertStringContainsString( '.acciones', $impresion, 'La regla de impresión habla del botón.' );
			$this->assertStringContainsString( 'display: none', $impresion, 'Y lo oculta.' );
		}

		public function test_si_no_hay_datos_de_entidad_el_recibo_no_se_rompe(): void {
			$GLOBALS['__gw_options']['convoca_gateway_settings'] = array();
			$this->pago();
			$d    = \Convoca\Gateway\Receipt_View::data( 3001, 'clave-recibo' );
			$html = \Convoca\Gateway\Receipt_View::html( $d );

			$this->assertSame( '', $d['org']['cif'] );
			$this->assertStringNotContainsString( 'CIF/NIF', $html, 'Sin CIF configurado no se enseña el rótulo vacío.' );
			$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		}

		public function test_un_recibo_que_no_existe_enseña_el_motivo(): void {
			$html = \Convoca\Gateway\Receipt_View::html( array( 'error' => 'Recibo no encontrado.' ) );
			$this->assertStringContainsString( 'Recibo no encontrado.', $html );
			$this->assertStringNotContainsString( 'window.print()', $html, 'Sin recibo no hay botón de imprimir.' );
		}
	}
}
