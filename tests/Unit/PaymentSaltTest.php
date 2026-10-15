<?php
/**
 * La sal de firma de la pasarela es SUYA, no la del ecosistema (issue convoca-core#16).
 *
 * Las dos se llamaban `get_persistent_salt()` y usan opciones distintas: core `convoca_persistent_salt` y la
 * pasarela `convoca_gateway_persistent_salt`. No eran dos copias del mismo secreto, eran **dos secretos con el
 * mismo nombre** — y unificarlas «para no duplicar» habría cambiado la sal de un lado y dejado de validar las
 * firmas del otro, sin que se note hasta que falla un cobro.
 *
 * Esta prueba vigila las tres cosas que ese error rompería, y ninguna suite cubría antes: con la firma sin
 * vigilancia, el renombrado podía haber cambiado la sal sin que cayera nada.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class PaymentSaltTest extends TestCase {

	private function fuente(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Payment_Handler.php' );
	}

	public function test_la_firma_usa_su_propia_sal(): void {
		$f = $this->fuente();
		$this->assertMatchesRegularExpression( '/hash_hmac\([^;]*self::get_payment_salt\(\)/', $f, 'la firma se calcula con la sal de la pasarela' );
	}

	public function test_usa_su_opcion_y_no_la_del_ecosistema(): void {
		$f = $this->fuente();
		$this->assertStringContainsString( "'convoca_gateway_persistent_salt'", $f, 'la sal vive en la opción de la pasarela' );
		$this->assertStringNotContainsString( "get_option( 'convoca_persistent_salt' )", $f, 'no se lee la sal del ecosistema: cambiarla rompería las firmas ya emitidas' );
	}

	public function test_no_vuelve_el_nombre_compartido(): void {
		$f = $this->fuente();
		$this->assertStringNotContainsString( 'function get_persistent_salt', $f, 'ese nombre lo usa core para OTRO secreto: aquí vuelve a confundir' );
	}
}
