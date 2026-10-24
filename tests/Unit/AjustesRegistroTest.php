<?php
/**
 * El registro de ajustes de la pasarela y las dos acciones de diagnóstico.
 *
 * Lo que se registra aquí es lo que se guarda: FUC, terminal, clave secreta SHA-256, entorno y método por defecto. Si un
 * campo deja de registrarse, la pasarela deja de poder cobrar y nadie se entera hasta que falla un pago. Las dos
 * acciones AJAX del diagnóstico se prueban por su respuesta: sin permiso, error; con permiso, ejecutan y contestan.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Settings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/Admin_Settings.php';

/**
 * Ajustes de la pasarela.
 */
final class AjustesRegistroTest extends TestCase {

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
			'settings' => $GLOBALS['__gw_registered_settings'] ?? array(),
			'sections' => $GLOBALS['__gw_sections'] ?? array(),
			'fields'   => $GLOBALS['__gw_fields'] ?? array(),
			'json'     => $GLOBALS['__gw_json'] ?? array(),
			'caps'     => $GLOBALS['__gw_caps'] ?? array(),
			'nonce'    => $GLOBALS['__gw_nonce_ok'] ?? null,
		);

		$GLOBALS['__gw_registered_settings'] = array();
		$GLOBALS['__gw_sections']            = array();
		$GLOBALS['__gw_fields']              = array();
		$GLOBALS['__gw_json']                = array();
		$GLOBALS['__gw_caps']                = array( 'manage_options' => true );
		$GLOBALS['__gw_nonce_ok']            = true;
		$_POST                               = array();
		$_REQUEST                            = array();
		$this->ajustes                       = new Admin_Settings();
	}

	/**
	 * Limpia.
	 */
	protected function tearDown(): void {
		$GLOBALS['__gw_registered_settings'] = $this->previo['settings'];
		$GLOBALS['__gw_sections']            = $this->previo['sections'];
		$GLOBALS['__gw_fields']              = $this->previo['fields'];
		$GLOBALS['__gw_json']                = $this->previo['json'];
		$GLOBALS['__gw_caps']                = $this->previo['caps'];
		if ( null === $this->previo['nonce'] ) {
			unset( $GLOBALS['__gw_nonce_ok'] );
		} else {
			$GLOBALS['__gw_nonce_ok'] = $this->previo['nonce'];
		}
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * El ajuste se registra con su saneador.
	 */
	public function test_registra_el_ajuste(): void {
		$this->ajustes->register_settings();

		$this->assertArrayHasKey( 'convoca_gateway_settings', $GLOBALS['__gw_registered_settings'] );
		$this->assertSame(
			array( $this->ajustes, 'sanitize' ),
			$GLOBALS['__gw_registered_settings']['convoca_gateway_settings']['sanitize_callback']
		);
	}

	/**
	 * La sección de Redsys existe.
	 */
	public function test_registra_la_seccion(): void {
		$this->ajustes->register_settings();

		$secciones = $GLOBALS['__gw_sections']['conv-gateway-settings'] ?? array();
		$this->assertArrayHasKey( 'convoca_gateway_redsys', $secciones );
	}

	/**
	 * Los campos que hacen falta para cobrar están todos.
	 */
	public function test_registra_los_campos_de_cobro(): void {
		$this->ajustes->register_settings();

		foreach ( array( 'merchant_code', 'terminal', 'default_method', 'secret_key', 'environment' ) as $clave ) {
			$this->assertArrayHasKey( 'convoca_gateway_' . $clave, $GLOBALS['__gw_fields'], 'falta el campo: ' . $clave );
			$this->assertSame( 'convoca_gateway_redsys', $GLOBALS['__gw_fields'][ 'convoca_gateway_' . $clave ]['section'] );
		}
	}

	/**
	 * El selector de método se pinta con sus cuatro opciones.
	 *
	 * El registro no guarda las opciones (las cierra en el callback), así que se comprueba donde se ven: al pintar el
	 * campo. Sin «cualquiera» no se puede dejar al pagador elegir, y sin «transferencia» se pierde ese método.
	 */
	public function test_los_metodos_del_selector(): void {
		$metodo = new \ReflectionMethod( Admin_Settings::class, 'render_form_field' );
		$campo  = array(
			'label'   => 'Método',
			'type'    => 'select',
			'options' => array(
				'any'           => 'Cualquiera',
				'tarjeta'       => 'Tarjeta',
				'bizum'         => 'Bizum',
				'transferencia' => 'Transferencia',
			),
			'desc'    => '',
		);

		ob_start();
		try {
			$metodo->invoke( $this->ajustes, 'default_method', $campo );
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString( '<select', $html );
		foreach ( array( 'any', 'tarjeta', 'bizum', 'transferencia' ) as $valor ) {
			$this->assertStringContainsString( 'value="' . $valor . '"', $html, 'falta la opción: ' . $valor );
		}
	}

	/**
	 * El diagnóstico sin permiso responde error y no ejecuta.
	 */
	public function test_diagnostico_sin_permiso(): void {
		$GLOBALS['__gw_caps']['manage_options'] = false;

		try {
			$this->ajustes->ajax_diagnostic_run();
			$this->fail( 'debía cortar' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Sin permisos', $e->getMessage() );
		}

		$ultima = end( $GLOBALS['__gw_json'] );
		$this->assertFalse( $ultima['ok'] );
	}

	/**
	 * Sin nonce válido no se ejecuta el diagnóstico.
	 */
	public function test_diagnostico_sin_nonce(): void {
		$GLOBALS['__gw_nonce_ok'] = false;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/nonce inválido/' );
		$this->ajustes->ajax_diagnostic_run();
	}

	/**
	 * Con permiso y nonce, responde éxito.
	 */
	public function test_diagnostico_completo(): void {
		try {
			$this->ajustes->ajax_diagnostic_run();
			$this->fail( 'debía responder' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Diagnóstico completado', $e->getMessage() );
		}

		$ultima = end( $GLOBALS['__gw_json'] );
		$this->assertTrue( $ultima['ok'] );
	}

	/**
	 * Reparar algo que no existe se dice, en vez de fingir que se ha hecho.
	 */
	public function test_reparar_accion_desconocida(): void {
		$_POST = array( 'fix' => 'inventada' );

		try {
			$this->ajustes->ajax_diagnostic_fix();
			$this->fail( 'debía responder' );
		} catch ( \RuntimeException $e ) {
			// La respuesta se afirma abajo.
		}

		$ultima = end( $GLOBALS['__gw_json'] );
		$this->assertFalse( $ultima['ok'] );
		$this->assertStringContainsString( 'Acción no encontrada', (string) $ultima['datos']['message'] );
	}

	/**
	 * Sin permiso, reparar tampoco.
	 */
	public function test_reparar_sin_permiso(): void {
		$GLOBALS['__gw_caps']['manage_options'] = false;
		$_POST = array( 'fix' => 'terminal' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Sin permisos/' );
		$this->ajustes->ajax_diagnostic_fix();
	}
}
