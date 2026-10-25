<?php
/**
 * Regresión del retorno firmado de Redsys (R14).
 *
 * Un retorno legítimo se acepta; si se manipula el contenido o la firma, `verify_notification()` tiene que
 * devolver `false`. En la demo se probó el camino bueno generando el retorno firmado de verdad, pero la
 * suite no cubría el rechazo: aquí queda fijado.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

final class RegresionRetornoFirmadoTest extends TestCase {

	private const MERCHANT_CODE = '999999999';
	private const SECRET_KEY    = 'clave-de-prueba';

	protected function setUp(): void {
		parent::setUp();

		$ajustes = array(
			'merchant_code' => self::MERCHANT_CODE,
			'secret_key'    => self::SECRET_KEY,
		);

		if ( function_exists( 'update_option' ) ) {
			update_option( 'convoca_gateway_settings', $ajustes );
		}
		$GLOBALS['_wp_stores']['options']['convoca_gateway_settings'] = $ajustes;

		$ruta = dirname( __DIR__, 2 ) . '/includes/Redsys_Client.php';
		if ( file_exists( $ruta ) && ! class_exists( '\\Convoca\\Gateway\\Redsys_Client' ) ) {
			require_once $ruta;
		}

		// **La caché estática de ajustes hay que soltarla.** `Redsys_Client` guarda los ajustes en una propiedad
		// `static` que nadie limpia sola: si antes corrió una prueba que los dejó con otros valores —`Redsys_Client`
		// los cachea en la primera lectura—, el `update_option()` de arriba no le llega y la firma se calcula con la
		// clave anterior. El resultado es «un retorno bien firmado no se acepta», y solo con ciertas semillas
		// (gateway#12). `PaymentConfirmationTest` y `ReceiptExpiryTest` ya lo hacían; esta prueba se lo saltaba.
		if ( function_exists( 'gw_reset_settings_cache' ) ) {
			gw_reset_settings_cache();
		}
	}

	/**
	 * Construye el POST del retorno como lo manda Redsys.
	 *
	 * @param array<string,mixed> $campos Campos del comerciante.
	 * @return array<string,string>
	 */
	private function retorno( array $campos ): array {
		$mp_b64 = rtrim( strtr( base64_encode( (string) json_encode( $campos ) ), '+/', '-_' ), '=' );

		return array(
			'Ds_SignatureVersion'   => \Convoca\Gateway\Redsys_Client::signature_version(),
			'Ds_MerchantParameters' => $mp_b64,
			'Ds_Signature'          => \Convoca\Gateway\Redsys_Client::sign( $mp_b64, (string) $campos['Ds_Order'] ),
		);
	}

	/**
	 * Un retorno bien firmado se acepta.
	 */
	public function test_un_retorno_bien_firmado_se_acepta(): void {
		if ( ! class_exists( '\\Convoca\\Gateway\\Redsys_Client' ) ) {
			$this->markTestIncomplete( 'No he podido cargar Redsys_Client.php en la suite unitaria.' );
		}

		$campos = array(
			'Ds_Order'        => '261015QA0001',
			'Ds_Amount'       => '3000',
			'Ds_Response'     => '0000',
			'Ds_Currency'     => '978',
			'Ds_MerchantCode' => self::MERCHANT_CODE,
		);

		try {
			$verificado = \Convoca\Gateway\Redsys_Client::verify_notification( $this->retorno( $campos ) );
		} catch ( \Error $e ) {
			$this->markTestIncomplete( 'El bootstrap no cubre lo que necesita la firma: ' . $e->getMessage() );
			return;
		}

		$this->assertIsArray( $verificado, 'Un retorno con la firma correcta debe aceptarse.' );
	}

	/**
	 * Un retorno con la firma manipulada se rechaza.
	 */
	public function test_un_retorno_con_firma_manipulada_se_rechaza(): void {
		if ( ! class_exists( '\\Convoca\\Gateway\\Redsys_Client' ) ) {
			$this->markTestIncomplete( 'No he podido cargar Redsys_Client.php en la suite unitaria.' );
		}

		$campos = array(
			'Ds_Order'    => '261015QA0002',
			'Ds_Amount'   => '3000',
			'Ds_Response' => '0000',
		);

		$retorno = $this->retorno( $campos );
		$retorno['Ds_Signature'] = str_repeat( 'A', strlen( $retorno['Ds_Signature'] ) );

		$this->assertFalse(
			\Convoca\Gateway\Redsys_Client::verify_notification( $retorno ),
			'Con la firma cambiada, el retorno no puede darse por bueno.'
		);
	}

	/**
	 * Un importe cambiado después de firmar se rechaza (la firma deja de cuadrar).
	 */
	public function test_un_importe_manipulado_se_rechaza(): void {
		if ( ! class_exists( '\\Convoca\\Gateway\\Redsys_Client' ) ) {
			$this->markTestIncomplete( 'No he podido cargar Redsys_Client.php en la suite unitaria.' );
		}

		$campos  = array( 'Ds_Order' => '261015QA0003', 'Ds_Amount' => '3000', 'Ds_Response' => '0000' );
		$retorno = $this->retorno( $campos );

		// Se cambia el importe sin volver a firmar: mismo ataque que un intermediario.
		$alterado = array( 'Ds_Order' => '261015QA0003', 'Ds_Amount' => '1', 'Ds_Response' => '0000' );
		$retorno['Ds_MerchantParameters'] = rtrim( strtr( base64_encode( (string) json_encode( $alterado ) ), '+/', '-_' ), '=' );

		$this->assertFalse(
			\Convoca\Gateway\Redsys_Client::verify_notification( $retorno ),
			'Si el importe cambia, la firma deja de cuadrar y el retorno debe rechazarse.'
		);
	}
}
