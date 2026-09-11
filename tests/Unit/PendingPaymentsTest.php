<?php
/**
 * El panel de pagos sin terminar: la regla que decide qué está colgado y lo que enseña.
 *
 * Diferencia clave con el recordatorio: un enlace caducado sigue contando como colgado
 * (justo entonces hay que mirarlo), pero un pago sin correo no se puede recordar y sí
 * se puede vigilar.
 */

namespace Convoca\Gateway\Tests {

	use PHPUnit\Framework\TestCase;

	class PendingPaymentsTest extends TestCase {

		private const AHORA = 1800000000;

		private static $cargado = false;

		protected function setUp(): void {
			if ( ! self::$cargado ) {
				foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Payment_Handler', 'Email_Notifications', 'Receipt_Generator', 'Pending_Payments' ) as $class ) {
					$path = dirname( __DIR__, 2 ) . '/includes/' . $class . '.php';
					if ( file_exists( $path ) ) {
						require_once $path;
					}
				}
				self::$cargado = true;
			}

			$GLOBALS['__gw_meta']    = array();
			$GLOBALS['__gw_hijos']   = array();
			$GLOBALS['__gw_widgets'] = array();
			$GLOBALS['__gw_caps']    = array();
			$GLOBALS['__gw_options'] = array( 'gmt_offset' => 2 );
		}

		private function meta( array $extra = array() ): array {
			return array_merge(
				array(
					'_convoca_status'       => 'pending',
					'_convoca_origin'       => 'enlace',
					'_convoca_amount_cents' => 1200,
					'_convoca_created_ts'   => self::AHORA - 3600,
				),
				$extra
			);
		}

		private function colgado( array $meta ): bool {
			return \Convoca\Gateway\Pending_Payments::is_stuck( $meta, self::AHORA );
		}

		// — La regla ————————————————————————————————————

		public function test_un_pago_empezado_hace_rato_esta_colgado(): void {
			$this->assertTrue( $this->colgado( $this->meta() ) );
		}

		public function test_lo_que_no_esta_colgado(): void {
			$this->assertFalse( $this->colgado( $this->meta( array( '_convoca_status' => 'paid' ) ) ), 'Cobrado.' );
			$this->assertFalse( $this->colgado( $this->meta( array( '_convoca_status' => 'failed' ) ) ), 'Fallido.' );
			$this->assertFalse( $this->colgado( $this->meta( array( '_convoca_origin' => 'link_payment' ) ) ), 'Una plantilla de enlace no es un cobro.' );
			$this->assertFalse( $this->colgado( $this->meta( array( '_convoca_amount_cents' => 0 ) ) ), 'Sin importe no hay nada que cobrar.' );
			$this->assertFalse( $this->colgado( $this->meta( array( '_convoca_created_ts' => self::AHORA - 60 ) ) ), 'Acaba de empezar.' );
			$this->assertFalse( $this->colgado( $this->meta( array( '_convoca_created_ts' => 0, '_convoca_created_at' => '' ) ) ), 'Sin fecha no se puede saber.' );
		}

		public function test_un_pago_antiguo_sin_marca_de_tiempo_tambien_cuenta(): void {
			// Los pagos anteriores al recordatorio solo tienen la fecha local del sitio.
			// Son justo los que llevan meses colgados: tienen que salir en el panel.
			$viejo = array(
				'_convoca_created_ts' => 0,
				'_convoca_created_at' => gmdate( 'Y-m-d H:i:s', self::AHORA - 7200 + 7200 ),
			);
			$this->assertTrue( $this->colgado( $this->meta( $viejo ) ) );

			// Uno recién creado en hora local, no.
			$recien = array(
				'_convoca_created_ts' => 0,
				'_convoca_created_at' => gmdate( 'Y-m-d H:i:s', self::AHORA - 300 + 7200 ),
			);
			$this->assertFalse( $this->colgado( $this->meta( $recien ) ) );
		}

		public function test_un_enlace_caducado_sigue_contando_como_colgado(): void {
			$this->assertTrue(
				$this->colgado( $this->meta( array( '_convoca_expires_at' => self::AHORA - 1 ) ) ),
				'Para el panel, un enlace caducado es justo lo que hay que mirar.'
			);
		}

		public function test_sin_correo_tambien_se_vigila(): void {
			$this->assertTrue(
				$this->colgado( $this->meta( array( '_convoca_payer_email' => '' ) ) ),
				'Aunque no se le pueda recordar por correo, el pago sigue a medias.'
			);
		}

		public function test_el_panel_no_mezcla_reglas(): void {
			$meta = $this->meta( array( '_convoca_created_ts' => 0, '_convoca_created_at' => '' ) );
			$this->assertFalse( $this->colgado( $meta ) );
			$this->assertFalse(
				\Convoca\Gateway\Email_Notifications::should_remind( array_merge( $meta, array( '_convoca_payer_email' => 'a@example.com' ) ), self::AHORA ),
				'Lo que no está colgado tampoco se recuerda: comparten la misma regla.'
			);
		}

		// — El panel ————————————————————————————————————

		public function test_solo_lo_ve_quien_puede_administrar(): void {
			$GLOBALS['__gw_caps']['manage_options'] = false;
			\Convoca\Gateway\Pending_Payments::register_widget();
			$this->assertArrayNotHasKey( 'convoca_gateway_pendientes', $GLOBALS['__gw_widgets'], 'Sin permiso no se registra.' );

			$GLOBALS['__gw_caps']['manage_options'] = true;
			\Convoca\Gateway\Pending_Payments::register_widget();
			$this->assertArrayHasKey( 'convoca_gateway_pendientes', $GLOBALS['__gw_widgets'] );
		}

		public function test_sin_pagos_colgados_lo_dice(): void {
			$GLOBALS['__gw_hijos'] = array();
			ob_start();
			\Convoca\Gateway\Pending_Payments::render_widget();
			$salida = (string) ob_get_clean();

			$this->assertStringContainsString( 'Ningún pago a medias', $salida );
		}

		public function test_el_panel_enseña_importe_concepto_y_si_hay_correo(): void {
			$GLOBALS['__gw_hijos'] = array( 9001 );
			$GLOBALS['__gw_meta'][ 9001 ] = array(
				'_convoca_status'       => 'pending',
				'_convoca_amount_cents' => 1200,
				'_convoca_method'       => 'bizum',
				'_convoca_order_id'     => '260911PQ8EIX',
				'_convoca_product_desc' => 'Donativo de prueba',
				'_convoca_created_ts'   => self::AHORA - 3600,
				'_convoca_payer_email'  => '',
			);

			ob_start();
			\Convoca\Gateway\Pending_Payments::render_widget();
			$salida = (string) ob_get_clean();

			$this->assertStringContainsString( '12,00 €', $salida );
			$this->assertStringContainsString( 'Donativo de prueba', $salida );
			$this->assertStringContainsString( 'Bizum', $salida );
			$this->assertStringContainsString( 'sin correo', $salida );
			$this->assertStringContainsString( 'post.php?post=9001', $salida, 'Se puede ir al pago desde el panel.' );
		}
	}
}
