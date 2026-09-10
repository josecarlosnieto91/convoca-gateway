<?php
/**
 * Enlaces de donativo: creación sin importe, un pago por aportación y datos del recibo.
 *
 * Pure/static logic plus a small in-memory meta store, same pattern as
 * ReceiptExpiryTest, so no full WordPress is needed.
 */

// ── Minimal in-memory stores (GLOBAL namespace, like WordPress) ──
namespace {
	if ( ! isset( $GLOBALS['__gw_meta'] ) ) {
		$GLOBALS['__gw_meta'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_inserted'] ) ) {
		$GLOBALS['__gw_inserted'] = array();
	}
	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $id, $key = '', $single = false ) {
			$vals = $GLOBALS['__gw_meta'][ (int) $id ][ $key ] ?? null;
			if ( $single ) {
				return $vals ?? '';
			}

			return null === $vals ? array() : ( is_array( $vals ) ? $vals : array( $vals ) );
		}
	}
	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $id, $key, $value ) {
			$GLOBALS['__gw_meta'][ (int) $id ][ $key ] = $value;
			return true;
		}
	}
	if ( ! function_exists( 'wp_insert_post' ) ) {
		/**
		 * Registra el post insertado para poder mirar el título.
		 */
		function wp_insert_post( $data, $error = false ) {
			$id                             = 9000 + count( $GLOBALS['__gw_inserted'] );
			$GLOBALS['__gw_inserted'][ $id ] = $data;

			return $id;
		}
	}
	if ( ! function_exists( 'wp_generate_password' ) ) {
		function wp_generate_password( $length = 12, $special = true, $extra = false ) {
			return substr( str_repeat( 'a1B2c3D4e5F6g7H8', 8 ), 0, $length );
		}
	}
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $format = 'mysql' ) {
			return '2026-01-01 00:00:00';
		}
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() {
			return 1;
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( strip_tags( (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_email' ) ) {
		function sanitize_email( $email ) {
			return filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
		}
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() {
			return (object) array(
				'ID'           => 1,
				'display_name' => 'Admin',
				'user_login'   => 'admin',
			);
		}
	}
	if ( ! function_exists( 'wp_date' ) ) {
		function wp_date( $format, $timestamp = null ) {
			return gmdate( $format, $timestamp ?? time() );
		}
	}
}

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class DonationLinkTest extends TestCase {

		private function loadClasses(): void {
			foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry' ) as $class ) {
				$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
		}

		protected function setUp(): void {
			$this->loadClasses();
			$GLOBALS['__gw_meta']     = array();
			$GLOBALS['__gw_inserted'] = array();
		}

		private function titleOf( int $id ): string {
			return (string) ( $GLOBALS['__gw_inserted'][ $id ]['post_title'] ?? '' );
		}

		// ── Enlace de donativo: sin importe ────────────────────────────

		public function test_open_amount_link_is_created_without_amount(): void {
			$id = \Convoca\Gateway\CPT_Pago::create_link_payment(
				array(
					'amount'      => 0,
					'open_amount' => true,
					'concepto'    => 'Donativo',
					'method'      => 'any',
					'expires_at'  => 'never',
				)
			);

			$this->assertIsInt( $id, 'Un enlace de donativo debe poder crearse sin importe.' );
			$this->assertSame( '1', get_post_meta( $id, '_convoca_open_amount', true ) );
			$this->assertSame( 0, (int) get_post_meta( $id, '_convoca_amount_cents', true ) );
			$this->assertStringContainsString( 'importe libre', $this->titleOf( $id ) );
		}

		public function test_open_amount_link_is_marked_from_data_not_guessed(): void {
			$id = \Convoca\Gateway\CPT_Pago::create_link_payment(
				array(
					'amount'      => 0,
					'open_amount' => true,
					'concepto'    => 'Donativo',
				)
			);

			$this->assertSame( '1', get_post_meta( $id, '_convoca_open_amount', true ) );
		}

		// ── El mínimo sigue vigente en los enlaces normales ────────────

		public function test_payment_link_still_requires_the_minimum_amount(): void {
			$result = \Convoca\Gateway\CPT_Pago::create_link_payment(
				array(
					'amount'   => 0.10,
					'concepto' => 'Cuota',
				)
			);

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'invalid_amount', $result->get_error_code() );
		}

		public function test_payment_link_without_flag_keeps_requiring_amount(): void {
			$result = \Convoca\Gateway\CPT_Pago::create_link_payment(
				array(
					'amount'   => 0,
					'concepto' => 'Cuota',
				)
			);

			$this->assertInstanceOf( \WP_Error::class, $result );
		}

		public function test_normal_link_is_not_marked_as_open_amount(): void {
			$id = \Convoca\Gateway\CPT_Pago::create_link_payment(
				array(
					'amount'   => 25,
					'concepto' => 'Cuota',
				)
			);

			$this->assertIsInt( $id );
			$this->assertSame( '', get_post_meta( $id, '_convoca_open_amount', true ) );
			$this->assertSame( 2500, (int) get_post_meta( $id, '_convoca_amount_cents', true ) );
		}

		// ── Pago de donativo: email del pagador y recibo ───────────────

		public function test_donation_payment_records_payer_email_and_receipt_order(): void {
			$id = \Convoca\Gateway\CPT_Pago::create_link_payment(
				array(
					'amount'         => 15.50,
					'concepto'       => 'Donativo',
					'method'         => 'tarjeta',
					'payer_email'    => 'dona@example.com',
					'receipt_always' => '1',
					'es_donacion'    => '1',
					'expires_at'     => 'never',
					'origin'         => 'donativo',
					'origin_id'      => 77,
				)
			);

			$this->assertIsInt( $id );
			$this->assertSame( 'dona@example.com', get_post_meta( $id, '_convoca_payer_email', true ) );
			$this->assertSame( '1', get_post_meta( $id, '_convoca_receipt_always', true ) );
			$this->assertSame( '1', get_post_meta( $id, '_convoca_es_donacion', true ), 'El recibo debe usar la serie de donativos.' );
			$this->assertSame( 'donativo', get_post_meta( $id, '_convoca_origin', true ) );
			$this->assertSame( 77, (int) get_post_meta( $id, '_convoca_origin_id', true ), 'El pago debe apuntar al enlace del que nace.' );
			$this->assertSame( 1550, (int) get_post_meta( $id, '_convoca_amount_cents', true ) );
			$this->assertSame( 'tarjeta', get_post_meta( $id, '_convoca_method', true ) );
		}

		public function test_donation_without_email_does_not_force_the_receipt(): void {
			$id = \Convoca\Gateway\CPT_Pago::create_link_payment(
				array(
					'amount'         => 5,
					'concepto'       => 'Donativo',
					'payer_email'    => '',
					'receipt_always' => '',
					'es_donacion'    => '1',
					'expires_at'     => 'never',
					'origin'         => 'donativo',
				)
			);

			$this->assertSame( '', get_post_meta( $id, '_convoca_payer_email', true ) );
			$this->assertSame( '', get_post_meta( $id, '_convoca_receipt_always', true ) );
		}
	}
}
