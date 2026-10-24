<?php
/**
 * «Reparar» crea las páginas que faltan, reutiliza las que valen y no pisa lo que funciona.
 *
 * Es el botón que se pulsa cuando el diagnóstico dice que el pago no vuelve a ningún sitio. Si crea dos veces la misma
 * página, el ajuste apunta a una y el público aterriza en otra; si reutiliza una en borrador, sigue sin volver. Aquí se
 * fijan las tres salidas: crear, reutilizar y no tocar.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Diagnostic;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/Diagnostic.php';

/**
 * Reparación de páginas.
 */
final class RepararPaginasTest extends TestCase {

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
			'inserted' => $GLOBALS['__gw_inserted'] ?? array(),
		);
		$GLOBALS['__gw_options']        = array();
		$GLOBALS['_wp_stores']['posts'] = array();
		$GLOBALS['__gw_inserted']       = array();
		unset( $GLOBALS['__gw_insert_falla'] );
	}

	/**
	 * Limpia.
	 */
	protected function tearDown(): void {
		$GLOBALS['__gw_options']        = $this->previo['opciones'];
		$GLOBALS['_wp_stores']['posts'] = $this->previo['posts'];
		$GLOBALS['__gw_inserted']       = $this->previo['inserted'];
		unset( $GLOBALS['__gw_insert_falla'] );
	}

	/**
	 * Una página en el almacén.
	 *
	 * @param int    $id     Id.
	 * @param string $slug   Slug.
	 * @param string $estado Estado.
	 */
	private function pagina( int $id, string $slug, string $estado = 'publish' ): void {
		$GLOBALS['_wp_stores']['posts'][ $id ] = (object) array(
			'ID'           => $id,
			'post_name'    => $slug,
			'post_status'  => $estado,
			'post_content' => '',
		);
	}

	/**
	 * Sin nada configurado se crean las tres, cada una con su shortcode.
	 */
	public function test_crea_las_tres(): void {
		$r = Diagnostic::fix_create_pages();

		$this->assertTrue( $r['success'] );
		$this->assertCount( 3, $GLOBALS['__gw_inserted'], 'una de pago, una de éxito y una de error' );

		$ajustes = $GLOBALS['__gw_options']['convoca_gateway_settings'];
		$this->assertGreaterThan( 0, $ajustes['payment_page_id'] );
		$this->assertGreaterThan( 0, $ajustes['ok_page_id'] );
		$this->assertGreaterThan( 0, $ajustes['ko_page_id'] );
		$this->assertSame( 3, count( array_unique( array( $ajustes['payment_page_id'], $ajustes['ok_page_id'], $ajustes['ko_page_id'] ) ) ), 'tres páginas distintas' );

		$contenidos = array_column( $GLOBALS['__gw_inserted'], 'post_content' );
		$this->assertStringContainsString( '[convoca_pago]', implode( "\n", $contenidos ) );
		$this->assertStringContainsString( '[convoca_pago_ok]', implode( "\n", $contenidos ) );
		$this->assertStringContainsString( '[convoca_pago_ko]', implode( "\n", $contenidos ) );
	}

	/**
	 * Si las páginas ya existen y están publicadas, **no se crea ninguna** y se reutilizan.
	 */
	public function test_reutiliza_las_que_valen(): void {
		$this->pagina( 31, 'pago' );
		$this->pagina( 32, 'pago-completado' );
		$this->pagina( 33, 'pago-error' );

		$r = Diagnostic::fix_create_pages();

		$this->assertTrue( $r['success'] );
		$this->assertCount( 0, $GLOBALS['__gw_inserted'], 'no se duplica lo que ya existe' );
		$ajustes = $GLOBALS['__gw_options']['convoca_gateway_settings'];
		$this->assertSame( 31, (int) $ajustes['payment_page_id'] );
		$this->assertSame( 32, (int) $ajustes['ok_page_id'] );
		$this->assertSame( 33, (int) $ajustes['ko_page_id'] );
	}

	/**
	 * Una página en borrador no vale: se crea la buena y las otras dos se reutilizan.
	 */
	public function test_el_borrador_no_se_reutiliza(): void {
		$this->pagina( 31, 'pago-completado', 'draft' );
		$this->pagina( 32, 'pago-error' );

		Diagnostic::fix_create_pages();

		$this->assertCount( 2, $GLOBALS['__gw_inserted'], 'la de éxito (en borrador) y la de pago' );
		$ajustes = $GLOBALS['__gw_options']['convoca_gateway_settings'];
		$this->assertGreaterThan( 0, $ajustes['ok_page_id'] );
		$this->assertNotSame( 31, (int) $ajustes['ok_page_id'], 'no se guarda una página en borrador' );
		$this->assertSame( 32, (int) $ajustes['ko_page_id'], 'la que sí valía se reutiliza' );
	}

	/**
	 * Si la creación falla, no se guarda un id que no existe.
	 */
	public function test_si_falla_la_creacion_no_guarda_id(): void {
		$GLOBALS['__gw_insert_falla'] = true;

		$r = Diagnostic::fix_create_pages();

		$this->assertTrue( $r['success'], 'no revienta; simplemente no crea' );
		$this->assertArrayNotHasKey( 'payment_page_id', $GLOBALS['__gw_options']['convoca_gateway_settings'] ?? array() );
	}

	/**
	 * Un id configurado que ya no existe se sustituye por una página buena.
	 */
	public function test_id_muerto_se_sustituye(): void {
		$GLOBALS['__gw_options']['convoca_gateway_settings'] = array( 'payment_page_id' => 999 );

		Diagnostic::fix_create_pages();

		$ajustes = $GLOBALS['__gw_options']['convoca_gateway_settings'];
		$this->assertGreaterThan( 0, $ajustes['payment_page_id'] );
		$this->assertNotSame( 999, (int) $ajustes['payment_page_id'] );
	}

	/**
	 * Reponer el terminal no se lleva por delante el resto de ajustes.
	 */
	public function test_terminal_conserva_lo_demas(): void {
		$GLOBALS['__gw_options']['convoca_gateway_settings'] = array(
			'merchant_code' => '123456789',
			'terminal'      => '',
		);

		$r = Diagnostic::fix_default_terminal();

		$this->assertTrue( $r['success'] );
		$ajustes = $GLOBALS['__gw_options']['convoca_gateway_settings'];
		$this->assertSame( '001', $ajustes['terminal'] );
		$this->assertSame( '123456789', $ajustes['merchant_code'], 'el FUC no se toca' );
	}
}
