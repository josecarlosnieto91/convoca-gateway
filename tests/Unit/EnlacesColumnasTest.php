<?php
/**
 * La columna del listado que enseña el enlace de pago y la del importe.
 *
 * El enlace que se copia desde aquí es el que se manda a la gente: si su caducidad o su clave se construyen mal, el
 * enlace que se pega en un correo no cobra. Y los botones de **Editar** y **Eliminar** no pueden salir para quien no
 * puede tocar el registro.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Links;
use Convoca\Gateway\CPT_Pago;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/Admin_Links.php';

/**
 * Columnas del listado de enlaces.
 */
final class EnlacesColumnasTest extends TestCase {

	/**
	 * Listado.
	 *
	 * @var Admin_Links
	 */
	private Admin_Links $listado;

	/**
	 * Meta y capacidades previas.
	 *
	 * @var array
	 */
	private array $previo = array();

	/**
	 * Prepara el caso.
	 */
	protected function setUp(): void {
		$this->previo = array(
			'meta' => $GLOBALS['__gw_meta'] ?? array(),
			'caps' => $GLOBALS['__gw_caps'] ?? array(),
		);

		$GLOBALS['__gw_meta'] = array();
		$GLOBALS['__gw_caps'] = array( 'manage_options' => true, 'convoca_manage_payments' => true );
		$this->listado        = new Admin_Links();
	}

	/**
	 * Limpia.
	 */
	protected function tearDown(): void {
		$GLOBALS['__gw_meta'] = $this->previo['meta'];
		$GLOBALS['__gw_caps'] = $this->previo['caps'];
	}

	/**
	 * Un enlace con su concepto y su clave.
	 *
	 * @param array $extra Meta extra.
	 * @return object
	 */
	private function enlace( array $extra = array() ): object {
		$GLOBALS['__gw_meta'][50] = array_merge(
			array(
				'_convoca_product_desc' => 'Cuota anual',
				'_convoca_link_key'     => 'abc123',
				'_convoca_amount_cents' => '2550',
				'_convoca_method'       => 'tarjeta',
				'_convoca_expires_at'   => '',
			),
			$extra
		);

		return (object) array( 'ID' => 50 );
	}

	/**
	 * La columna enseña el concepto y el enlace que se copia.
	 */
	public function test_concepto_con_enlace_copiable(): void {
		$html = $this->listado->column_concepto( $this->enlace() );

		$this->assertStringContainsString( 'Cuota anual', $html );
		$this->assertStringContainsString( 'conv-gateway-copy-link', $html );
		$this->assertStringContainsString( 'data-link="', $html, 'sin el enlace no hay nada que copiar' );
		$this->assertStringContainsString( 'Ver detalles', $html );
	}

	/**
	 * Con permiso salen Editar y Eliminar; sin permiso, no.
	 */
	public function test_los_botones_dependen_del_permiso(): void {
		$con    = $this->listado->column_concepto( $this->enlace() );
		$sin    = null;
		$GLOBALS['__gw_caps'] = array( 'manage_options' => false, 'convoca_manage_payments' => false );

		// Se vuelve a pintar con la capacidad denegada.
		$sin = $this->listado->column_concepto( $this->enlace() );

		$this->assertStringContainsString( 'action=edit', $con );
		$this->assertStringContainsString( 'action=delete', $con );
		$this->assertStringNotContainsString( 'action=edit', $sin, 'sin permiso no se ofrece editar' );
		$this->assertStringNotContainsString( 'action=delete', $sin, 'sin permiso no se ofrece borrar' );
	}

	/**
	 * El enlace que se copia lleva su clave y **no** expone la caducidad en la URL.
	 *
	 * La caducidad se comprueba en el servidor contra el meta del pago; llevarla en la URL la haría manipulable y no
	 * aporta nada al que copia el enlace. Es una decisión escrita en el producto, y aquí se fija para que nadie la
	 * «arregle» metiendo la fecha.
	 */
	public function test_el_enlace_lleva_su_clave_y_no_la_caducidad(): void {
		$html = $this->listado->column_concepto( $this->enlace( array( '_convoca_expires_at' => (string) ( time() + 3600 ) ) ) );

		$this->assertStringContainsString( 'convoca_gateway_key=', $html, 'sin la clave el enlace no vale' );
		$this->assertStringNotContainsString( 'convoca_gateway_t=', $html, 'la caducidad no viaja en la URL' );
	}

	/**
	 * Importe libre se dice con palabras, no con un cero.
	 */
	public function test_importe_libre(): void {
		$html = $this->listado->column_amount( $this->enlace( array( '_convoca_open_amount' => '1' ) ) );

		$this->assertStringContainsString( 'Importe libre', $html );
		$this->assertStringNotContainsString( '0,00', $html, 'un donativo abierto no es un cobro de cero euros' );
	}

	/**
	 * **Un enlace sin importe no tumba la pantalla.**
	 *
	 * `CPT_Pago::get_meta()` devuelve lo que da `get_post_meta( ..., true )`, que para un meta escalar es
	 * **cadena** y para un meta que no existe es cadena vacía. Pasarla a `format_amount( int $cents )` lanza
	 * `TypeError` en PHP 8 —la cadena vacía no es numérica— y el listado de enlaces entero se quedaba sin pintar:
	 * basta un registro antiguo, una importación a medias o un cobro creado por otra vía (gateway#11).
	 */
	public function test_un_enlace_sin_importe_no_revienta(): void {
		$html = $this->listado->column_amount(
			$this->enlace( array( '_convoca_amount_cents' => '' ) )
		);

		$this->assertStringContainsString( '0,00', $html, 'sin importe se enseña cero, no un fatal' );
	}

	/**
	 * Y tampoco si la meta no está: `get_post_meta` devuelve `''` y el cast lo deja en cero.
	 */
	public function test_un_enlace_sin_la_meta_de_importe_no_revienta(): void {
		$GLOBALS['__gw_meta'][50] = array(
			'_convoca_product_desc' => 'Cuota anual',
			'_convoca_link_key'     => 'abc123',
			'_convoca_method'       => 'tarjeta',
		);

		$html = $this->listado->column_amount( (object) array( 'ID' => 50 ) );

		$this->assertStringContainsString( '0,00', $html );
		$this->assertStringNotContainsString( 'Importe libre', $html, 'sin open_amount no es un donativo abierto' );
	}

	/**
	 * Un importe fijo se formatea.
	 */
	public function test_importe_fijo(): void {
		$html = $this->listado->column_amount( $this->enlace() );

		$this->assertStringContainsString( '25', $html );
		$this->assertStringNotContainsString( 'Importe libre', $html );
	}

	/**
	 * El método se traduce; uno desconocido se enseña tal cual en vez de quedarse en blanco.
	 */
	public function test_metodo(): void {
		$this->assertSame( 'Tarjeta', $this->listado->column_method( $this->enlace() ) );
		$this->assertSame(
			'cripto',
			$this->listado->column_method( $this->enlace( array( '_convoca_method' => 'cripto' ) ) ),
			'un método nuevo no puede salir vacío'
		);
	}

	/**
	 * El origen dice de dónde salió el cobro.
	 */
	public function test_origen(): void {
		$this->assertStringContainsString( 'Enlace de pago', $this->listado->column_origin( $this->enlace() ) );
	}
}
