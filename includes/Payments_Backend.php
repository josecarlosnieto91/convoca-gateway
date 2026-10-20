<?php
/**
 * Puente de cobros con el núcleo: el gateway se registra, el núcleo no lo nombra.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra este plugin como backend de `Convoca\Core\Payments`.
 *
 * Por qué existe: la fachada del núcleo (`Core\Payments`) tenía tres respaldos con `class_exists` y el
 * nombre de nuestras clases dentro, así que el núcleo seguía dependiendo de este plugin para cobrar.
 * Con esto la dirección es la correcta: **el addon se registra en el núcleo**, y el día que haya dos
 * pasarelas se sustituyen por filtro sin tocar el núcleo ni los módulos que cobran.
 *
 * Lo que se registra:
 *   · `convoca_payment_backend` — crear un pago (devuelve `array{pago_id,payment_url}` o `WP_Error`);
 *   · `convoca_payment_reader`  — leer un pago (importe, estado, método, fecha);
 *   · `convoca_payment_receipt` — URL pública del recibo.
 *
 * No hay problema de orden de carga: los filtros son de WordPress y se registran al cargar este
 * plugin, antes de que nadie llame a la fachada. Pero **el núcleo nuevo con un gateway viejo apaga los
 * cobros**, así que las dos versiones se despliegan juntas.
 */
class Payments_Backend {

	/**
	 * Engancha los tres filtros del núcleo.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'convoca_payment_backend', array( __CLASS__, 'backend' ) );
		add_filter( 'convoca_payment_reader', array( __CLASS__, 'reader' ), 10, 2 );
		add_filter( 'convoca_payment_receipt', array( __CLASS__, 'receipt' ), 10, 2 );
	}

	/**
	 * Backend de creación de pagos.
	 *
	 * @param callable|null $backend Lo que hubiera registrado otro plugin.
	 * @return callable
	 */
	public static function backend( $backend ): callable {
		return array( Payment_Handler::class, 'create_payment' );
	}

	/**
	 * Lector de pagos: los metadatos del pago, como los devuelve el CPT.
	 *
	 * @param array<string, mixed>|null $pago    Pago ya leído por otro backend, o null.
	 * @param int                       $pago_id Identificador del pago.
	 * @return array<string, mixed>
	 */
	public static function reader( $pago, $pago_id ): array {
		$pago_id = (int) $pago_id;

		if ( $pago_id <= 0 ) {
			return is_array( $pago ) ? $pago : array();
		}

		return CPT_Pago::get_meta( $pago_id );
	}

	/**
	 * URL pública del recibo.
	 *
	 * @param string $url     URL ya resuelta por otro backend (vacía si no hay).
	 * @param int    $pago_id Identificador del pago.
	 * @return string
	 */
	public static function receipt( $url, $pago_id ): string {
		$pago_id = (int) $pago_id;

		if ( $pago_id <= 0 ) {
			return (string) $url;
		}

		return Receipt_Generator::receipt_url( $pago_id );
	}
}
