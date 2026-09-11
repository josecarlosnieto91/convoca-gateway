<?php
/**
 * El listado «Todos los Pagos»: qué muestra y qué no.
 *
 * Un enlace de pago es una plantilla y vive en «Enlaces de Pago»; si se cuela en el
 * listado de pagos se confunde con un pago pendiente y se borra sin querer (pasó de
 * verdad). Aquí se fija que la consulta los excluye y que los orígenes se leen en
 * castellano.
 *
 * Los dobles de WordPress los pone tests/stubs.php (cargado por el bootstrap).
 */

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class AdminPaymentsColumnsTest extends TestCase {

		private static $cargado = false;

		protected function setUp(): void {
			if ( ! self::$cargado ) {
				foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Admin_Payments', 'Admin_Links' ) as $class ) {
					$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
					if ( file_exists( $path ) ) {
						require_once $path;
					}
				}
				self::$cargado = true;
			}

			$GLOBALS['__gw_meta'] = array();
			$_GET                 = array();
		}

		/** Registro con metadatos como los escribe el plugin. */
		private function registro( int $id, array $meta ): \stdClass {
			$GLOBALS['__gw_meta'][ $id ] = array();

			foreach ( $meta as $clave => $valor ) {
				$GLOBALS['__gw_meta'][ $id ][ '_convoca_' . $clave ] = $valor;
			}

			return (object) array( 'ID' => $id );
		}

		private function tabla(): \Convoca\Gateway\Admin_Payments {
			return new \Convoca\Gateway\Admin_Payments();
		}

		public function test_origin_is_read_in_spanish_not_as_a_slug(): void {
			$tabla = $this->tabla();

			$this->assertSame( 'Inscripción', $tabla->column_origin( $this->registro( 1, array( 'origin' => 'enroll' ) ) ) );
			$this->assertSame( 'Socio/a', $tabla->column_origin( $this->registro( 2, array( 'origin' => 'members' ) ) ) );
			$this->assertSame( 'Donación', $tabla->column_origin( $this->registro( 3, array( 'origin' => 'donativo' ) ) ) );
			$this->assertSame( 'Formulario web', $tabla->column_origin( $this->registro( 4, array( 'origin' => 'manual' ) ) ) );
		}

		public function test_open_amount_is_not_shown_as_zero(): void {
			$tabla = $this->tabla();

			$this->assertSame( 'Importe libre', $tabla->column_amount( $this->registro( 20, array( 'amount_cents' => 0, 'open_amount' => '1' ) ) ) );
			$this->assertSame( '25,00 €', $tabla->column_amount( $this->registro( 21, array( 'amount_cents' => 2500 ) ) ) );
		}

		/** Un enlace no tiene «estado de pago»: es una plantilla, no un cobro pendiente. */
		public function test_the_links_list_has_no_payment_status_column(): void {
			$columnas = ( new \Convoca\Gateway\Admin_Links() )->get_columns();

			$this->assertArrayNotHasKey( 'status', $columnas, 'El estado del pago no aplica a un enlace.' );
			$this->assertArrayHasKey( 'active', $columnas, 'Lo que sí interesa: si sigue activo y cuántos cobros ha emitido.' );
		}

		/** La consulta tiene que dejar fuera los enlaces, y sin exigir el metadato. */
		public function test_the_query_leaves_payment_links_out(): void {
			$this->tabla()->prepare_items();

			$consulta = \WP_Query::$ultimo;
			$this->assertArrayHasKey( 'meta_query', $consulta, 'La consulta debe filtrar.' );

			$clausula = $consulta['meta_query']['convoca_sin_enlaces'] ?? null;
			$this->assertNotNull( $clausula, 'Falta la exclusión de enlaces.' );

			$planas = json_encode( $clausula );
			$this->assertStringContainsString( 'link_payment', $planas );
			$this->assertStringContainsString( 'NOT EXISTS', $planas, 'Los registros sin metadato de origen no deben quedar fuera.' );
			$this->assertStringContainsString( '!=', $planas );
		}
	}
}
