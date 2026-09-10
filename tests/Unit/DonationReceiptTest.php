<?php
/**
 * Recibo del donante: el email indicado durante el pago debe recibir la confirmación
 * aunque el aviso general de confirmaciones esté apagado; un pago normal sigue
 * dependiendo de ese ajuste.
 */

namespace {
	if ( ! isset( $GLOBALS['__gw_meta'] ) ) {
		$GLOBALS['__gw_meta'] = array();
	}
	if ( ! isset( $GLOBALS['__gw_emails'] ) ) {
		$GLOBALS['__gw_emails'] = array();
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
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
			return true;
		}
	}
	if ( ! function_exists( 'wp_mail' ) ) {
		function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
			$GLOBALS['__gw_emails'][] = compact( 'to', 'subject', 'message' );

			return true;
		}
	}
	if ( ! function_exists( 'wp_generate_password' ) ) {
		function wp_generate_password( $length = 12, $special = true, $extra = false ) {
			return substr( str_repeat( 'a1B2c3D4e5F6g7H8', 8 ), 0, $length );
		}
	}
	if ( ! function_exists( 'get_the_date' ) ) {
		function get_the_date( $format = '', $post = null ) {
			return '01/01/2026 12:00';
		}
	}
	if ( ! function_exists( 'get_bloginfo' ) ) {
		function get_bloginfo( $show = 'name' ) {
			return 'Entidad de prueba';
		}
	}
	if ( ! function_exists( 'set_url_scheme' ) ) {
		function set_url_scheme( $url, $scheme = null ) {
			return $url;
		}
	}
	if ( ! function_exists( 'get_permalink' ) ) {
		function get_permalink( $post = 0 ) {
			return 'https://example.com/pago/';
		}
	}
}

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class DonationReceiptTest extends TestCase {

		private function loadClasses(): void {
			foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Receipt_Generator', 'Payment_Handler', 'Email_Notifications' ) as $class ) {
				$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
		}

		protected function setUp(): void {
			$this->loadClasses();
			$GLOBALS['__gw_meta']   = array();
			$GLOBALS['__gw_emails'] = array();
		}

		/**
		 * Los ajustes llegan vacíos (get_option devuelve el default), así que
		 * email_confirmation está apagado: es justo el caso que hay que cubrir.
		 */
		public function test_donation_receipt_is_sent_even_with_general_notifications_off(): void {
			update_post_meta( 501, '_convoca_payer_email', 'dona@example.com' );
			update_post_meta( 501, '_convoca_receipt_always', '1' );
			update_post_meta( 501, '_convoca_amount_cents', 2000 );
			update_post_meta( 501, '_convoca_status', 'paid' );

			( new \Convoca\Gateway\Email_Notifications() )->send_success_email( 501, 'donativo', 77, array() );

			$this->assertCount( 1, $GLOBALS['__gw_emails'], 'El donante debe recibir su recibo aunque el aviso general esté apagado.' );
			$this->assertSame( 'dona@example.com', $GLOBALS['__gw_emails'][0]['to'] );
		}

		public function test_donation_without_email_sends_nothing(): void {
			update_post_meta( 502, '_convoca_receipt_always', '1' );
			update_post_meta( 502, '_convoca_amount_cents', 2000 );

			( new \Convoca\Gateway\Email_Notifications() )->send_success_email( 502, 'donativo', 77, array() );

			$this->assertCount( 0, $GLOBALS['__gw_emails'] );
		}

		public function test_normal_payment_is_still_gated_by_the_setting(): void {
			update_post_meta( 503, '_convoca_payer_email', 'socio@example.com' );
			update_post_meta( 503, '_convoca_amount_cents', 2500 );

			( new \Convoca\Gateway\Email_Notifications() )->send_success_email( 503, 'members', 12, array() );

			$this->assertCount( 0, $GLOBALS['__gw_emails'], 'Sin el ajuste activo, un pago normal no manda correo.' );
		}

		public function test_forcing_the_receipt_does_not_need_the_donation_marker(): void {
			update_post_meta( 504, '_convoca_payer_email', 'quien@paga.example' );
			update_post_meta( 504, '_convoca_receipt_always', '1' );

			( new \Convoca\Gateway\Email_Notifications() )->send_success_email( 504, 'link_payment', 0, array() );

			$this->assertCount( 1, $GLOBALS['__gw_emails'] );
			$this->assertSame( 'quien@paga.example', $GLOBALS['__gw_emails'][0]['to'] );
		}
	}
}
