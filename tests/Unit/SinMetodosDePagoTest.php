<?php
/**
 * El último paso del alta no puede ser un callejón sin salida.
 *
 * Defecto medido con ojos de usuario nuevo (recorrido a ciegas, pasada 9 del gauntlet): en la demo,
 * el alta de socio terminaba en la pantalla de pago con «No hay ningún método de pago disponible.
 * Contacta con la entidad.» y **ahí se acababa todo**: sin decir qué faltaba, sin decir si el alta
 * se había registrado y sin un solo botón. Quien rellenaba cuatro pasos se quedaba mirando un aviso.
 *
 * Ahora el aviso dice tres cosas: qué ha pasado (el alta queda registrada y pendiente de pago), qué
 * falta y dónde se arregla —solo a quien puede arreglarlo—, y por dónde seguir.
 *
 * Prueba en negativo: volviendo al aviso de una línea, las tres comprobaciones fallan.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests {

	use PHPUnit\Framework\TestCase;

	class SinMetodosDePagoTest extends TestCase {

		protected function setUp(): void {
			$this->loadClasses();
			$GLOBALS['__gw_options'] = array();
			$GLOBALS['__gw_caps']    = array();
			$this->resetCaches();
		}

		protected function tearDown(): void {
			$GLOBALS['__gw_options'] = array();
			$GLOBALS['__gw_caps']    = array();
			$this->resetCaches();
		}

		private function loadClasses(): void {
			foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Payment_Handler' ) as $class ) {
				$file = dirname( __DIR__, 2 ) . '/includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
			foreach ( glob( dirname( __DIR__, 2 ) . '/includes/*.php' ) as $file ) {
				$base = basename( $file );
				if ( str_starts_with( $base, 'class-' ) || 'Payment_Handler.php' !== $base ) {
					continue;
				}
				require_once $file;
			}
			require_once dirname( __DIR__, 2 ) . '/includes/Payment_Handler.php';
		}

		private function resetCaches(): void {
			foreach ( array( 'convoca_gateway_methods_cache', 'convoca_gateway_settings_cache' ) as $k ) {
				unset( $GLOBALS['__gw_options'][ $k ] );
			}

			// La caché de VERDAD es la propiedad estática de `Redsys_Client`, no esas dos opciones: con la caché
			// llena este caso cree que hay credenciales configuradas y sus tres pruebas del aviso fallan
			// (gateway#12). Un «resetCaches» que no vacía la caché que el producto usa no resetea nada.
			gw_reset_settings_cache();
		}

		/**
		 * Lo que ve el usuario: se llama a `render_method_cards()`, que es la puerta por la que
		 * entra la pantalla de pago, y NO al método nuevo directamente.
		 *
		 * Aprendido a base de equivocarse: la primera versión de esta prueba invocaba
		 * `render_sin_metodos()` por reflexión, así que **pasaba con el defecto puesto** —el aviso
		 * de una línea vivía en el punto de llamada y la prueba no lo tocaba—. Un test que llama
		 * al arreglo y no al camino que recorre el usuario no comprueba nada (regla 8).
		 */
		private function sinMetodos(): string {
			$r = new \ReflectionClass( \Convoca\Gateway\Payment_Handler::class );
			$h = $r->newInstanceWithoutConstructor();
			// Sin setAccessible(): desde PHP 8.1 no hace nada y en 8.5 sale un Deprecated
			// (medido: el CI lo cuenta como incidencia y ensucia el verde).
			$m = new \ReflectionMethod( \Convoca\Gateway\Payment_Handler::class, 'render_method_cards' );
			return (string) $m->invoke( $h, 'https://demo.test/pago/' );
		}

		public function test_el_aviso_dice_que_el_alta_no_se_ha_perdido(): void {
			$html = $this->sinMetodos();
			$this->assertStringContainsString( 'No hay ningún método de pago disponible', $html );
			$this->assertStringContainsString(
				'queda registrada y pendiente de pago',
				$html,
				'El aviso no tranquiliza a quien acaba de rellenar el alta: no sabe si se ha registrado.'
			);
		}

		public function test_el_aviso_no_es_un_callejon_sin_salida(): void {
			$html = $this->sinMetodos();
			$this->assertMatchesRegularExpression(
				'/<a [^>]*href="[^"]+"/',
				$html,
				'La pantalla no ofrece ningún enlace: el usuario se queda encerrado.'
			);
			$this->assertStringNotContainsString( 'href=""', $html, 'Hay un enlace sin destino.' );
		}

		public function test_a_quien_puede_arreglarlo_se_le_dice_que_falta_y_donde(): void {
			$GLOBALS['__gw_caps']['manage_options'] = true;
			$html = $this->sinMetodos();
			$this->assertStringContainsString( 'falta configurar', $html, 'No se dice qué falta.' );
			$this->assertStringContainsString( 'credenciales de la pasarela', $html, 'No se nombra la pasarela.' );
			$this->assertStringContainsString( 'cuenta bancaria para las transferencias', $html, 'No se nombra la cuenta de transferencias.' );
			$this->assertStringContainsString(
				'admin.php?page=conv-gateway-settings',
				$html,
				'No lleva a los ajustes que hay que tocar.'
			);
		}

		public function test_a_quien_no_puede_arreglarlo_no_se_le_habla_de_credenciales(): void {
			$GLOBALS['__gw_caps']['manage_options'] = false;
			$html = $this->sinMetodos();
			$this->assertStringNotContainsString( 'credenciales de la pasarela', $html, 'A un socio no se le habla de credenciales.' );
			$this->assertStringNotContainsString( 'conv-gateway-settings', $html, 'A un socio no se le manda a los ajustes.' );
			$this->assertStringContainsString( 'Contacta con la entidad', $html, 'Un socio tiene que saber con quién hablar.' );
		}
	}
}
