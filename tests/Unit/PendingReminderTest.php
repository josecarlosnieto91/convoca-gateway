<?php
/**
 * Un pago que se quedó a medias y con correo se recuerda una vez, media hora después.
 *
 * Las reglas viven en `should_remind()` y se prueban una por una: cobrado, sin correo,
 * ya recordado, enlace caducado, todavía a tiempo y sin fecha. El corte de la media
 * hora se comprueba por los dos lados, y la zona horaria (la fecha local antigua se
 * interpreta con el desfase del sitio) tiene su propio caso, porque es donde este tipo
 * de aviso se rompe sin que nadie lo note.
 */

namespace Convoca\Gateway\Tests {

	use PHPUnit\Framework\TestCase;

	class PendingReminderTest extends TestCase {

		private const AHORA = 1800000000;

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

			$GLOBALS['__gw_meta']     = array();
			$GLOBALS['__gw_hijos']    = array();
			$GLOBALS['__gw_emails']   = array();
			$GLOBALS['__gw_options']  = array(
				'convoca_gateway_settings' => array(),
				'gmt_offset'               => 2,
			);
		}

		/** Meta de un pago normal: pendiente, con correo, hecho hace 31 minutos. */
		private function meta( array $extra = array() ): array {
			return array_merge(
				array(
					'_convoca_status'        => 'pending',
					'_convoca_payer_email'   => 'socia@example.com',
					'_convoca_expires_at'    => 0,
					'_convoca_created_ts'    => self::AHORA - 1860,
					'_convoca_amount_cents'  => 1200,
					'_convoca_product_desc'  => 'Donativo',
					'_convoca_link_key'      => 'token-de-prueba',
					'_convoca_origin'        => 'donativo',
				),
				$extra
			);
		}

		private function recordar( array $meta ): bool {
			return \Convoca\Gateway\Email_Notifications::should_remind( $meta, self::AHORA );
		}

		// — Las reglas ————————————————————————————————————————————

		public function test_un_pago_a_medias_con_correo_se_recuerda(): void {
			$this->assertTrue( $this->recordar( $this->meta() ) );
		}

		public function test_la_media_hora_se_mira_por_los_dos_lados(): void {
			$this->assertFalse(
				$this->recordar( $this->meta( array( '_convoca_created_ts' => self::AHORA - 1799 ) ) ),
				'A los 29 minutos y 59 segundos todavía está a tiempo: no se le da la lata.'
			);
			$this->assertTrue(
				$this->recordar( $this->meta( array( '_convoca_created_ts' => self::AHORA - 1800 ) ) ),
				'A los 30 minutos exactos ya se avisa.'
			);
		}

		public function test_un_pago_cobrado_no_se_recuerda(): void {
			$this->assertFalse( $this->recordar( $this->meta( array( '_convoca_status' => 'paid' ) ) ) );
			$this->assertFalse( $this->recordar( $this->meta( array( '_convoca_status' => 'failed' ) ) ) );
			$this->assertFalse( $this->recordar( $this->meta( array( '_convoca_status' => '' ) ) ) );
		}

		public function test_sin_correo_no_se_puede_avisar(): void {
			$this->assertFalse( $this->recordar( $this->meta( array( '_convoca_payer_email' => '' ) ) ) );

			// Un correo que no es un correo tampoco vale.
			$this->assertFalse( $this->recordar( $this->meta( array( '_convoca_payer_email' => 'no-es-un-correo' ) ) ) );
		}

		public function test_el_correo_del_enlace_sirve_cuando_no_hay_el_de_quien_paga(): void {
			$this->assertFalse(
				$this->recordar( $this->meta( array( '_convoca_payer_email' => '', '_convoca_recipient_email' => '' ) ) )
			);
			$this->assertTrue(
				$this->recordar( $this->meta( array( '_convoca_payer_email' => '', '_convoca_recipient_email' => 'enlace@example.com' ) ) )
			);
		}

		public function test_a_quien_se_avisa_es_a_quien_paga(): void {
			$email = \Convoca\Gateway\Email_Notifications::reminder_email(
				$this->meta( array( '_convoca_payer_email' => 'paga@example.com', '_convoca_recipient_email' => 'enlace@example.com' ) )
			);
			$this->assertSame( 'paga@example.com', $email );
		}

		public function test_un_aviso_no_es_una_campana(): void {
			$this->assertFalse(
				$this->recordar( $this->meta( array( '_convoca_reminder_sent' => self::AHORA - 60 ) ) ),
				'Si ya se le avisó, no se le vuelve a insistir.'
			);
		}

		public function test_si_el_enlace_ya_caduco_no_se_manda_un_boton_que_no_lleva_a_ninguna_parte(): void {
			$this->assertFalse(
				$this->recordar( $this->meta( array( '_convoca_expires_at' => self::AHORA - 1 ) ) )
			);
			$this->assertTrue(
				$this->recordar( $this->meta( array( '_convoca_expires_at' => self::AHORA + 3600 ) ) ),
				'Si al enlace le queda tiempo, el botón sirve y se avisa.'
			);
		}

		public function test_un_pago_sin_fecha_conocida_no_se_recuerda(): void {
			$this->assertFalse(
				$this->recordar( $this->meta( array( '_convoca_created_ts' => 0, '_convoca_created_at' => '' ) ) ),
				'Antes no avisar que avisar mal.'
			);
		}

