<?php
/**
 * Unit tests for D21-D24: link expiry, receipt numbering and Redsys code mapping.
 *
 * Pure/static logic plus a small in-memory meta store so the "regenerate link
 * keeps the same order" behaviour can be asserted without a full WordPress.
 */

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class ReceiptExpiryTest extends TestCase {

		private function loadClass( string $file ): void {
			$path = dirname( __DIR__, 2 ) . "/includes/$file";
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		protected function setUp(): void {
			$this->loadClass( 'Link_Expiry.php' );
			$this->loadClass( 'Receipt_Generator.php' );
			$this->loadClass( 'Payment_Handler.php' );
			$this->loadClass( 'Redsys_Client.php' );
		}

		// ── D23/D24: cálculo de numeración anual ───────────────────────

		public function test_format_receipt_number_zero_pads_sequence(): void {
			$this->assertSame( '2026-001', \Convoca\Gateway\Receipt_Generator::format_number( 2026, 1 ) );
			$this->assertSame( '2026-042', \Convoca\Gateway\Receipt_Generator::format_number( 2026, 42 ) );
			$this->assertSame( '2026-1000', \Convoca\Gateway\Receipt_Generator::format_number( 2026, 1000 ) );
		}

		public function test_format_receipt_number_donation_prefix(): void {
			$this->assertSame( 'D-2026-005', \Convoca\Gateway\Receipt_Generator::format_number( 2026, 5, 'D' ) );
		}

		public function test_format_receipt_number_other_year(): void {
			$this->assertSame( '2027-001', \Convoca\Gateway\Receipt_Generator::format_number( 2027, 1 ) );
		}

		// ── D21: caducidad por defecto 7 días ─────────────────────────

		public function test_default_expiry_days_defaults_to_seven(): void {
			$this->assertSame( 7, \Convoca\Gateway\Link_Expiry::default_expiry_days() );
		}

		public function test_default_notice_hours_defaults_to_twenty_four(): void {
			$this->assertSame( 24, \Convoca\Gateway\Link_Expiry::notice_hours() );
		}

		public function test_compute_expiry_timestamp_adds_days(): void {
			$this->assertSame(
				1000000 + ( 7 * 86400 ),
				\Convoca\Gateway\Link_Expiry::compute_expiry_timestamp( 7, 1000000 )
			);
		}

		// ── D21c/D22c: regeneración mantiene el mismo order ────────────

		public function test_regenerate_link_keeps_order_id_and_refreshes_expiry(): void {
			$id = 123;
			// El bootstrap ya define get_post() (tipo por ID, 'miembro' por defecto);
			// aquí el pago tiene que ser de tipo 'pago' para que no devuelva
			// pago_not_found.
			$GLOBALS['_wp_stores']['post_types'][ $id ] = 'pago';
			$GLOBALS['__gw_meta'][ $id ] = array(
				'_convoca_order_id'   => '260901ABCDEF',
				'_convoca_link_key'   => 'testtoken',
				'_convoca_expires_at' => 1000,
				'_convoca_status'     => 'pending',
			);

			$url = \Convoca\Gateway\Link_Expiry::regenerate_link( $id );

			$this->assertIsString( $url );
			$this->assertStringContainsString( 'convoca_gateway_pago=123', $url );
			$this->assertStringContainsString( 'convoca_gateway_key=testtoken', $url );

			// El order id no cambia (mismo pago).
			$this->assertSame( '260901ABCDEF', \get_post_meta( $id, '_convoca_order_id', true ) );

			// La caducidad se renueva a un timestamp futuro.
			$new_expiry = \get_post_meta( $id, '_convoca_expires_at', true );
			$this->assertIsInt( $new_expiry );
			$this->assertGreaterThan( 1000, $new_expiry );
		}

		public function test_regenerate_link_rejects_missing_payment(): void {
			// IDs sin entrada en el store no tienen tipo 'pago' → WP_Error.
			$result = \Convoca\Gateway\Link_Expiry::regenerate_link( 99999 );
			$this->assertInstanceOf( \WP_Error::class, $result );
		}

		// ── D22: mapa de código Redsys a texto ─────────────────────────

		public function test_response_message_approved(): void {
			$this->assertSame( 'Transacción autorizada', \Convoca\Gateway\Redsys_Client::get_response_message( '0000' ) );
		}

		public function test_response_message_known_code(): void {
			$this->assertSame( 'Tarjeta ajena al servicio', \Convoca\Gateway\Redsys_Client::get_response_message( '0180' ) );
			$this->assertSame( 'Usuario ha cancelado el pago', \Convoca\Gateway\Redsys_Client::get_response_message( '9915' ) );
		}

		public function test_response_message_unknown_code_fallback(): void {
			$this->assertSame( 'Denegación o error desconocido (Código: 12345)', \Convoca\Gateway\Redsys_Client::get_response_message( '12345' ) );
		}
	}
}
