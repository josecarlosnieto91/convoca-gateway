<?php
/**
 * El HTML de las pantallas de pago.
 *
 * `Payment_Handler` tiene 2.000 líneas y 39 métodos: cada fragmento de marcado dentro de un método
 * de lógica le cuenta como longitud y complejidad. Aquí el marcado está solo, troceado en piezas
 * cortas, y la lógica del cobro no se toca.
 *
 * @package ConvocaGateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vistas del formulario de pago.
 */
final class Payment_Views {

	/**
	 * Formulario de donativo.
	 *
	 * @param array<string,mixed> $d Datos ya resueltos.
	 * @return string HTML.
	 */
	public static function donacion( array $d ): string {
		$metodo = (string) ( $d['metodo'] ?? '' );

		$html = '<div class="conv-payment-wrapper convoca-form" role="region" aria-label="'
			. esc_attr__( 'Formulario de donativo', 'convoca-gateway' ) . '">'
			. self::cabecera( (string) ( $d['concepto'] ?? '' ), (string) ( $d['error'] ?? '' ) )
			. self::params( (array) ( $d['params'] ?? array() ) )
			. ( '' === $metodo ? (string) $d['picker'] : self::cuerpo( $d ) )
			. self::nota( $metodo )
			. '</div>';

		wp_enqueue_style( 'convoca-gateway' );

		return $html;
	}

	/**
	 * Cabecera con el concepto y el aviso de error.
	 *
	 * @param string $concepto Qué se está pagando.
	 * @param string $error    Mensaje de error, si lo hay.
	 * @return string HTML.
	 */
	private static function cabecera( string $concepto, string $error ): string {
		$html = '<div class="conv-payment-summary"><h3>' . esc_html( $concepto ) . '</h3></div>';

		if ( '' !== $error ) {
			$html .= '<div class="convoca-alert convoca-alert--danger">' . esc_html( $error ) . '</div>';
		}

		return $html;
	}

	/**
	 * Los datos adicionales que llegan en la compra.
	 *
	 * @param array<string,mixed> $params Pares clave-valor.
	 * @return string HTML.
	 */
	private static function params( array $params ): string {
		if ( empty( $params ) ) {
			return '';
		}

		$html = '<div class="conv-params"><h4>' . esc_html__( 'Datos adicionales', 'convoca-gateway' ) . '</h4><ul class="conv-params-list">';

		foreach ( $params as $k => $v ) {
			$html .= '<li><span class="conv-param-key">' . esc_html( (string) $k ) . ':</span> <span class="conv-param-value">' . esc_html( (string) $v ) . '</span></li>';
		}

		return $html . '</ul></div>';
	}

	/**
	 * El formulario en sí: importe y correo.
	 *
	 * @param array<string,mixed> $d Datos ya resueltos.
	 * @return string HTML.
	 */
	private static function cuerpo( array $d ): string {
		$metodo = esc_attr( (string) ( $d['metodo'] ?? '' ) );

		$html  = (string) $d['elegido'];
		$html .= '<form method="post" action="" class="conv-link-form conv-donation-form">';
		$html .= self::nonce( (int) ( $d['pago_id'] ?? 0 ) );
		$html .= '<input type="hidden" name="convoca_gateway_method" value="' . $metodo . '">';
		$html .= self::campo_importe( (string) ( $d['importe'] ?? '' ) );
		$html .= self::campo_correo( (string) ( $d['correo'] ?? '' ) );
		$html .= '<div class="form-actions"><button type="submit" class="wp-block-button__link">';

		$html .= esc_html(
			sprintf(
				/* translators: %s: payment method label (Tarjeta, Bizum o Transferencia). */
				__( 'Continuar con %s', 'convoca-gateway' ),
				(string) ( $d['etiqueta_metodo'] ?? '' )
			)
		);

		return $html . ' &rarr;</button></div></form>';
	}

	/**
	 * El campo oculto del nonce.
	 *
	 * @param int $pago_id Pago.
	 * @return string HTML.
	 */
	private static function nonce( int $pago_id ): string {
		ob_start();
		wp_nonce_field( 'convoca_donation_' . $pago_id, 'convoca_donation_nonce' );
		return (string) ob_get_clean();
	}

	/**
	 * Campo del importe.
	 *
	 * @param string $importe Valor actual.
	 * @return string HTML.
	 */
	private static function campo_importe( string $importe ): string {
		return '<div class="conv-field"><label for="convoca_donation_amount">'
			. esc_html__( 'Importe (€)', 'convoca-gateway' ) . ' *</label>'
			. '<input type="number" name="convoca_donation_amount" id="convoca_donation_amount" class="regular-text" step="0.01" min="0.50" required value="'
			. esc_attr( $importe ) . '" placeholder="0.00">'
			. '<p class="conv-help">' . esc_html__( 'Mínimo 0,50 €.', 'convoca-gateway' ) . '</p></div>';
	}

	/**
	 * Campo del correo para el recibo.
	 *
	 * @param string $correo Valor actual.
	 * @return string HTML.
	 */
	private static function campo_correo( string $correo ): string {
		return '<div class="conv-email-field"><label for="convoca_donation_email">'
			. esc_html__( 'Email para el recibo (opcional)', 'convoca-gateway' ) . '</label>'
			. '<input type="email" name="convoca_donation_email" id="convoca_donation_email" class="regular-text" value="'
			. esc_attr( $correo ) . '">'
			. '<p class="conv-help">' . esc_html__( 'Si lo indicas, te enviamos el recibo de tu aportación.', 'convoca-gateway' ) . '</p></div>';
	}

	/**
	 * Nota de seguridad al pie.
	 *
	 * @param string $metodo Método elegido.
	 * @return string HTML.
	 */
	private static function nota( string $metodo ): string {
		$texto = 'transferencia' === $metodo
			? '🍀 ' . esc_html__( 'Al continuar te mostraremos los datos para hacer el ingreso.', 'convoca-gateway' )
			: '🔒 ' . esc_html__( 'Pago seguro gestionado por Redsys. No almacenamos tus datos bancarios.', 'convoca-gateway' );

		return '<p class="conv-security-note">' . $texto . '</p>';
	}
}