		public function test_un_pago_antiguo_con_fecha_local_se_interpreta_con_el_desfase_del_sitio(): void {
			// Creado a las 13:00 hora del sitio (UTC+2). A las 12:00 UTC han pasado 60
			// minutos: toca avisar. Sin el desfase parecerían 2 horas de más.
			$viejo = array(
				'_convoca_created_ts' => 0,
				'_convoca_created_at' => gmdate( 'Y-m-d H:i:s', self::AHORA - 3600 + 7200 ),
			);
			$this->assertTrue( $this->recordar( $this->meta( $viejo ) ) );

			// Recién creado en hora local: todavía no.
			$recien = array(
				'_convoca_created_ts' => 0,
				'_convoca_created_at' => gmdate( 'Y-m-d H:i:s', self::AHORA - 300 + 7200 ),
			);
			$this->assertFalse( $this->recordar( $this->meta( $recien ) ) );
		}

		public function test_a_una_plantilla_de_enlace_no_se_le_recuerda_nada(): void {
			$this->assertFalse(
				$this->recordar( $this->meta( array( '_convoca_origin' => 'link_payment' ) ) ),
				'Una plantilla de enlace no es un cobro: no se avisa a su destinatario.'
			);
		}

		public function test_un_importe_de_cero_no_es_un_pago_que_completar(): void {
			$this->assertFalse(
				$this->recordar( $this->meta( array( '_convoca_amount_cents' => 0 ) ) ),
				'No se le puede recordar un pago de 0,00 €.'
			);
		}

		// — El envío ————————————————————————————————————————————

		public function test_el_aviso_lleva_importe_boton_y_salida(): void {
			$GLOBALS['__gw_meta'][ 501 ] = $this->meta();

			$this->assertTrue( \Convoca\Gateway\Email_Notifications::send_pending_reminder( 501, self::AHORA ) );
			$this->assertCount( 1, $GLOBALS['__gw_emails'] );

			$correo = $GLOBALS['__gw_emails'][0];
			$this->assertSame( 'socia@example.com', $correo['to'] );
			$this->assertStringContainsString( '12,00 €', $correo['subject'] );
			$this->assertStringContainsString( '12,00 €', $correo['message'] );
			$this->assertStringContainsString( 'Donativo', $correo['message'] );
			$this->assertStringContainsString( 'Completar el pago', $correo['message'] );
			$this->assertStringContainsString( 'pago/', $correo['message'], 'El botón lleva al pago.' );
			$this->assertStringContainsString( 'No se te ha cobrado nada', $correo['message'], 'Se le dice claro que no se le ha cobrado.' );
		}

		public function test_si_no_toca_no_se_manda_nada(): void {
			$GLOBALS['__gw_meta'][ 502 ] = $this->meta( array( '_convoca_status' => 'paid' ) );

			$this->assertFalse( \Convoca\Gateway\Email_Notifications::send_pending_reminder( 502, self::AHORA ) );
			$this->assertCount( 0, $GLOBALS['__gw_emails'] );
		}

		public function test_una_plantilla_propia_sustituye_a_la_de_serie(): void {
			$GLOBALS['__gw_meta'][ 503 ] = $this->meta();
			$GLOBALS['__gw_options']['convoca_gateway_settings'] = array(
				'email_pending_subject' => 'Te quedó algo a medias: {importe}',
				'email_pending_body'    => '<p>Retómalo aquí: {enlace_pago}</p>',
			);

			\Convoca\Gateway\Email_Notifications::send_pending_reminder( 503, self::AHORA );

			$correo = $GLOBALS['__gw_emails'][0];
			$this->assertSame( 'Te quedó algo a medias: 12,00 €', $correo['subject'] );
			$this->assertStringContainsString( 'Retómalo aquí: https://example.com/pago/', $correo['message'] );
		}

		// — El barrido ————————————————————————————————————————

		public function test_el_barrido_avisa_una_vez_y_no_insiste(): void {
			$GLOBALS['__gw_meta'][ 601 ] = $this->meta();
			$GLOBALS['__gw_hijos']       = array( 601 );

			$primera = \Convoca\Gateway\Email_Notifications::maybe_send_pending_reminders( self::AHORA );
			$this->assertSame( 1, $primera );
			$this->assertCount( 1, $GLOBALS['__gw_emails'] );

			// La promesa del mensaje («no vamos a insistirte») tiene que ser verdad.
			$segunda = \Convoca\Gateway\Email_Notifications::maybe_send_pending_reminders( self::AHORA + 900 );
			$this->assertSame( 0, $segunda );
			$this->assertCount( 1, $GLOBALS['__gw_emails'], 'No se manda dos veces el mismo aviso.' );
		}

		public function test_el_barrido_marca_tambien_los_que_no_se_pudieron_avisar(): void {
			$GLOBALS['__gw_meta'][ 602 ] = $this->meta( array( '_convoca_payer_email' => '' ) );
			$GLOBALS['__gw_hijos']       = array( 602 );

			$this->assertSame( 0, \Convoca\Gateway\Email_Notifications::maybe_send_pending_reminders( self::AHORA ) );
			$this->assertNotSame( '', (string) get_post_meta( 602, '_convoca_reminder_sent', true ), 'Se marca para no repasarlo cada cuarto de hora.' );
		}
	}
}
