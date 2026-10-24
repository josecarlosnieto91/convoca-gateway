<?php
/**
 * El generador de enlaces de pago: importe, concepto, método y caducidad.
 *
 * Un enlace de pago con el importe equivocado o sin caducidad acaba en un cobro raro o en un enlace eterno. Lo que se
 * prueba: el mínimo de 0,50 € se respeta, un concepto vacío solo se admite en un donativo (y entonces pone «Donativo»),
 * el método tiene que existir, y un donativo **no caduca** porque está pensado para estar publicado.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Generador;
use Convoca\Gateway\CPT_Pago;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/admin/class-admin-generador.php';

/**
 * Generador de pagos.
 */
final class GeneradorPagosTest extends TestCase {

	/**
	 * Generador.
	 *
	 * @var Admin_Generador
	 */
	private Admin_Generador $generador;

	/**
	 * Almacenes previos.
	 *
	 * @var array
	 */
	private array $previo = array();

	/**
	 * Prepara el caso.
	 */
	protected function setUp(): void {
		$this->previo = array(
			'posts'   => $GLOBALS['_wp_stores']['posts'] ?? array(),
			'meta'    => $GLOBALS['__gw_meta'] ?? array(),
			'options' => $GLOBALS['__gw_options'] ?? array(),
		);

		$GLOBALS['_wp_stores']['posts']     = array();
		$GLOBALS['__gw_meta'] = array();
		$GLOBALS['__gw_options']   = array();
		$GLOBALS['_wp_stub_next_id']        = 3000;
		$this->generador                    = new Admin_Generador();
	}

	/**
	 * Limpia.
	 */
	protected function tearDown(): void {
		$GLOBALS['_wp_stores']['posts']     = $this->previo['posts'];
		$GLOBALS['__gw_meta'] = $this->previo['meta'];
		$GLOBALS['__gw_options']   = $this->previo['options'];
		unset( $GLOBALS['_wp_stub_next_id'] );
	}

	/**
	 * Genera con los datos dados.
	 *
	 * @param array $datos Formulario.
	 * @return array|\WP_Error
	 */
	private function generar( array $datos ) {
		$metodo = new \ReflectionMethod( Admin_Generador::class, 'process_generation' );
		return $metodo->invoke( $this->generador, $datos );
	}

	/**
	 * Por debajo de 0,50 € no se genera nada.
	 */
	public function test_importe_minimo(): void {
		$resultado = $this->generar( array( 'convoca_gateway_amount' => '0.20', 'convoca_gateway_concepto' => 'Cuota' ) );

		$this->assertInstanceOf( \WP_Error::class, $resultado );
		$this->assertSame( 'invalid_amount', $resultado->get_error_code() );
	}

	/**
	 * Sin concepto y con importe, no se puede generar.
	 */
	public function test_sin_concepto(): void {
		$resultado = $this->generar( array( 'convoca_gateway_amount' => '10' ) );

		$this->assertInstanceOf( \WP_Error::class, $resultado );
		$this->assertSame( 'missing_concept', $resultado->get_error_code() );
	}

	/**
	 * Un concepto larguísimo no entra.
	 */
	public function test_concepto_demasiado_largo(): void {
		$resultado = $this->generar(
			array(
				'convoca_gateway_amount'   => '10',
				'convoca_gateway_concepto' => str_repeat( 'a', 126 ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $resultado );
		$this->assertSame( 'long_concept', $resultado->get_error_code() );
	}

	/**
	 * Un método de pago que no existe no se acepta.
	 */
	public function test_metodo_invalido(): void {
		$resultado = $this->generar(
			array(
				'convoca_gateway_amount'   => '10',
				'convoca_gateway_concepto' => 'Cuota',
				'convoca_gateway_method'   => 'cripto',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $resultado );
		$this->assertSame( 'invalid_method', $resultado->get_error_code() );
	}

	/**
	 * Importe y concepto válidos: se crea el pago y se devuelve el enlace.
	 */
	public function test_genera_el_enlace(): void {
		$resultado = $this->generar(
			array(
				'convoca_gateway_amount'   => '25.50',
				'convoca_gateway_concepto' => 'Cuota anual',
				'convoca_gateway_method'   => 'tarjeta',
				'convoca_gateway_email'    => 'ada@example.test',
			)
		);

		$this->assertIsArray( $resultado, 'devuelve el pago, no un error' );
		$this->assertArrayHasKey( 'pago_id', $resultado );
		$this->assertArrayHasKey( 'url', $resultado );
		$this->assertNotSame( '', $resultado['url'] );
	}

	/**
	 * Un donativo sin importe no caduca: está pensado para estar publicado.
	 */
	public function test_donativo_no_caduca(): void {
		$resultado = $this->generar( array( 'convoca_gateway_amount' => '' ) );

		$this->assertIsArray( $resultado );
		$meta = $GLOBALS['__gw_meta'][ $resultado['pago_id'] ] ?? array();
		$this->assertSame( 0, $meta['_convoca_expires_at'] ?? null, 'sin caducidad se guarda 0: un enlace de donativo siempre está vivo' );
	}

	/**
	 * Marcar «nunca caduca» también lo deja sin fecha.
	 */
	public function test_nunca_caduca_marcado(): void {
		$resultado = $this->generar(
			array(
				'convoca_gateway_amount'      => '10',
				'convoca_gateway_concepto'    => 'Cuota',
				'convoca_gateway_never_expires' => '1',
			)
		);

		$this->assertIsArray( $resultado );
		$meta = $GLOBALS['__gw_meta'][ $resultado['pago_id'] ] ?? array();
		$this->assertSame( 0, $meta['_convoca_expires_at'] ?? null, 'sin caducidad se guarda 0' );
	}

	/**
	 * Los métodos sin pasarela: transferencia solo si hay IBAN.
	 */
	public function test_metodos_sin_pasarela(): void {
		$metodo = new \ReflectionMethod( Admin_Generador::class, 'get_offline_methods' );

		$this->assertSame( array(), $metodo->invoke( $this->generador ), 'sin IBAN no hay transferencia' );

		$GLOBALS['__gw_options']['convoca_gateway_settings'] = array( 'iban' => 'ES00 0000 0000' );
		$this->assertSame( array( 'transferencia' ), $metodo->invoke( $this->generador ) );
	}
}
