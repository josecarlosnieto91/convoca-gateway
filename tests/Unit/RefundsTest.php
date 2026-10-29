<?php
/**
 * Devoluciones: importes, estados e idempotencia.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\CPT_Pago;
use Convoca\Gateway\Refunds;
use PHPUnit\Framework\TestCase;

/**
 * Una devolución no puede pagar dos veces el mismo dinero.
 */
final class RefundsTest extends TestCase {

	/**
	 * Pago pagado.
	 */
	private int $pago;

	/**
	 * Arnés limpio.
	 */
	protected function setUp(): void {
		$GLOBALS['__gw_meta']     = array();
		$GLOBALS['__gw_inserted'] = array();
		$GLOBALS['__gw_options']  = array();
		$GLOBALS['__gw_filtros']  = array();

		$this->pago = CPT_Pago::create(
			array(
				'amount_cents' => 5000,
				'origin'       => 'courses',
				'origin_id'    => 3441,
				'product_desc' => 'Curso',
			)
		);

		$GLOBALS['__gw_meta'][ $this->pago ]['_convoca_status'] = 'paid';
	}

	/**
	 * Un pago pagado se puede devolver.
	 */
	public function test_un_pagado_se_puede_devolver(): void {
		$this->assertTrue( Refunds::disponible( $this->pago ) );
		$this->assertSame( 5000, Refunds::pendiente( $this->pago ) );
	}

	/**
	 * Un pago pendiente no se puede devolver.
	 */
	public function test_un_pendiente_no(): void {
		$GLOBALS['__gw_meta'][ $this->pago ]['_convoca_status'] = 'pending';

		$this->assertFalse( Refunds::disponible( $this->pago ) );
	}

	/**
	 * La devolución completa deja el pago en `refunded` y archiva importe y fecha.
	 */
	public function test_devolucion_completa(): void {
		add_filter( 'convoca_gateway_refund_result', array( $this, 'aprobar' ), 10, 3 );

		$resultado = Refunds::ejecutar( $this->pago, 0, 'Desistimiento' );

		remove_filter( 'convoca_gateway_refund_result', array( $this, 'aprobar' ), 10 );

		$this->assertIsArray( $resultado );
		$this->assertSame( 5000, $resultado['cents'] );
		$this->assertFalse( $resultado['parcial'] );
		$this->assertSame( 'refunded', $GLOBALS['__gw_meta'][ $this->pago ]['_convoca_status'] );
		$this->assertSame( 5000, $GLOBALS['__gw_meta'][ $this->pago ][ Refunds::META_CENTS ] );
		$this->assertSame( 'Desistimiento', $GLOBALS['__gw_meta'][ $this->pago ][ Refunds::META_REASON ] );
	}

	/**
	 * Devolver otra vez el mismo importe **no** genera una segunda operación.
	 */
	public function test_no_se_devuelve_dos_veces(): void {
		add_filter( 'convoca_gateway_refund_result', array( $this, 'aprobar' ), 10, 3 );

		Refunds::ejecutar( $this->pago, 0, '' );

		$segunda = Refunds::ejecutar( $this->pago, 0, '' );

		remove_filter( 'convoca_gateway_refund_result', array( $this, 'aprobar' ), 10 );

		$this->assertInstanceOf( \WP_Error::class, $segunda );
		$this->assertSame( 'no_disponible', $segunda->get_error_code() );
		$this->assertSame( 5000, $GLOBALS['__gw_meta'][ $this->pago ][ Refunds::META_CENTS ] );
	}

	/**
	 * Una devolución parcial deja el resto disponible.
	 */
	public function test_devolucion_parcial(): void {
		add_filter( 'convoca_gateway_refund_result', array( $this, 'aprobar' ), 10, 3 );

		$resultado = Refunds::ejecutar( $this->pago, 2000, 'Mitad' );

		remove_filter( 'convoca_gateway_refund_result', array( $this, 'aprobar' ), 10 );

		$this->assertTrue( $resultado['parcial'] );
		$this->assertSame( 'partially_refunded', $GLOBALS['__gw_meta'][ $this->pago ]['_convoca_status'] );
		$this->assertSame( 3000, Refunds::pendiente( $this->pago ) );
	}

	/**
	 * No se puede devolver más de lo cobrado.
	 */
	public function test_no_se_puede_devolver_mas(): void {
		$resultado = Refunds::ejecutar( $this->pago, 6000, '' );

		$this->assertInstanceOf( \WP_Error::class, $resultado );
		$this->assertSame( 'importe_excesivo', $resultado->get_error_code() );
	}

	/**
	 * Si el TPV rechaza, el pago no cambia.
	 */
	public function test_si_el_tpv_rechaza_no_cambia_nada(): void {
		add_filter( 'convoca_gateway_refund_result', array( $this, 'rechazar' ), 10, 3 );

		$resultado = Refunds::ejecutar( $this->pago, 0, '' );

		remove_filter( 'convoca_gateway_refund_result', array( $this, 'rechazar' ), 10 );

		$this->assertInstanceOf( \WP_Error::class, $resultado );
		$this->assertSame( 'rechazada', $resultado->get_error_code() );
		$this->assertSame( 'paid', $GLOBALS['__gw_meta'][ $this->pago ]['_convoca_status'] );
		$this->assertArrayNotHasKey( Refunds::META_CENTS, $GLOBALS['__gw_meta'][ $this->pago ] );
	}

	/**
	 * Aprueba cualquier devolución.
	 *
	 * @param mixed $resultado Resultado previo.
	 * @param int   $pago_id   Pago.
	 * @param int   $cents     Céntimos.
	 * @return array<string,mixed>
	 */
	public function aprobar( $resultado, $pago_id, $cents ): array {
		unset( $resultado, $pago_id );

		return array(
			'approved' => true,
			'response' => '0900',
			'order_id' => 'ORD1',
			'cents'    => $cents,
		);
	}

	/**
	 * Rechaza cualquier devolución.
	 *
	 * @param mixed $resultado Resultado previo.
	 * @param int   $pago_id   Pago.
	 * @param int   $cents     Céntimos.
	 * @return array<string,mixed>
	 */
	public function rechazar( $resultado, $pago_id, $cents ): array {
		unset( $resultado, $pago_id, $cents );

		return array(
			'approved' => false,
			'response' => '0190',
			'order_id' => 'ORD1',
		);
	}
}
