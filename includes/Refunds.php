<?php
/**
 * Devoluciones de cobros.
 *
 * Una devolución mueve dinero de verdad: se ejecuta solo desde el panel, nunca desde una petición web
 * anónima, y nunca dos veces por el mismo importe.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Devolución total o parcial de un pago.
 */
class Refunds {

	/**
	 * Céntimos devueltos hasta ahora.
	 */
	public const META_CENTS = '_convoca_refunded_cents';

	/**
	 * Fecha de la última devolución.
	 */
	public const META_AT = '_convoca_refunded_at';

	/**
	 * Referencia o código de la última devolución.
	 */
	public const META_REF = '_convoca_refund_reference';

	/**
	 * Motivo declarado por quien devuelve.
	 */
	public const META_REASON = '_convoca_refund_reason';

	/**
	 * Céntimos ya devueltos de un pago.
	 *
	 * @param int $pago_id Pago.
	 * @return int
	 */
	public static function devuelto( int $pago_id ): int {
		return (int) get_post_meta( $pago_id, self::META_CENTS, true );
	}

	/**
	 * Céntimos que aún se pueden devolver.
	 *
	 * @param int $pago_id Pago.
	 * @return int
	 */
	public static function pendiente( int $pago_id ): int {
		$total = (int) get_post_meta( $pago_id, '_convoca_amount_cents', true );

		return max( 0, $total - self::devuelto( $pago_id ) );
	}

	/**
	 * ¿Se puede devolver este pago?
	 *
	 * @param int $pago_id Pago.
	 * @return bool
	 */
	public static function disponible( int $pago_id ): bool {
		if ( $pago_id <= 0 || ! get_post( $pago_id ) ) {
			return false;
		}

		$estado = (string) get_post_meta( $pago_id, '_convoca_status', true );

		if ( ! in_array( $estado, array( 'paid', 'partially_refunded' ), true ) ) {
			return false;
		}

		return self::pendiente( $pago_id ) > 0;
	}

	/**
	 * Devuelve un importe. Sin importe, devuelve lo que quede.
	 *
	 * @param int    $pago_id Pago.
	 * @param int    $cents   Céntimos a devolver (0 = todo lo pendiente).
	 * @param string $motivo  Motivo, para el registro.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function ejecutar( int $pago_id, int $cents = 0, string $motivo = '' ): array|\WP_Error {
		if ( ! self::disponible( $pago_id ) ) {
			return new \WP_Error( 'no_disponible', __( 'Este pago no admite devolución.', 'convoca-gateway' ) );
		}

		$pendiente = self::pendiente( $pago_id );
		$cents     = $cents > 0 ? $cents : $pendiente;

		if ( $cents > $pendiente ) {
			return new \WP_Error( 'importe_excesivo', __( 'No se puede devolver más de lo cobrado.', 'convoca-gateway' ) );
		}

		$order = (string) get_post_meta( $pago_id, '_convoca_order_id', true );

		/**
		 * Permite sustituir la llamada al TPV (otra pasarela, o una prueba).
		 *
		 * Con `null` se habla con Redsys, que es lo que pasa en producción.
		 *
		 * @param array<string,mixed>|\WP_Error|null $resultado Resultado ya calculado, o null.
		 * @param int                                 $pago_id   Pago.
		 * @param int                                 $cents     Céntimos a devolver.
		 */
		$resultado = apply_filters( 'convoca_gateway_refund_result', null, $pago_id, $cents );

		if ( null === $resultado ) {
			$resultado = Redsys_Client::refund(
				array(
					'order_id'     => $order,
					'amount_cents' => $cents,
				)
			);
		}

		if ( is_wp_error( $resultado ) ) {
			return $resultado;
		}

		if ( empty( $resultado['approved'] ) ) {
			return new \WP_Error(
				'rechazada',
				sprintf(
					/* translators: %s: código de respuesta del TPV */
					__( 'El TPV rechazó la devolución (código %s).', 'convoca-gateway' ),
					(string) ( $resultado['response'] ?? '' )
				)
			);
		}

		$devuelto = self::devuelto( $pago_id ) + $cents;
		$total    = (int) get_post_meta( $pago_id, '_convoca_amount_cents', true );
		$parcial  = $devuelto < $total;

		update_post_meta( $pago_id, self::META_CENTS, $devuelto );
		update_post_meta( $pago_id, self::META_AT, current_time( 'mysql' ) );
		update_post_meta( $pago_id, self::META_REF, (string) ( $resultado['response'] ?? '' ) );
		update_post_meta( $pago_id, self::META_REASON, sanitize_text_field( $motivo ) );
		update_post_meta( $pago_id, '_convoca_status', $parcial ? 'partially_refunded' : 'refunded' );

		/**
		 * Un pago se ha devuelto.
		 *
		 * @param int  $pago_id Pago.
		 * @param int  $cents   Céntimos devueltos en esta operación.
		 * @param bool $parcial Si queda algo por devolver.
		 */
		do_action( 'convoca_gateway_payment_refunded', $pago_id, $cents, $parcial );

		return array(
			'cents'   => $cents,
			'parcial' => $parcial,
			'estado'  => $parcial ? 'partially_refunded' : 'refunded',
		);
	}
}
