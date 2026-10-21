<?php
/**
 * El aviso de privacidad del formulario de pago.
 *
 * Por qué existe: el pago se apoya en la ejecución de un contrato (art. 6.1.b RGPD), así que no se pide
 * consentimiento —una casilla de «acepto la política» no aporta base jurídica— y lo que la ley exige es
 * INFORMAR (art. 13) en el momento de recoger los datos.
 *
 * Estos tests fijan las tres cosas que importan y que se rompen solas: que el formulario informa, que el
 * texto es de cada instalación (el responsable del tratamiento lo es), y que el diagnóstico avisa cuando
 * nadie lo ha configurado — porque un texto por defecto con el nombre del sitio cumple el expediente pero
 * no identifica a quien responde.
 */

namespace Convoca\Gateway\Tests {
	use PHPUnit\Framework\TestCase;

	class PrivacidadFormularioTest extends TestCase {

		protected function setUp(): void {
			$_GET  = array();
			$_POST = array();

			$GLOBALS['__gw_options'] = array(
				'convoca_gateway_settings' => array(
					'merchant_code' => '999999999',
					'secret_key'    => 'clave-de-prueba',
				),
			);

			$redsys = new \ReflectionClass( \Convoca\Gateway\Redsys_Client::class );
			$redsys->setStaticPropertyValue( 'settings', null );

			$handler = new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class );
			$handler->setStaticPropertyValue( 'methods_css_printed', false );
		}

		/**
		 * Deja el arnés como lo encontró.
		 *
		 * `Redsys_Client::$settings` es estática y se llena al primer `enabled_methods()`: si este test no la
		 * limpia, el siguiente que se ejecute hereda un sitio con credenciales y ve métodos de pago donde su
		 * escenario dice que no hay ninguno. Medido: tres fallos en `SinMetodosDePagoTest` por esto.
		 */
		protected function tearDown(): void {
			$GLOBALS['__gw_options'] = array();

			$redsys = new \ReflectionClass( \Convoca\Gateway\Redsys_Client::class );
			$redsys->setStaticPropertyValue( 'settings', null );

			$handler = new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class );
			$handler->setStaticPropertyValue( 'methods_css_printed', false );
		}

		private function handler(): \Convoca\Gateway\Payment_Handler {
			return ( new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * @param array<int, mixed> $args Argumentos del método.
		 * @return mixed
		 */
		private function call( string $method, array $args = array() ) {
			$reflection = new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class );
			$callable   = $reflection->getMethod( $method );

			return $callable->invokeArgs( $this->handler(), $args );
		}

		// ── El formulario informa ─────────────────────────────────────

		public function test_el_formulario_informa_aunque_nadie_lo_haya_configurado(): void {
			$aviso = $this->call( 'privacy_notice' );

			$this->assertStringContainsString( 'Responsable:', $aviso, 'Sin texto propio, el formulario no puede quedarse mudo.' );
			$this->assertStringContainsString( 'Finalidad:', $aviso );
			$this->assertStringContainsString( 'Base jurídica', $aviso, 'La base jurídica es lo que quita la casilla de consentimiento: si no se dice, no se entiende.' );
			$this->assertStringContainsString( 'derechos', $aviso );
		}

		public function test_el_texto_por_defecto_nombra_al_sitio_como_responsable(): void {
			$GLOBALS['__gw_options']['convoca_gateway_settings']['privacidad_texto'] = '';

			$aviso = $this->call( 'privacy_notice' );

			$this->assertStringContainsString( 'Responsable:', $aviso );
			$this->assertStringNotContainsString( 'Responsable: .', $aviso, 'Un «Responsable: .» sin nombre es peor que no poner nada.' );
		}

		public function test_el_formulario_usa_el_texto_de_cada_instalacion(): void {
			$GLOBALS['__gw_options']['convoca_gateway_settings']['privacidad_texto'] = 'Responsable: Asociación de prueba, CIF G-00000000.';
			$GLOBALS['__gw_options']['wp_page_for_privacy_policy']                   = 42;

			$aviso = $this->call( 'privacy_notice' );

			$this->assertStringContainsString( 'Asociación de prueba, CIF G-00000000', $aviso );
			$this->assertStringContainsString( 'Política de privacidad', $aviso, 'El aviso se enlaza con la política.' );
		}

		public function test_el_enlace_es_el_de_la_politica_del_sitio(): void {
			$GLOBALS['__gw_options']['wp_page_for_privacy_policy'] = 42;

			$aviso = $this->call( 'privacy_notice' );

			$this->assertStringContainsString( 'page_id=42', $aviso, 'Por defecto se enlaza la página de privacidad de WordPress.' );

			$GLOBALS['__gw_options']['convoca_gateway_settings']['privacidad_url'] = 'https://ejemplo.org/privacidad/';

			$aviso = $this->call( 'privacy_notice' );

			$this->assertStringContainsString( 'https://ejemplo.org/privacidad/', $aviso, 'Si se configura, manda lo configurado.' );
		}

		// ── El diagnóstico avisa cuando no está configurado ────────────

		public function test_el_diagnostico_avisa_si_nadie_ha_puesto_el_texto(): void {
			$r = \Convoca\Gateway\Diagnostic::check_privacidad();

			$this->assertSame( 'privacidad', $r['slug'] );
			$this->assertSame( \Convoca\Gateway\Diagnostic::SEVERITY_WARNING, $r['severity'], 'Un texto genérico no identifica al responsable: hay que avisar.' );
			$this->assertNotEmpty( $r['fix'] );
		}

		public function test_el_diagnostico_avisa_si_no_hay_politica_ni_enlace(): void {
			$GLOBALS['__gw_options']['convoca_gateway_settings']['privacidad_texto'] = 'Responsable: Entidad.';
			$GLOBALS['__gw_options']['wp_page_for_privacy_policy']                   = 0;

			$r = \Convoca\Gateway\Diagnostic::check_privacidad();

			$this->assertSame( \Convoca\Gateway\Diagnostic::SEVERITY_WARNING, $r['severity'] );
			$this->assertStringContainsString( 'política', $r['message'] );
		}

		public function test_el_diagnostico_calla_cuando_esta_bien(): void {
			$GLOBALS['__gw_options']['convoca_gateway_settings']['privacidad_texto'] = 'Responsable: Entidad, CIF G-00000000.';
			$GLOBALS['__gw_options']['wp_page_for_privacy_policy']                   = 42;

			$r = \Convoca\Gateway\Diagnostic::check_privacidad();

			$this->assertSame( \Convoca\Gateway\Diagnostic::SEVERITY_OK, $r['severity'] );
		}

		// ── El aviso está en el formulario, no en un cajón ────────────

		public function test_el_aviso_aparece_en_el_formulario_antes_del_boton(): void {
			$GLOBALS['__gw_options']['convoca_gateway_settings']['privacidad_texto'] = 'Responsable: Entidad de prueba.';
			$_GET['convoca_gateway_method']                                          = 'tarjeta';

			$html = $this->call( 'render_manual_form' );

			$this->assertStringContainsString( 'conv-privacy-note', $html, 'El aviso viaja DENTRO del formulario.' );
			$this->assertStringContainsString( 'Responsable: Entidad de prueba', $html );

			$pos_aviso = strpos( $html, 'conv-privacy-note' );
			$pos_boton = strpos( $html, 'form-actions' );
			$this->assertLessThan( $pos_boton, $pos_aviso, 'Se informa ANTES de enviar, no después.' );
		}
	}
}
