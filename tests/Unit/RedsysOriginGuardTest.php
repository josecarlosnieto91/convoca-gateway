<?php
/**
 * Fija la guarda de ORIGEN de las notificaciones del Gateway.
 *
 * El defecto que esto caza: en producción, el loopback y la IP del propio servidor no
 * estaban admitidos, así que cualquier flujo ejecutado desde la propia máquina (la
 * batería del ecosistema, un `wp` que dispare una notificación) se rechazaba y dejaba
 * `Notificación rechazada: IP de origen no autorizada` en el log de producción. Y al
 * revés: un sitio detrás de un proxy podía dejar de aceptar notificaciones legítimas
 * sin que nada lo avisara.
 *
 * Lo que de verdad protege contra una notificación inventada es la FIRMA de Redsys, que
 * se verifica aparte; esta lista es defensa en profundidad y no puede rechazar a quien
 * sale del propio sitio.
 *
 * @package ConvocaGateway
 */

use PHPUnit\Framework\TestCase;
use Convoca\Gateway\Payment_Handler;

/**
 * Guarda de origen de las notificaciones de pago.
 */
class RedsysOriginGuardTest extends TestCase {

	/**
	 * Instancia sin constructor: la decisión no depende del estado del objeto.
	 *
	 * @return Payment_Handler
	 */
	private function handler(): Payment_Handler {
		return ( new ReflectionClass( Payment_Handler::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * El loopback es el propio sitio: se admite.
	 *
	 * @return void
	 */
	public function test_el_loopback_se_admite(): void {
		$h = $this->handler();
		$this->assertTrue(
			$h->is_allowed_origin_ip( '127.0.0.1' ),
			'una peticion del propio servidor no puede rechazarse: es el defecto que esto fija'
		);
		$this->assertTrue( $h->is_allowed_origin_ip( '::1' ) );
	}

	/**
	 * Las direcciones del propio sitio (su IP pública) se admiten.
	 *
	 * @return void
	 */
	public function test_las_direcciones_del_propio_sitio_se_admiten(): void {
		$h = $this->handler();
		$this->assertTrue(
			$h->is_allowed_origin_ip( '79.112.96.163', array( '79.112.96.163' ) ),
			'la IP del propio servidor tiene que poder disparar sus propias notificaciones'
		);
	}

	/**
	 * Y lo que NO puede pasar: un tercero cualquiera sigue fuera.
	 *
	 * @return void
	 */
	public function test_una_ip_externa_desconocida_se_sigue_rechazando(): void {
		$h = $this->handler();
		$this->assertFalse(
			$h->is_allowed_origin_ip( '203.0.113.99', array( '79.112.96.163' ) ),
			'abrir la puerta al propio sitio no puede abrirla a cualquiera'
		);
		$this->assertFalse( $h->is_allowed_origin_ip( '' ), 'sin IP no se admite nada' );
		$this->assertFalse( $h->is_allowed_origin_ip( '127.0.0.2' ), 'no vale cualquier 127.x' );
	}

	/**
	 * Los rangos de Redsys se siguen admitiendo.
	 *
	 * @return void
	 */
	public function test_los_rangos_de_redsys_se_admiten(): void {
		$h = $this->handler();
		$this->assertTrue( $h->is_allowed_origin_ip( '195.76.9.187' ) );
		$this->assertTrue( $h->is_allowed_origin_ip( '193.16.243.33' ) );
	}
}
