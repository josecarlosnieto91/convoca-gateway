<?php
/**
 * La ficha de un pago en el panel: solo presentación.
 *
 * Estaba dentro de `Admin_Payments::render_detail()` con 210 líneas, complejidad ciclomática 26 y
 * un NPath de 2.239.488 — el método más caro del plugin. Aquí cada bloque es un método corto y la
 * clase que lo contiene empieza sin métodos, así que se puede partir sin disparar la regla de clase.
 *
 * @package ConvocaGateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ficha de un pago.
 */
final class Admin_Payments_Detail {

	/**
	 * Pinta la ficha completa.
	 *
	 * @param int                 $id   Pago.
	 * @param array<string,mixed> $meta Metadatos.
	 * @param array<int,mixed>    $logs Eventos.
	 * @return void
	 */
	public function render( int $id, array $meta, array $logs ): void {
		?>
		<div class="wrap">
			<h1><?php printf( esc_html__( 'Pago #%s', 'convoca-gateway' ), esc_html( (string) ( $meta['order_id'] ?? '' ) ) ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=conv-gateway-payments' ) ); ?>" class="button">&lsaquo; <?php echo esc_html__( 'Volver al listado', 'convoca-gateway' ); ?></a>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado dentro.
						echo $this->caja_info( $meta );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
						echo $this->caja_logs( $logs );
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
						echo $this->caja_acciones( $id, $meta );
						?>
					</div>
				</div>
			</div>
		</div>
		<style>
			.full-width { width: 100%; text-align: center; }
		</style>
		<?php
	}

	/**
	 * La caja con los datos del pago.
	 *
	 * @param array<string,mixed> $meta Metadatos.
	 * @return string HTML.
	 */
	private function caja_info( array $meta ): string {
		ob_start();
		?>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Información del Pago', 'convoca-gateway' ); ?></span></h2>
			<div class="inside">
				<table class="form-table">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filas propias, escapadas dentro.
					echo $this->filas_base( $meta );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
					echo $this->filas_recibo_y_justificante( $meta );
					?>
				</table>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Las filas que siempre están.
	 *
	 * @param array<string,mixed> $meta Metadatos.
	 * @return string HTML.
	 */
	private function filas_base( array $meta ): string {
		$filas = array(
			__( 'ID Pedido', 'convoca-gateway' ) => '<code>' . esc_html( (string) ( $meta['order_id'] ?? '' ) ) . '</code>',
			__( 'Estado', 'convoca-gateway' )    => CPT_Pago::badge( (string) ( $meta['status'] ?? '' ) ),
			__( 'Importe', 'convoca-gateway' )   => '<strong>' . CPT_Pago::format_amount( (int) ( $meta['amount_cents'] ?? 0 ) ) . '</strong>',
			__( 'Método', 'convoca-gateway' )   => esc_html( ucfirst( (string) ( $meta['method'] ?? '' ) ) ),
			__( 'Origen', 'convoca-gateway' )    => esc_html( (string) ( $meta['origin'] ?? '' ) ) . ' (#' . (int) ( $meta['origin_id'] ?? 0 ) . ')',
			__( 'Descripción producto', 'convoca-gateway' ) => esc_html( (string) ( $meta['product_desc'] ?? '' ) ),
			__( 'Código Autorización', 'convoca-gateway' ) => esc_html( (string) ( $meta['redsys_auth_code'] ?? '' ) ?: '—' ),
		);

		$html = '';
		foreach ( $filas as $titulo => $valor ) {
			$html .= '<tr><th>' . esc_html( $titulo ) . '</th><td>' . $valor . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- el valor ya viene escapado.
		}

		$respuesta = (string) ( $meta['redsys_response'] ?? '' );
		$html     .= '<tr><th>' . esc_html__( 'Respuesta Redsys', 'convoca-gateway' ) . '</th><td><code>' . esc_html( '' !== $respuesta ? $respuesta : '—' ) . '</code>';

		if ( '' !== $respuesta ) {
			$html .= '<br><span class="description">' . esc_html( Redsys_Client::get_response_message( $respuesta ) ) . '</span>';
		}

		return $html . '</td></tr>';
	}

	/**
	 * Las filas que solo salen si hay recibo o justificante.
	 *
	 * @param array<string,mixed> $meta Metadatos.
	 * @return string HTML.
	 */
	private function filas_recibo_y_justificante( array $meta ): string {
		$html = '';

		if ( ! empty( $meta['receipt_number'] ) ) {
			$html .= '<tr><th>' . esc_html__( 'Número de recibo', 'convoca-gateway' ) . '</th><td><code>' . esc_html( (string) $meta['receipt_number'] ) . '</code>';

			if ( ! empty( $meta['receipt_pdf'] ) ) {
				$html .= '<br><a href="' . esc_url( (string) $meta['receipt_pdf'] ) . '" class="button" target="_blank">' . esc_html__( 'Ver recibo', 'convoca-gateway' ) . '</a>';
			}

			$html .= '</td></tr>';
		}

		if ( ! empty( $meta['proof_file'] ) ) {
			$html .= '<tr><th>' . esc_html__( 'Justificante de pago', 'convoca-gateway' ) . '</th><td><a href="' . esc_url( (string) $meta['proof_file'] ) . '" class="button" target="_blank"><span class="dashicons dashicons-media-document" style="vertical-align: middle;"></span> ' . esc_html__( 'Ver justificante', 'convoca-gateway' ) . '</a></td></tr>';
		}

		return $html;
	}

	/**
	 * La caja del registro de eventos.
	 *
	 * @param array<int,mixed> $logs Eventos.
	 * @return string HTML.
	 */
	private function caja_logs( array $logs ): string {
		ob_start();
		?>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Registro de eventos (Logs)', 'convoca-gateway' ); ?></span></h2>
			<div class="inside">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Fecha', 'convoca-gateway' ); ?></th>
							<th><?php esc_html_e( 'Nivel', 'convoca-gateway' ); ?></th>
							<th><?php esc_html_e( 'Mensaje', 'convoca-gateway' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $logs ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'No hay eventos registrados.', 'convoca-gateway' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( array_reverse( $logs ) as $log ) : ?>
								<tr>
									<td><?php echo esc_html( (string) ( $log['timestamp'] ?? '—' ) ); ?></td>
									<td><?php echo CPT_Pago::badge( (string) ( $log['level'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge returns safe HTML ?></td>
									<td><?php echo esc_html( (string) ( $log['message'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * La caja de acciones.
	 *
	 * @param int                 $id   Pago.
	 * @param array<string,mixed> $meta Metadatos.
	 * @return string HTML.
	 */
	private function caja_acciones( int $id, array $meta ): string {
		$pagado = 'paid' === (string) ( $meta['status'] ?? '' );

		ob_start();
		?>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Acciones', 'convoca-gateway' ); ?></span></h2>
			<div class="inside">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado dentro.
				echo $this->accion_reenvio( $id, $pagado );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
				echo $this->accion_donacion( $id, $meta );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
				echo $this->accion_estado( $id, $pagado );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- idem.
				echo $this->enlace_origen( $meta );
				?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Reenviar el correo de confirmación.
	 *
	 * @param int  $id     Pago.
	 * @param bool $pagado Si está pagado.
	 * @return string HTML.
	 */
	private function accion_reenvio( int $id, bool $pagado ): string {
		ob_start();
		?>
		<form method="post">
			<?php wp_nonce_field( 'convoca_gateway_resend_' . $id ); ?>
			<button type="submit" name="convoca_gateway_resend_email" class="button button-large" <?php echo $pagado ? '' : 'disabled'; ?>>
				<?php esc_html_e( 'Reenviar email de confirmación', 'convoca-gateway' ); ?>
			</button>
			<p class="description"><?php esc_html_e( 'Solo disponible para pagos completados.', 'convoca-gateway' ); ?></p>
		</form>
		<hr>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Marcar el pago como donación.
	 *
	 * @param int                 $id   Pago.
	 * @param array<string,mixed> $meta Metadatos.
	 * @return string HTML.
	 */
	private function accion_donacion( int $id, array $meta ): string {
		ob_start();
		?>
		<form method="post">
			<?php wp_nonce_field( 'convoca_gateway_donacion_' . $id ); ?>
			<label>
				<input type="checkbox" name="convoca_gateway_es_donacion" value="1" <?php checked( '1' === (string) ( $meta['es_donacion'] ?? '' ) ); ?>>
				<?php esc_html_e( 'Este pago es una donación', 'convoca-gateway' ); ?>
			</label>
			<button type="submit" name="convoca_gateway_toggle_donacion" class="button button-small"><?php esc_html_e( 'Guardar', 'convoca-gateway' ); ?></button>
			<p class="description"><?php esc_html_e( 'Si se marca, el recibo se sustituye por un justificante de donación (Ley 49/2002) con numeración D-AÑO-NNN.', 'convoca-gateway' ); ?></p>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Confirmar el pago a mano o marcarlo como reembolsado.
	 *
	 * @param int  $id     Pago.
	 * @param bool $pagado Si está pagado.
	 * @return string HTML.
	 */
	private function accion_estado( int $id, bool $pagado ): string {
		ob_start();

		if ( ! $pagado ) {
			?>
			<hr>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Confirmas que has recibido el dinero de este pago?', 'convoca-gateway' ) ); ?>');">
				<?php wp_nonce_field( 'convoca_gateway_mark_paid_' . $id ); ?>
				<button type="submit" name="convoca_gateway_mark_paid" class="button button-primary full-width"><?php esc_html_e( 'Confirmar Pago Manual', 'convoca-gateway' ); ?></button>
				<p class="description"><?php esc_html_e( 'Úsalo para confirmar transferencias recibidas.', 'convoca-gateway' ); ?></p>
			</form>
			<?php
		} else {
			?>
			<hr>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Estás seguro de marcar este pago como reembolsado?', 'convoca-gateway' ) ); ?>');">
				<?php wp_nonce_field( 'convoca_gateway_refund_' . $id ); ?>
				<button type="submit" name="convoca_gateway_refund_payment" class="button button-link-delete" style="color: #d63638;"><?php esc_html_e( 'Marcar como Reembolsado', 'convoca-gateway' ); ?></button>
			</form>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Enlace a la inscripción de origen, si la hay.
	 *
	 * @param array<string,mixed> $meta Metadatos.
	 * @return string HTML.
	 */
	private function enlace_origen( array $meta ): string {
		if ( 'enroll' !== (string) ( $meta['origin'] ?? '' ) || empty( $meta['origin_id'] ) ) {
			return '<hr>';
		}

		return '<hr><a href="' . esc_url( admin_url( 'post.php?post=' . (int) $meta['origin_id'] . '&action=edit' ) ) . '" class="button full-width">'
			. esc_html__( 'Ver inscripción original', 'convoca-gateway' ) . '</a>';
	}
}
