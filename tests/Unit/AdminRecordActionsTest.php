<?php
/**
 * Borrado y edición de registros desde el escritorio: identificadores, aviso de
 * confirmación y borrado efectivo.
 *
 * Los guardas de petición (nonce, capacidad) se comprueban en el sitio real: aquí
 * se prueba la parte reutilizable, que es donde están las decisiones.
 */

namespace {
	if ( ! isset( $GLOBALS['__gw_meta'] ) ) {
		$GLOBALS['__gw_meta'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_posts'] ) ) {
		$GLOBALS['__gw_posts'] = array();
	}
	/** El código real llama a estas de WordPress: aquí, versión mínima. */
	if ( ! function_exists( 'absint' ) ) {
		function absint( $v ) {
			return abs( (int) $v );
		}
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $cap, ...$args ) {
			return true;
		}
	}
	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( $s ) {
			return $s;
		}
	}
	if ( ! function_exists( 'wp_nonce_field' ) ) {
		function wp_nonce_field( $action, $name = '_wpnonce' ) {
			echo '<input type="hidden" name="' . $name . '">';
		}
	}
	if ( ! function_exists( 'get_post_type' ) ) {
		function get_post_type( $post = null ) {
			$id = is_object( $post ) ? (int) $post->ID : (int) $post;

			return $GLOBALS['__gw_posts'][ $id ] ?? false;
		}
	}
	if ( ! function_exists( 'wp_delete_post' ) ) {
		/** Registra el borrado y lo refleja en los almacenes del harness. */
		function wp_delete_post( $id, $force = false ) {
			$id = (int) $id;
			$GLOBALS['__gw_deleted'][] = array( 'id' => $id, 'force' => $force );
			unset( $GLOBALS['__gw_posts'][ $id ], $GLOBALS['__gw_meta'][ $id ] );

			return (object) array( 'ID' => $id );
		}
	}
	if ( ! function_exists( '_n' ) ) {
		function _n( $single, $plural, $number, $domain = null ) {
			return 1 === (int) $number ? $single : $plural;
		}
	}
	if ( ! function_exists( 'checked' ) ) {
		function checked( $checked, $current = true, $display = true ) {
			$r = ( $checked == $current ) ? " checked='checked'" : '';
			if ( $display ) {
				echo $r;
			}

			return $r;
		}
	}
	if ( ! function_exists( 'post_type_exists' ) ) {
		function post_type_exists( $tipo ) {
			return 'pago' === $tipo;
		}
	}
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = array() ) {
			return $GLOBALS['__gw_hijos'] ?? array();
		}
	}
	if ( ! function_exists( 'wp_die' ) ) {
		function wp_die( $msg ) {
			throw new RuntimeException( 'wp_die: ' . ( is_scalar( $msg ) ? $msg : '?' ) );
		}
	}
}

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class AdminRecordActionsTest extends TestCase {

		protected function setUp(): void {
			foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Admin_Record_Actions' ) as $class ) {
				$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}

			$GLOBALS['__gw_meta']    = array();
			$GLOBALS['__gw_posts']   = array();
			$GLOBALS['__gw_deleted'] = array();
			$GLOBALS['__gw_hijos']   = array();
			$_GET                    = array();
			$_POST                   = array();
			$_REQUEST                = array();  // en producción $_REQUEST = GET + POST
		}

		protected function tearDown(): void {
			$_GET     = array();
			$_POST    = array();
			$_REQUEST = array();
		}

		/**
		 * Crea un registro de pago en los almacenes del harness.
		 *
		 * Las claves se guardan como las escribe el plugin (`_convoca_*`), que es lo
		 * que lee get_post_meta.
		 */
		private function registro( int $id, array $meta = array() ): int {
			$GLOBALS['__gw_posts'][ $id ] = 'pago';
			$GLOBALS['__gw_meta'][ $id ]  = array();

			foreach ( $meta as $clave => $valor ) {
				$GLOBALS['__gw_meta'][ $id ][ '_convoca_' . $clave ] = $valor;
			}

			return $id;
		}

		/** Ejecuta algo que imprime y devuelve su salida. */
		private function capturar( callable $fn ): string {
			ob_start();
			$fn();

			return (string) ob_get_clean();
		}

		// ── Identificadores ────────────────────────────────────────────

		public function test_requested_ids_reads_a_single_row_action(): void {
			$this->registro( 41 );
			$_GET                 = array(
				'action' => 'delete',
				'id'     => '41',
			);
			$_REQUEST['id']       = '41';
			$_REQUEST['action']   = 'delete';

			$this->assertSame( array( 41 ), \Convoca\Gateway\Admin_Record_Actions::requested_ids( 'pago' ) );
		}

		public function test_requested_ids_reads_the_bulk_selection_of_both_lists(): void {
			$this->registro( 41 );
			$this->registro( 42 );

			$_GET          = array( 'pago' => array( '41', '42' ) );
			$_REQUEST['pago'] = array( '41', '42' );
			$this->assertSame( array( 41, 42 ), \Convoca\Gateway\Admin_Record_Actions::requested_ids( 'pago' ) );

			$_GET             = array();
			$_REQUEST         = array( 'enlace' => array( '42' ) );
			$this->assertSame( array( 42 ), \Convoca\Gateway\Admin_Record_Actions::requested_ids( 'enlace' ) );
		}

		public function test_requested_ids_discards_junk_duplicates_and_other_post_types(): void {
			$this->registro( 41 );
			$this->registro( 42 );
			$GLOBALS['__gw_posts'][ 43 ] = 'page'; // una página no se borra desde aquí

			$_REQUEST['pago'] = array( '41', '41', '43', '0', 'abc', '-7', array( 'x' ) );

			$this->assertSame(
				array( 41 ),
				\Convoca\Gateway\Admin_Record_Actions::requested_ids( 'pago' ),
				'Solo entran enteros positivos que sean registros de pago.'
			);
		}

		// ── Acción pendiente ───────────────────────────────────────────

		public function test_pending_action_covers_the_bulk_selector_and_the_second_button(): void {
			$_REQUEST = array( 'action' => 'convoca_delete' );
			$this->assertSame( 'convoca_delete', \Convoca\Gateway\Admin_Record_Actions::pending_action() );

			$_REQUEST = array(
				'action'  => '-1',
				'action2' => 'convoca_delete',
			);
			$this->assertSame( 'convoca_delete', \Convoca\Gateway\Admin_Record_Actions::pending_action() );

			$_REQUEST = array( 'action' => 'delete' );
			$this->assertSame( '', \Convoca\Gateway\Admin_Record_Actions::pending_action(), 'El borrado de una fila se atiende aparte.' );

			$_REQUEST = array( 'action' => 'bitcoin' );
			$this->assertSame( '', \Convoca\Gateway\Admin_Record_Actions::pending_action() );
		}

		// ── Pantalla de confirmación ───────────────────────────────────

		public function test_confirm_screen_lists_what_will_be_deleted_and_asks(): void {
			$this->registro(
				77,
				array(
					'order_id'     => '260910ABCDEF',
					'amount_cents' => 5000,
					'status'       => 'pending',
					'created_at'   => '2026-09-10 00:00:00',
					'origin'       => 'members',
				)
			);

			$html = $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::render_confirm( 'pago', array( 77 ) ) );

			$this->assertStringContainsString( 'Esta acción no se puede deshacer', $html );
			$this->assertStringContainsString( '260910ABCDEF', $html );
			$this->assertStringContainsString( '50,00 €', $html );
			$this->assertStringContainsString( 'name="ids[]" value="77"', $html );
			$this->assertStringContainsString( 'name="tipo" value="pago"', $html );
			$this->assertStringContainsString( 'convoca_gateway_delete_records', $html );
			$this->assertStringContainsString( 'Cancelar', $html );
		}

		public function test_confirm_screen_warns_about_a_paid_payment_with_receipt(): void {
			$this->registro(
				78,
				array(
					'order_id'     => '260910ZZZZZZ',
					'amount_cents' => 1550,
					'status'       => 'paid',
					'paid_at'      => '2026-09-09 12:00:00',
					'created_at'   => '2026-09-09 11:00:00',
				)
			);
			update_post_meta( 78, '_convoca_receipt_number', 'D-2026-003' );

			$html = $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::render_confirm( 'pago', array( 78 ) ) );

			$this->assertStringContainsString( 'PAGADO', $html );
			$this->assertStringContainsString( 'D-2026-003', $html );
			$this->assertStringContainsString( 'Cobrado el 2026-09-09 12:00:00', $html );
		}

		public function test_confirm_screen_says_that_donations_are_kept_when_deleting_a_link(): void {
			$this->registro(
				90,
				array(
					'order_id'     => '260910LINK01',
					'open_amount'  => '1',
					'amount_cents' => 0,
					'product_desc' => 'Donativo',
					'status'       => 'pending',
					'expires_at'   => 0,
				)
			);
			$GLOBALS['__gw_hijos'] = array( 101, 102 );

			$html = $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::render_confirm( 'enlace', array( 90 ) ) );

			$this->assertStringContainsString( 'Importe libre', $html );
			$this->assertStringContainsString( 'se conservan', $html );
			$this->assertStringContainsString( '2', $html );
		}

		public function test_confirm_screen_without_selection_refuses(): void {
			$html = $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::render_confirm( 'pago', array() ) );

			$this->assertStringContainsString( 'no se ha seleccionado ningún registro', $html );
			$this->assertStringNotContainsString( 'name="ids[]"', $html );
		}

		// ── Borrado ────────────────────────────────────────────────────

		public function test_delete_records_removes_only_payments(): void {
			$this->registro( 1, array( 'order_id' => 'A', 'amount_cents' => 100, 'status' => 'pending', 'origin' => 'link_payment' ) );
			$this->registro( 2, array( 'order_id' => 'B', 'amount_cents' => 200, 'status' => 'paid', 'origin' => 'members' ) );
			$GLOBALS['__gw_posts'][ 3 ] = 'page';

			$borrados = \Convoca\Gateway\Admin_Record_Actions::delete_records( array( 1, 2, 3, 0 ) );

			$this->assertSame( 2, $borrados );
			$this->assertSame( array( 1, 2 ), array_column( $GLOBALS['__gw_deleted'], 'id' ) );
			$this->assertSame( array( true, true ), array_column( $GLOBALS['__gw_deleted'], 'force' ), 'El borrado es definitivo, no a la papelera.' );
			$this->assertArrayNotHasKey( 2, $GLOBALS['__gw_meta'], 'Los metadatos desaparecen con el registro.' );
		}

		public function test_delete_records_is_safe_with_nothing(): void {
			$this->assertSame( 0, \Convoca\Gateway\Admin_Record_Actions::delete_records( array() ) );
			$this->assertSame( 0, \Convoca\Gateway\Admin_Record_Actions::delete_records( array( 999 ) ), 'Un id que no existe no cuenta.' );
		}

		// ── Aviso de resultado ─────────────────────────────────────────

		public function test_result_notice_speaks_in_singular_and_plural(): void {
			$_GET = array( \Convoca\Gateway\Admin_Record_Actions::FLAG => '1' );
			$uno  = $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::maybe_notice( 'pago' ) );
			$this->assertStringContainsString( 'Se ha eliminado 1 registro', $uno );

			$_GET    = array( \Convoca\Gateway\Admin_Record_Actions::FLAG => '3' );
			$varios  = $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::maybe_notice( 'enlace' ) );
			$this->assertStringContainsString( 'Se han eliminado 3 registros', $varios );

			$_GET = array();
			$this->assertSame( '', $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::maybe_notice( 'pago' ) ) );
		}

		public function test_result_notice_reports_errors(): void {
			$_GET = array( \Convoca\Gateway\Admin_Record_Actions::FLAG_ERROR => '1' );
			$html = $this->capturar( static fn() => \Convoca\Gateway\Admin_Record_Actions::maybe_notice( 'pago' ) );

			$this->assertStringContainsString( 'notice-error', $html );
			$this->assertStringContainsString( 'permisos', $html );
		}

		// ── Cargas y URL de vuelta ─────────────────────────────────────

		public function test_each_list_returns_to_its_own_screen(): void {
			$this->assertStringContainsString( 'page=conv-gateway-payments', \Convoca\Gateway\Admin_Record_Actions::list_url( 'pago' ) );
			$this->assertStringContainsString( 'page=conv-gateway-links', \Convoca\Gateway\Admin_Record_Actions::list_url( 'enlace' ) );
			$this->assertSame( 'pago', \Convoca\Gateway\Admin_Record_Actions::field( 'pago' ) );
			$this->assertSame( 'enlace', \Convoca\Gateway\Admin_Record_Actions::field( 'enlace' ) );
		}
	}
}
