<?php
/**
 * Formularios de pago: el HTML que ve quien va a pagar.
 *
 * Vive aparte de `Payment_Handler` porque la clase llegó a 2.155 líneas y 39 métodos, y ahí
 * cualquier método nuevo dispara la regla de clase de PHPMD aunque ahorre una de longitud. Aquí
 * cada trozo se puede partir sin coste, y `Payment_Handler` se queda con la lógica del cobro.
 *
 * @package ConvocaGateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formularios de pago.
 */
final class Payment_Forms {

	/**
	 * Instrucciones de transferencia, con el justificante si falta.
	 *
	 * @param int                 $pago_id Pago.
	 * @param array<string,mixed> $meta    Metadatos del pago.
	 * @param array<string,mixed> $estado  `upload_success` y `upload_error` del manejador.
	 * @return string HTML.
	 */
	public function transferencia( int $pago_id, array $meta, array $estado = array() ): string {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$iban     = (string) ( $settings['iban'] ?? '' );

		if ( '' === $iban ) {
			return $this->transferencia_sin_iban();
		}

		$html  = '<div class="conv-payment-wrapper convoca-form conv-transfer-view" role="region" aria-label="Instrucciones de transferencia">';
		$html .= $this->transferencia_datos(
			array(
				'importe'       => CPT_Pago::format_amount( (int) ( $meta['amount_cents'] ?? 0 ) ),
				'iban'          => $iban,
				'beneficiario'  => (string) ( $settings['beneficiary'] ?? 'Asociación Convoca' ),
				'pedido'        => (string) ( $meta['order_id'] ?? '' ),
				'instrucciones' => (string) ( $settings['instructions'] ?? '' ),
			)
		);
		$html .= $this->transferencia_justificante( $pago_id, $estado );
		$html .= $this->transferencia_pie();
		$html .= '</div>';

		wp_enqueue_style( 'convoca-gateway' );

		return $html . $this->transferencia_script();
	}

	/**
	 * Aviso cuando el sitio no tiene IBAN configurado.
	 *
	 * @return string HTML.
	 */
	private function transferencia_sin_iban(): string {
		return '<div class="convoca-form convoca-card" style="max-width:500px; margin: 2rem auto; text-align:center;">
                <div class="convoca-alert convoca-alert--danger">
                    <h4 style="margin-top:0">⚠️ Método no disponible</h4>
                    <p>Lo sentimos, el pago por transferencia no está configurado correctamente en este momento (falta el IBAN de destino).</p>
                    <div style="margin-top:1.5rem">
                        <a href="' . esc_url( remove_query_arg( 'convoca_gateway_method' ) ) . '" class="wp-block-button__link">Volver a elegir método</a>
                    </div>
                </div>
            </div>';
	}

	/**
	 * Los datos del ingreso.
	 *
	 * @param array<string,string> $datos Importe, IBAN, beneficiario, pedido e instrucciones.
	 * @return string HTML.
	 */
	private function transferencia_datos( array $datos ): string {
		ob_start();
		?>
		<div class="conv-payment-summary">
			<div class="conv-success-icon">🍀</div>
			<h3>Pago por Transferencia</h3>
			<p>Por favor, realiza el ingreso con los siguientes datos:</p>
		</div>

		<div class="conv-transfer-details">
			<div class="conv-detail-row">
				<span class="conv-detail-label">Importe:</span>
				<span class="conv-detail-value conv-highlight"><?php echo esc_html( $datos['importe'] ); ?></span>
			</div>
			<div class="conv-detail-row">
				<span class="conv-detail-label">IBAN:</span>
				<span class="conv-detail-value conv-copyable" id="conv-iban"><?php echo esc_html( $datos['iban'] ); ?></span>
			</div>
			<div class="conv-detail-row">
				<span class="conv-detail-label">Beneficiario:</span>
				<span class="conv-detail-value"><?php echo esc_html( $datos['beneficiario'] ); ?></span>
			</div>
			<div class="conv-detail-row">
				<span class="conv-detail-label">Concepto (MUY IMPORTANTE):</span>
				<span class="conv-detail-value conv-highlight conv-copyable" id="conv-concept"><?php echo esc_html( $datos['pedido'] ); ?></span>
			</div>
		</div>

		<?php if ( '' !== $datos['instrucciones'] ) : ?>
			<div class="conv-transfer-instructions">
				<h4>Instrucciones adicionales</h4>
				<p><?php echo nl2br( esc_html( $datos['instrucciones'] ) ); ?></p>
			</div>
		<?php endif; ?>

		<div class="convoca-alert convoca-alert--info">
			<p>Tu inscripción quedará como <strong>pendiente</strong> hasta que verifiquemos el ingreso (suele tardar 24-48h hábiles).</p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * El bloque del justificante: éxito, error o formulario.
	 *
	 * @param int                 $pago_id Pago.
	 * @param array<string,mixed> $estado  `upload_success` y `upload_error`.
	 * @return string HTML.
	 */
	private function transferencia_justificante( int $pago_id, array $estado ): string {
		$ok     = ! empty( $estado['upload_success'] );
		$error  = (string) ( $estado['upload_error'] ?? '' );
		$prueba = (string) get_post_meta( $pago_id, '_convoca_proof_file', true );

		ob_start();
		?>
		<div class="conv-proof-upload">
			<h4>Adjuntar justificante de pago</h4>
			<p class="text-muted">Si adjuntas el justificante en PDF, podremos validar tu pago mucho más rápido.</p>

			<?php if ( $ok ) : ?>
				<div class="convoca-alert convoca-alert--success">
					✅ Justificante enviado correctamente. Revisaremos tu pago pronto.
				</div>
			<?php elseif ( '' !== $error ) : ?>
				<div class="convoca-alert convoca-alert--danger">
					❌ <?php echo esc_html( $error ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $ok && '' === $prueba ) : ?>
				<form method="post" enctype="multipart/form-data" class="conv-upload-form">
					<?php wp_nonce_field( 'convoca_gateway_proof_upload_action', 'convoca_gateway_proof_nonce' ); ?>
					<input type="hidden" name="convoca_gateway_upload_proof" value="1">
					<div class="form-group">
						<input type="file" name="convoca_gateway_proof_file" accept=".pdf,image/*" required>
						<button type="submit" class="wp-block-button__link">Enviar justificante</button>
					</div>
				</form>
			<?php elseif ( '' !== $prueba ) : ?>
				<div class="conv-proof-exists">
					📄 Ya has enviado un justificante. Si necesitas cambiarlo, contacta con nosotros.
				</div>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Volver e imprimir.
	 *
	 * @return string HTML.
	 */
	private function transferencia_pie(): string {
		return '<div class="conv-actions">
				<a href="' . esc_url( remove_query_arg( 'convoca_gateway_method' ) ) . '" class="conv-back-link">&larr; Volver a elegir método</a>
				<button type="button" class="wp-block-button__link" onclick="window.print()">🖨️ Imprimir instrucciones</button>
			</div>';
	}

	/**
	 * El «copiar al portapapeles» de los datos.
	 *
	 * @return string HTML.
	 */
	private function transferencia_script(): string {
		return <<<'HTML'
		<script>
			document.querySelectorAll('.conv-copyable').forEach(el => {
				el.addEventListener('click', () => {
					const text = el.innerText;
					navigator.clipboard.writeText(text).then(() => {
						const originalText = el.innerText;
						el.innerText = '¡Copiado!';
						setTimeout(() => el.innerText = originalText, 1000);
					});
				});
			});
		</script>
		HTML;
	}
}
