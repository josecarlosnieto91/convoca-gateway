<?php
/**
 * Datos fiscales del pagador en el recibo.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\CPT_Pago;
use Convoca\Gateway\Receipt_View;
use PHPUnit\Framework\TestCase;

/**
 * El recibo tiene que poder llevar el NIF de quien paga.
 */
final class ReciboDatosFiscalesTest extends TestCase {

	/**
	 * Arnés limpio.
	 */
	protected function setUp(): void {
		$GLOBALS['__gw_meta']     = array();
		$GLOBALS['__gw_inserted'] = array();
		$GLOBALS['__gw_options']  = array();
	}

	/**
	 * Un pago creado con factura guarda nombre y datos fiscales.
	 */
	public function test_guarda_nombre_y_datos_fiscales(): void {
		$id = CPT_Pago::create(
			array(
				'amount_cents'   => 2500,
				'origin'         => 'courses',
				'origin_id'      => 3441,
				'product_desc'   => 'Curso de prueba',
				'payer_email'    => 'ana@example.com',
				'payer_name'     => 'Ana Pérez',
				'payer_nif'      => '12345678z',
				'payer_business' => 'Ana Pérez SL',
				'payer_address'  => 'Calle Mayor 1, Oviedo',
				'needs_invoice'  => true,
			)
		);

		$this->assertIsInt( $id );
		$this->assertSame( 'Ana Pérez', $GLOBALS['__gw_meta'][ $id ]['_convoca_payer_name'] );
		$this->assertSame( '12345678Z', $GLOBALS['__gw_meta'][ $id ]['_convoca_payer_nif'], 'El NIF se guarda en mayúsculas.' );
		$this->assertSame( 'Ana Pérez SL', $GLOBALS['__gw_meta'][ $id ]['_convoca_payer_business'] );
		$this->assertSame( 'Calle Mayor 1, Oviedo', $GLOBALS['__gw_meta'][ $id ]['_convoca_payer_address'] );
		$this->assertSame( '1', $GLOBALS['__gw_meta'][ $id ]['_convoca_needs_invoice'] );
	}

	/**
	 * Un pago sin factura no inventa datos fiscales.
	 */
	public function test_sin_factura_no_guarda_fiscales(): void {
		$id = CPT_Pago::create(
			array(
				'amount_cents' => 2500,
				'origin'       => 'courses',
				'origin_id'    => 3441,
				'payer_email'  => 'ana@example.com',
				'payer_name'   => 'Ana Pérez',
			)
		);

		$this->assertSame( 'Ana Pérez', $GLOBALS['__gw_meta'][ $id ]['_convoca_payer_name'] );
		$this->assertArrayNotHasKey( '_convoca_payer_nif', $GLOBALS['__gw_meta'][ $id ] );
		$this->assertArrayNotHasKey( '_convoca_needs_invoice', $GLOBALS['__gw_meta'][ $id ] );
	}

	/**
	 * El recibo lee los datos fiscales del pago.
	 */
	public function test_el_recibo_los_lee(): void {
		$id = CPT_Pago::create(
			array(
				'amount_cents'   => 2500,
				'origin'         => 'courses',
				'origin_id'      => 3441,
				'product_desc'   => 'Curso de prueba',
				'payer_email'    => 'ana@example.com',
				'payer_name'     => 'Ana Pérez',
				'payer_nif'      => '12345678Z',
				'payer_business' => 'Ana Pérez SL',
				'payer_address'  => 'Calle Mayor 1, Oviedo',
				'needs_invoice'  => true,
			)
		);

		$GLOBALS['__gw_meta'][ $id ]['_convoca_status']      = 'paid';
		$GLOBALS['__gw_meta'][ $id ]['_convoca_paid_at']     = '2026-10-29 20:00:00';
		$GLOBALS['__gw_meta'][ $id ]['_convoca_amount_cents'] = 2500;
		$GLOBALS['__gw_meta'][ $id ]['_convoca_receipt_key'] = 'clave';

		$datos = Receipt_View::data( $id, 'clave' );

		$this->assertSame( 'Ana Pérez SL', $datos['payer_business'] );
		$this->assertSame( '12345678Z', $datos['payer_nif'] );
		$this->assertSame( 'Calle Mayor 1, Oviedo', $datos['payer_address'] );
		$this->assertTrue( $datos['needs_invoice'] );
	}

	/**
	 * El nombre guardado se usa cuando nadie lo aporta por el filtro.
	 */
	public function test_el_nombre_guardado_es_el_que_sale(): void {
		$id = CPT_Pago::create(
			array(
				'amount_cents' => 2500,
				'origin'       => 'courses',
				'origin_id'    => 3441,
				'product_desc' => 'Curso',
				'payer_email'  => 'ana@example.com',
				'payer_name'   => 'Ana Pérez',
			)
		);

		$GLOBALS['__gw_meta'][ $id ]['_convoca_status']       = 'paid';
		$GLOBALS['__gw_meta'][ $id ]['_convoca_paid_at']      = '2026-10-29 20:00:00';
		$GLOBALS['__gw_meta'][ $id ]['_convoca_amount_cents'] = 2500;
		$GLOBALS['__gw_meta'][ $id ]['_convoca_receipt_key']  = 'clave';

		$datos = Receipt_View::data( $id, 'clave' );

		$this->assertSame( 'Ana Pérez', $datos['payer_name'] );
	}
}
