<?php
/**
 * La pestaña de estado: lo que ve quien tiene que arreglar la pasarela.
 *
 * El resumen decide entre tres estados (errores, avisos, todo bien) y la lista enseña una fila por comprobación, con el
 * arreglo y el botón solo cuando hay algo que reparar. Si el resumen dijera «todo correcto» con un error dentro, la
 * pantalla estaría mintiendo, que es peor que no tenerla.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Settings;
use Convoca\Gateway\Diagnostic;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/Admin_Settings.php';

/**
 * Pestaña de estado.
 */
final class PestanaEstadoTest extends TestCase {

	/**
	 * Ajustes.
	 *
	 * @var Admin_Settings
	 */
	private Admin_Settings $ajustes;

	/**
	 * Previos.
	 *
	 * @var array
	 */
	private array $previo = array();

	/**
	 * Prepara el caso.
	 */
	protected function setUp(): void {
		$this->previo = array(
			'opciones' => $GLOBALS['__gw_options'] ?? array(),
			'posts'    => $GLOBALS['_wp_stores']['posts'] ?? array(),
			'caps'     => $GLOBALS['__gw_caps'] ?? array(),
		);
		$GLOBALS['__gw_options']        = array();
		$GLOBALS['_wp_stores']['posts'] = array();
		$GLOBALS['__gw_caps']           = array( 'manage_options' => true );
		$this->ajustes                  = new Admin_Settings();
	}

	/**
	 * Limpia.
	 */
	protected function tearDown(): void {
		$GLOBALS['__gw_options']        = $this->previo['opciones'];
		$GLOBALS['_wp_stores']['posts'] = $this->previo['posts'];
		$GLOBALS['__gw_caps']           = $this->previo['caps'];
	}

	/**
	 * Pinta la pestaña y devuelve el HTML.
	 *
	 * @return string
	 */
	private function pintar(): string {
		$metodo = new \ReflectionMethod( Admin_Settings::class, 'render_status_tab' );

		ob_start();
		try {
			$metodo->invoke( $this->ajustes );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Sin configurar nada, el resumen dice que hay errores y se ofrece reparar.
	 */
	public function test_sin_configurar_el_resumen_avisa_de_errores(): void {
		$html = $this->pintar();

		$this->assertStringContainsString( 'conv-gateway-badge--error', $html, 'el resumen tiene que estar en rojo' );
		$this->assertStringContainsString( 'Estado: Errores detectados', $html );
		$this->assertStringContainsString( 'Hay errores de configuración que deben resolverse', $html );
		$this->assertStringNotContainsString( 'Todo correcto', $html, 'no puede decir que todo va bien con errores dentro' );
	}

	/**
	 * Cada comprobación tiene su fila, y no se pierde ninguna por el camino.
	 */
	public function test_una_fila_por_comprobacion(): void {
		$resultados = Diagnostic::run_all( true );
		$html       = $this->pintar();

		$hijos = 0;
		foreach ( $resultados as $r ) {
			$hijos += count( $r['children'] ?? array() );
		}

		$this->assertSame(
			count( $resultados ) + $hijos,
			substr_count( $html, '<div class="conv-gateway-diagnostic-row">' ),
			'cada comprobación y cada detalle que se ejecuta se enseña'
		);

		foreach ( $resultados as $r ) {
			$this->assertStringContainsString( $r['title'], $html, 'falta el título de: ' . $r['slug'] );
		}
	}

	/**
	 * Los que se pueden arreglar traen su botón con el slug; los que no, no.
	 */
	public function test_botones_solo_donde_hay_arreglo(): void {
		$resultados = Diagnostic::run_all( true );
		$html       = $this->pintar();

		$con    = 0;
		$sin    = 0;

		foreach ( $resultados as $r ) {
			if ( ! empty( $r['fix_callback'] ) ) {
				++$con;
				$this->assertStringContainsString( 'data-fix="' . $r['slug'] . '"', $html, 'falta el botón de: ' . $r['slug'] );
			} else {
				++$sin;
				$this->assertStringNotContainsString( 'data-fix="' . $r['slug'] . '"', $html, 'no debería ofrecerse reparar: ' . $r['slug'] );
			}
		}

		$this->assertGreaterThan( 0, $con, 'con la configuración vacía tiene que haber algo que reparar' );
		$this->assertGreaterThan( 0, $sin, 'y también comprobaciones que no se reparan solas' );
	}

	/**
	 * El mensaje del arreglo se enseña cuando existe.
	 */
	public function test_el_mensaje_del_arreglo(): void {
		$resultados = Diagnostic::run_all( true );
		$html       = $this->pintar();

		foreach ( $resultados as $r ) {
			if ( ! empty( $r['fix'] ) && is_string( $r['fix'] ) ) {
				$this->assertStringContainsString( esc_html( $r['fix'] ), $html, 'falta el arreglo de: ' . $r['slug'] );
			}
		}
		$this->assertStringContainsString( 'conv-gateway-fix-info', $html );
	}

	/**
	 * Los hijos de una comprobación se pintan dentro de ella.
	 */
	public function test_los_hijos_se_pintan(): void {
		$html = $this->pintar();

		$this->assertStringContainsString( 'conv-gateway-diagnostic-children', $html, 'los detalles van agrupados en su comprobación' );
		$this->assertStringContainsString( 'Página de pago OK', $html, 'las páginas de retorno se detallan una a una' );
		$this->assertStringContainsString( 'Página de pago error', $html );
	}

	/**
	 * El botón para forzar la comprobación está siempre: es la salida cuando la caché enseña un estado viejo.
	 */
	public function test_boton_forzar(): void {
		$this->assertStringContainsString( 'conv-gateway-run-diagnostic', $this->pintar() );
	}
}
