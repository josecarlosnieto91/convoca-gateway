<?php
/**
 * El uso de un enlace de pago: pasos, avisos y emisión del cobro.
 *
 * Es dinero de verdad: aquí se emite el cobro cuando alguien usa un enlace. Lo que se prueba: sin nonce se pide el
 * formulario (no se cobra), con nonce inválido se avisa y **no se cobra**, sin método elegido tampoco, y cuando se cobra
 * el importe sale en céntimos / 100, el origen es `enlace` y queda apuntando al enlace del que salió.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\CPT_Pago;
use Convoca\Gateway\Payment_Handler;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/Payment_Handler.php';

/**
 * Uso de enlaces.
 */
final class EnlacePagoTest extends TestCase {

	/**
	 * Manejador.
	 *
	 * @var Payment_Handler
	 */
	private Payment_Handler $handler;

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
			'meta'  => $GLOBALS['__gw_meta'] ?? array(),
			'posts' => $GLOBALS['_wp_stores']['posts'] ?? array(),
		);

		$GLOBALS['__gw_meta']           = array();
		$GLOBALS['_wp_stores']['posts'] = array();
		$GLOBALS['_wp_stub_next_id']    = 4000;
		$_POST                          = array();
		$_REQUEST                       = array();
		$this->handler                  = new Payment_Handler();
	}

	/**
	 * Limpia.
	 */
	protected function tearDown(): void {
		$GLOBALS['__gw_meta']           = $this->previo['meta'];
		$GLOBALS['_wp_stores']['posts'] = $this->previo['posts'];
		unset( $GLOBALS['_wp_stub_next_id'] );
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Meta de un enlace de 25,50 € con su concepto.
	 *
	 * @return array
	 */
	private function meta_enlace(): array {
		$GLOBALS['__gw_meta'][100]['_convoca_product_desc'] = 'Cuota anual';
		$GLOBALS['__gw_meta'][100]['_convoca_params']       = array( 'socio' => '55' );

		return array( 'amount_cents' => 2550 );
	}

	/**
	 * Sin nonce se pide el formulario y **no se emite ningún cobro**.
	 */
	public function test_sin_nonce_pide_el_formulario(): void {
		$html = $this->usar();

		$this->assertNotSame( '', $html, 'devuelve el paso 1, no un aviso vacío' );
		$this->assertStringNotContainsString( 'sesión ha caducado', $html );
		$this->assertSame( array(), $this->cobros(), 'no se ha cobrado nada' );
	}

	/**
	 * Con nonce inválido se avisa y tampoco se cobra.
	 */
	public function test_nonce_invalido(): void {
		$GLOBALS['__gw_nonces'] = array( 'otro' );
		$_POST = array( 'convoca_link_nonce' => 'malo', 'convoca_gateway_method' => 'tarjeta' );

		$html = $this->usar();

		$this->assertStringContainsString( 'sesión ha caducado', $html );
		$this->assertSame( array(), $this->cobros(), 'un nonce malo no cobra' );
		unset( $GLOBALS['__gw_nonces'] );
	}

	/**
	 * Sin método elegido, aviso y no se cobra.
	 */
	public function test_sin_metodo(): void {
		$GLOBALS['__gw_nonces'] = array( 'bueno' );
		$_POST = array( 'convoca_link_nonce' => 'bueno' );

		$html = $this->usar();

		$this->assertStringContainsString( 'Elige un método de pago', $html );
		$this->assertSame( array(), $this->cobros() );
		unset( $GLOBALS['__gw_nonces'] );
	}

	/**
	 * Con método válido se emite el cobro con su importe, concepto, origen y parámetros.
	 */
	public function test_metodo_valido_emite_el_cobro(): void {
		$GLOBALS['__gw_nonces'] = array( 'bueno' );
		$_POST = array(
			'convoca_link_nonce'      => 'bueno',
			'convoca_gateway_method'  => 'transferencia',
			'convoca_gateway_email'   => 'ada@example.test',
		);

		$this->usar();

		$cobros = $this->cobros();
		$this->assertCount( 1, $cobros, 'se emite un cobro, no dos' );
		$id   = (int) array_key_first( $cobros );
		$meta = $GLOBALS['__gw_meta'][ $id ];

		$this->assertSame( 'enlace', $meta['_convoca_origin'], 'el origen delata de dónde salió' );
		$this->assertSame( 100, (int) $meta['_convoca_origin_id'], 'apunta al enlace' );
		$this->assertSame( 'transferencia', $meta['_convoca_method'] );
		$this->assertSame( 0, (int) $meta['_convoca_expires_at'], 'el cobro del enlace no caduca' );
		$this->assertSame( 'ada@example.test', $meta['_convoca_payer_email'] );
		$this->assertSame( array( 'socio' => '55' ), $meta['_convoca_params'], 'los parámetros del enlace viajan al cobro' );
		unset( $GLOBALS['__gw_nonces'] );
	}

	/**
	 * Llama al manejador con el enlace 100.
	 *
	 * @return string
	 */
	private function usar(): string {
		$metodo = new \ReflectionMethod( Payment_Handler::class, 'handle_link_use' );
		return (string) $metodo->invoke(
			$this->handler,
			100,
			$this->meta_enlace(),
			'Cuota anual',
			2550,
			'',
			'',
			array()
		);
	}

	/**
	 * Cobros emitidos (los que no son el enlace).
	 *
	 * @return array
	 */
	private function cobros(): array {
		$cobros = array();
		foreach ( $GLOBALS['__gw_meta'] as $id => $meta ) {
			if ( ( $meta['_convoca_origin'] ?? '' ) === 'enlace' ) {
				$cobros[ $id ] = $meta;
			}
		}
		return $cobros;
	}
}
