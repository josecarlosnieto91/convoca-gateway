<?php
/**
 * Las páginas de retorno del banco: si faltan, el pago se cobra y el socio se queda en una pantalla en blanco.
 *
 * `check_return_pages()` mira tres páginas (pago, éxito y error), que existan, que estén publicadas y que lleven su
 * shortcode. Aquí se recorren los tres estados de cada una: no configurada, configurada pero inexistente o en borrador,
 * publicada sin shortcode (aviso, no error) y publicada con shortcode (bien). Y el veredicto del bloque: cualquier
 * falta pone el bloque en **error** y ofrece el arreglo.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Diagnostic;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/Diagnostic.php';

/**
 * Páginas de retorno.
 */
final class PaginasRetornoTest extends TestCase {

	/**
	 * Opciones y posts previos.
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
		);
		$GLOBALS['__gw_options']        = array();
		$GLOBALS['_wp_stores']['posts'] = array();
	}

	/**
	 * Limpia.
	 */
	protected function tearDown(): void {
		$GLOBALS['__gw_options']        = $this->previo['opciones'];
		$GLOBALS['_wp_stores']['posts'] = $this->previo['posts'];
	}

	/**
	 * Crea una página en el almacén.
	 *
	 * @param int    $id      Id.
	 * @param string $estado  Estado.
	 * @param string $contenido Contenido.
	 */
	private function pagina( int $id, string $estado, string $contenido ): void {
		$GLOBALS['_wp_stores']['posts'][ $id ] = (object) array(
			'ID'           => $id,
			'post_status'  => $estado,
			'post_content' => $contenido,
			'post_title'   => 'Página ' . $id,
			'post_type'    => 'page',
		);
	}

	/**
	 * Configura las tres páginas.
	 *
	 * @param array $ajustes Ajustes.
	 */
	private function configurar( array $ajustes ): void {
		$GLOBALS['__gw_options']['convoca_gateway_settings'] = $ajustes;
	}

	/**
	 * Sin configurar, el bloque entero está en error y ofrece arreglo.
	 */
	public function test_sin_configurar(): void {
		$r = Diagnostic::check_return_pages();

		$this->assertSame( Diagnostic::SEVERITY_ERROR, $r['severity'] );
		$this->assertNotNull( $r['fix_callback'], 'tiene que ofrecer crear las páginas' );
		foreach ( array( 'ok_page', 'ko_page', 'payment_page' ) as $clave ) {
			$this->assertSame( Diagnostic::SEVERITY_ERROR, $r['children'][ $clave ]['severity'], $clave . ' debería estar en error' );
			$this->assertStringContainsString( 'No configurada', $r['children'][ $clave ]['message'] );
		}
	}

	/**
	 * Las tres publicadas y con su shortcode: todo bien y nada que arreglar.
	 */
	public function test_las_tres_correctas(): void {
		$this->pagina( 11, 'publish', '[convoca_pago_ok]' );
		$this->pagina( 12, 'publish', '[convoca_pago_ko]' );
		$this->pagina( 13, 'publish', '[convoca_pago]' );
		$this->configurar(
			array(
				'ok_page_id'      => 11,
				'ko_page_id'      => 12,
				'payment_page_id' => 13,
			)
		);

		$r = Diagnostic::check_return_pages();

		$this->assertSame( Diagnostic::SEVERITY_OK, $r['severity'] );
		$this->assertNull( $r['fix_callback'], 'si está todo bien no se ofrece arreglar' );
		foreach ( $r['children'] as $hijo ) {
			$this->assertSame( Diagnostic::SEVERITY_OK, $hijo['severity'] );
		}
	}

	/**
	 * Publicada pero sin shortcode: aviso, no error (la página existe, el cobro no vuelve).
	 */
	public function test_publicada_sin_shortcode(): void {
		$this->pagina( 11, 'publish', 'Gracias por tu pago' );
		$this->configurar( array( 'ok_page_id' => 11 ) );

		$r = Diagnostic::check_return_pages();

		$this->assertSame( Diagnostic::SEVERITY_WARNING, $r['children']['ok_page']['severity'] );
		$this->assertStringContainsString( '[convoca_pago_ok]', $r['children']['ok_page']['message'] );
	}

	/**
	 * En borrador cuenta como que no existe: el banco volvería a una página que el público no ve.
	 */
	public function test_en_borrador_cuenta_como_falta(): void {
		$this->pagina( 11, 'draft', '[convoca_pago_ok]' );
		$this->configurar( array( 'ok_page_id' => 11 ) );

		$r = Diagnostic::check_return_pages();

		$this->assertSame( Diagnostic::SEVERITY_ERROR, $r['children']['ok_page']['severity'] );
		$this->assertStringContainsString( 'No existe o no publicada', $r['children']['ok_page']['message'] );
		$this->assertSame( Diagnostic::SEVERITY_ERROR, $r['severity'] );
	}

	/**
	 * Configurada con un id que ya no existe: error.
	 */
	public function test_id_que_ya_no_existe(): void {
		$this->configurar( array( 'ko_page_id' => 999 ) );

		$r = Diagnostic::check_return_pages();

		$this->assertSame( Diagnostic::SEVERITY_ERROR, $r['children']['ko_page']['severity'] );
		$this->assertNotNull( $r['children']['ko_page']['fix_callback'] );
	}

	/**
	 * Una sola página bien no tapa las otras dos.
	 */
	public function test_una_bien_no_tapa_las_faltas(): void {
		$this->pagina( 13, 'publish', '[convoca_pago]' );
		$this->configurar( array( 'payment_page_id' => 13 ) );

		$r = Diagnostic::check_return_pages();

		$this->assertSame( Diagnostic::SEVERITY_OK, $r['children']['payment_page']['severity'] );
		$this->assertSame( Diagnostic::SEVERITY_ERROR, $r['severity'], 'el bloque sigue en error por las que faltan' );
	}
}
