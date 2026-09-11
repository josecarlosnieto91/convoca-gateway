<?php
/**
 * Recibo del donante: el email indicado durante el pago debe recibir la confirmación
 * aunque el aviso general de confirmaciones esté apagado; un pago normal sigue
 * dependiendo de ese ajuste.
 */

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
