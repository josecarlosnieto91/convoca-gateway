<?php

/**
 * Convoca Gateway
 *
 * @package    Convoca\Gateway
 * @subpackage Includes
 *
 * @copyright  Copyright (C) 2026 Jose Carlos Nieto Ramos
 * @license    GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * Admin list table for generated payment links.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Admin_Links extends \WP_List_Table {


	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'enlace',
				'plural'   => 'enlaces',
				'ajax'     => false,
				'screen'   => 'conv-gateway-links',
			)
		);

		add_action( 'admin_post_convoca_gateway_save_link', array( $this, 'handle_save' ) );
	}

	/**
	 * Acciones en bloque del listado. El borrado pide confirmación después.
	 */
	protected function get_bulk_actions(): array {
		if ( ! Admin_Record_Actions::user_can() ) {
			return array();
		}

		return array( 'convoca_delete' => __( 'Eliminar', 'convoca-gateway' ) );
	}

	public function get_columns(): array {
		return array(
			'cb'       => __( '<input type="checkbox" />', 'convoca-gateway' ),
			'order_id' => __( 'Pedido', 'convoca-gateway' ),
			'concepto' => __( 'Concepto', 'convoca-gateway' ),
			'amount'   => __( 'Importe', 'convoca-gateway' ),
			'method'   => __( 'Método', 'convoca-gateway' ),
			'origin'   => __( 'Origen', 'convoca-gateway' ),
			'email'    => __( 'Email Destinatario', 'convoca-gateway' ),
			// Sin «Estado Pago»: un enlace es una plantilla, no un cobro pendiente. Su
			// estado («Activo», «Caducado» o cuántos cobros ha emitido) es lo de al lado.
			'active'   => __( 'Activo', 'convoca-gateway' ),
			'expires'  => __( 'Caducidad', 'convoca-gateway' ),
			'created'  => __( 'Generado', 'convoca-gateway' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'created' => array( 'created_at', true ),
			'amount'  => array( 'amount_cents', false ),
		);
	}

	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$orderby      = wp_unslash( $_GET['orderby'] ?? 'date' );
		$order        = wp_unslash( $_GET['order'] ?? 'desc' );

		$args = array(
			'post_type'      => 'pago',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'orderby'        => 'meta_value',
			'meta_key'       => '_convoca_created_at',
			'order'          => $order,
			'meta_query'     => array(
				array(
					'key'   => '_convoca_origin',
					'value' => 'link_payment',
				),
			),
		);

		$query       = new \WP_Query( $args );
		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="enlace[]" value="%s" />', $item->ID );
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_order_id( $item ): string {
		$meta     = CPT_Pago::get_meta( $item->ID );
		$url      = admin_url( 'admin.php?page=conv-gateway-payments-detail&id=' . $item->ID );
		$order_id = $meta['order_id'] ?? '—';
		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $order_id ) . '</strong></a>';
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_concepto( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		$url  = admin_url( 'admin.php?page=conv-gateway-payments-detail&id=' . $item->ID );

		// Build the actual link for quick copy.
		// expires_at puede ser string vacío cuando el meta no existe (PHP 8.1+ TypeError si pasamos string a ?int).
		$expires_ts = ! empty( $meta['expires_at'] ) ? (int) $meta['expires_at'] : null;
		$link_url   = Payment_Handler::get_payment_link( $item->ID, $meta['link_key'], $expires_ts );

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Ver detalles', 'convoca-gateway' ) ),
			'copy' => sprintf( '<a href="#" class="conv-gateway-copy-link" data-link="%s">%s</a>', esc_attr( $link_url ), esc_html__( 'Copiar enlace', 'convoca-gateway' ) ),
		);

		if ( Admin_Record_Actions::user_can() ) {
			$actions['edit']   = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=conv-gateway-links&action=edit&id=' . $item->ID ) ),
				esc_html__( 'Editar', 'convoca-gateway' )
			);
			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" aria-label="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=conv-gateway-links&action=delete&id=' . $item->ID ) ),
				esc_attr__( 'Eliminar este enlace de pago', 'convoca-gateway' ),
				esc_html__( 'Eliminar', 'convoca-gateway' )
			);
		}

		return sprintf( '<strong>%s</strong> %s', esc_html( $meta['product_desc'] ), $this->row_actions( $actions ) );
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_method( $item ): string {
		$meta    = CPT_Pago::get_meta( $item->ID );
		$methods = array(
			'tarjeta'       => __( 'Tarjeta', 'convoca-gateway' ),
			'bizum'         => __( 'Bizum', 'convoca-gateway' ),
			'transferencia' => __( 'Transferencia', 'convoca-gateway' ),
			'any'           => __( 'Cualquier método', 'convoca-gateway' ),
		);
		return esc_html( $methods[ $meta['method'] ] ?? $meta['method'] );
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_origin( $item ): string {
		return '<span class="convoca-badge convoca-badge--info">' . __( 'Enlace de pago', 'convoca-gateway' ) . '</span>';
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_amount( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );

		// Enlace de donativo: aún no hay importe, lo pone quien aporta.
		if ( ! empty( $meta['open_amount'] ) ) {
			return esc_html__( 'Importe libre', 'convoca-gateway' );
		}

		return CPT_Pago::format_amount( $meta['amount_cents'] );
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_email( $item ): string {
		$meta  = CPT_Pago::get_meta( $item->ID );
		$email = $meta['recipient_email'] ?: ( $meta['payer_email'] ?? '' );

		return esc_html( $email ?: '—' );
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_expires( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		if ( ! $meta['expires_at'] ) {
			return '<span style="color:#2d5a27; font-weight:600;">∞ ' . __( 'Nunca', 'convoca-gateway' ) . '</span>';
		}

		$expired = $meta['expires_at'] < time();
		$color   = $expired ? '#d63638' : 'inherit';

		return sprintf( '<span style="color:%s">%s</span>', $color, wp_date( 'd/m/Y H:i', $meta['expires_at'] ) );
	}

	public function column_active( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );

		$expired = $meta['expires_at'] > 0 && $meta['expires_at'] < time();

		// Un enlace no se gasta: emite un cobro por cada uso. Se cuentan sus cobros
		// (los pagos con `origin_id` apuntando al enlace), no su propio estado.
		$emitidos = CPT_Pago::cobros_emitidos( (int) $item->ID );

		if ( $emitidos > 0 && ! $expired ) {
			return sprintf(
				'<span class="convoca-badge convoca-badge--info">%s</span>',
				sprintf(
					/* translators: %d: número de cobros emitidos por el enlace. */
					_n( '%d cobro', '%d cobros', $emitidos, 'convoca-gateway' ),
					$emitidos
				)
			);
		}

		if ( $expired ) {
			return '<span class="convoca-badge convoca-badge--danger">' . __( 'Caducado', 'convoca-gateway' ) . '</span>';
		}

		return '<span class="convoca-badge convoca-badge--success">' . __( 'Activo', 'convoca-gateway' ) . '</span>';
	}

	/**
	 * Column renderer.
	 *
	 * @param \WP_Post $item Row item (WP_Post from WP_Query).
	 */
	public function column_created( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return esc_html( $meta['created_at'] );
	}

	public function render_page(): void {
		// Borrado: la acción (de fila o en bloque) lleva a la pantalla de confirmación.
		$borrado = Admin_Record_Actions::pending_action();
		if ( 'convoca_delete' === $borrado || ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] ) ) {
			Admin_Record_Actions::render_confirm( 'enlace', Admin_Record_Actions::requested_ids( 'enlace' ) );
			return;
		}

		// Edición de un enlace generado.
		if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
			$this->render_edit( absint( wp_unslash( $_GET['id'] ?? 0 ) ) );
			return;
		}

		$cols = array_keys( $this->get_columns() );
		$this->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Enlaces de Pago Generados', 'convoca-gateway' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=conv-gateway-generador' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Generar nuevo', 'convoca-gateway' ); ?></a>
			<hr class="wp-header-end">

			<?php Admin_Record_Actions::maybe_notice( 'enlace' ); ?>

			<form method="get">
				<input type="hidden" name="page" value="conv-gateway-links">
				<?php $this->display(); ?>
			</form>
		</div>
		<script>
		document.addEventListener('click', function(e) {
			if (e.target.classList.contains('conv-gateway-copy-link')) {
				e.preventDefault();
				const link = e.target.dataset.link;
				
				// Fallback for non-https/older browsers.
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(link).then(() => {
						showSuccess(e.target);
					});
				} else {
					const textArea = document.createElement("textarea");
					textArea.value = link;
					textArea.style.position = "fixed";
					textArea.style.left = "-999999px";
					textArea.style.top = "-999999px";
					document.body.appendChild(textArea);
					textArea.focus();
					textArea.select();
					try {
						document.execCommand('copy');
						showSuccess(e.target);
					} catch (err) {
						console.error('Error al copiar', err);
					}
					document.body.removeChild(textArea);
				}
			}
		});

		function showSuccess(el) {
			const originalText = el.textContent;
			el.textContent = '¡Copiado!';
			el.style.color = '#2d5a27';
			setTimeout(() => { 
				el.textContent = originalText;
				el.style.color = '';
			}, 2000);
		}
		</script>
		<?php
	}

	/*
	 * ── Edición de un enlace ───────────────────────────────────────────────
	 */

	/**
	 * Avisos de la pantalla de edición (guardado o error).
	 */
	private static function save_notices(): void {
		$error = isset( $_GET['convoca_save_error'] ) ? sanitize_key( wp_unslash( $_GET['convoca_save_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.

		if ( '' !== $error ) {
			$mensajes = array(
				'concepto' => __( 'El concepto es obligatorio.', 'convoca-gateway' ),
				'importe'  => __( 'El importe mínimo es 0,50 €. Déjalo vacío si quieres un enlace de importe libre.', 'convoca-gateway' ),
			);
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $mensajes[ $error ] ?? __( 'No se pudo guardar el enlace.', 'convoca-gateway' ) )
			);
		}

		if ( isset( $_GET['convoca_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Enlace actualizado.', 'convoca-gateway' ) . '</p></div>';
		}
	}

	/**
	 * Formulario de edición de un enlace generado.
	 *
	 * No se toca el token del enlace: la URL publicada sigue valiendo después de
	 * editar (si cambiara, todo lo ya repartido dejaría de funcionar).
	 *
	 * @param int $id Identificador del enlace.
	 */
	public function render_edit( int $id ): void {
		if ( ! Admin_Record_Actions::user_can() ) {
			wp_die( esc_html__( 'No tienes permiso para editar enlaces de pago.', 'convoca-gateway' ) );
		}

		if ( ! $id || 'pago' !== get_post_type( $id ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Enlace no encontrado.', 'convoca-gateway' ) . '</p></div></div>';
			return;
		}

		$meta    = CPT_Pago::get_meta( $id );
		$token   = (string) get_post_meta( $id, '_convoca_link_key', true );
		$enlace  = Payment_Handler::get_payment_link( $id, $token, ! empty( $meta['expires_at'] ) ? (int) $meta['expires_at'] : null );
		$abierto = ! empty( $meta['open_amount'] );
		$nunca   = empty( $meta['expires_at'] );
		$fecha   = $nunca ? '' : wp_date( 'Y-m-d', (int) $meta['expires_at'] );
		$volver  = admin_url( 'admin.php?page=conv-gateway-links' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Editar enlace de pago', 'convoca-gateway' ); ?></h1>
			<a href="<?php echo esc_url( $volver ); ?>" class="page-title-action"><?php esc_html_e( 'Volver a los enlaces', 'convoca-gateway' ); ?></a>
			<hr class="wp-header-end">

			<?php self::save_notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="convoca_gateway_save_link">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'convoca_gateway_save_link' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Pedido', 'convoca-gateway' ); ?></th>
						<td>
							<code><?php echo esc_html( (string) $meta['order_id'] ); ?></code>
							<span class="description">#<?php echo (int) $id; ?> · <?php echo esc_html( (string) $meta['created_at'] ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enlace', 'convoca-gateway' ); ?></th>
						<td>
							<input type="text" class="large-text code" readonly value="<?php echo esc_url( $enlace ); ?>">
							<p class="description"><?php esc_html_e( 'La dirección no cambia al editar: si el enlace ya está publicado, sigue funcionando igual.', 'convoca-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="convoca_concepto"><?php esc_html_e( 'Concepto', 'convoca-gateway' ); ?> *</label></th>
						<td><input type="text" name="concepto" id="convoca_concepto" class="regular-text" required value="<?php echo esc_attr( (string) $meta['product_desc'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="convoca_importe"><?php esc_html_e( 'Importe (€)', 'convoca-gateway' ); ?></label></th>
						<td>
							<input type="text" name="importe" id="convoca_importe" class="small-text" value="<?php echo $abierto ? '' : esc_attr( number_format( (int) $meta['amount_cents'] / 100, 2, ',', '.' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Déjalo vacío para que sea un enlace de donativo de importe libre (lo elige quien aporta, mínimo 0,50 €).', 'convoca-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="convoca_method"><?php esc_html_e( 'Método de pago', 'convoca-gateway' ); ?></label></th>
						<td>
							<select name="method" id="convoca_method">
								<?php
								$metodos = array(
									'any'           => __( 'Cualquier método (lo elige quien paga)', 'convoca-gateway' ),
									'tarjeta'       => __( 'Tarjeta', 'convoca-gateway' ),
									'bizum'         => __( 'Bizum', 'convoca-gateway' ),
									'transferencia' => __( 'Transferencia', 'convoca-gateway' ),
								);
								foreach ( $metodos as $slug => $etiqueta ) :
									?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) $meta['method'], $slug ); ?>><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="convoca_expires"><?php esc_html_e( 'Caducidad', 'convoca-gateway' ); ?></label></th>
						<td>
							<input type="date" name="expires" id="convoca_expires" value="<?php echo esc_attr( $fecha ); ?>">
							<label style="margin-left:1rem;">
								<input type="checkbox" name="never_expires" value="1" <?php checked( $nunca ); ?>>
								<?php esc_html_e( 'Sin caducidad', 'convoca-gateway' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Con la casilla marcada el enlace no caduca nunca. Sin fecha y sin casilla se aplica la caducidad por defecto del plugin.', 'convoca-gateway' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="convoca_email"><?php esc_html_e( 'Email de notificación', 'convoca-gateway' ); ?></label></th>
						<td>
							<input type="email" name="email" id="convoca_email" class="regular-text" value="<?php echo esc_attr( (string) $meta['recipient_email'] ); ?>">
							<p class="description"><?php esc_html_e( 'Opcional. Es también el correo del recibo si lo deja quien paga.', 'convoca-gateway' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar cambios', 'convoca-gateway' ); ?></button>
					<a class="button" href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( 'Cancelar', 'convoca-gateway' ); ?></a>
					<a class="button button-link-delete" href="<?php echo esc_url( admin_url( 'admin.php?page=conv-gateway-links&action=delete&id=' . $id ) ); ?>"><?php esc_html_e( 'Eliminar este enlace', 'convoca-gateway' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Guarda los cambios del enlace (admin-post).
	 */
	public function handle_save(): void {
		check_admin_referer( 'convoca_gateway_save_link' );

		if ( ! Admin_Record_Actions::user_can() ) {
			wp_die( esc_html__( 'No tienes permiso para editar enlaces de pago.', 'convoca-gateway' ), 403 );
		}

		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id || 'pago' !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Enlace no encontrado.', 'convoca-gateway' ), 404 );
		}

		$destino = admin_url( 'admin.php?page=conv-gateway-links&action=edit&id=' . $id );

		$concepto = sanitize_text_field( wp_unslash( $_POST['concepto'] ?? '' ) );
		if ( '' === $concepto ) {
			wp_safe_redirect( add_query_arg( 'convoca_save_error', 'concepto', $destino ) );
			exit;
		}

		// Importe vacío = enlace de donativo (importe libre).
		$importe_crudo = trim( (string) wp_unslash( $_POST['importe'] ?? '' ) );
		$abierto       = ( '' === $importe_crudo );
		$cents         = $abierto ? 0 : (int) round( (float) str_replace( ',', '.', $importe_crudo ) * 100 );

		if ( ! $abierto && $cents < 50 ) {
			wp_safe_redirect( add_query_arg( 'convoca_save_error', 'importe', $destino ) );
			exit;
		}

		$permitidos = array( 'any', 'tarjeta', 'bizum', 'transferencia' );
		$method     = sanitize_key( wp_unslash( $_POST['method'] ?? 'any' ) );
		if ( ! in_array( $method, $permitidos, true ) ) {
			$method = 'any';
		}

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$nunca = ! empty( $_POST['never_expires'] );
		$fecha = sanitize_text_field( wp_unslash( $_POST['expires'] ?? '' ) );

		if ( $nunca ) {
			$expira = 0;
		} elseif ( '' !== $fecha ) {
			$expira = (int) strtotime( $fecha . ' 23:59:59' );
		} else {
			$expira = Link_Expiry::compute_expiry_timestamp( Link_Expiry::default_expiry_days() );
		}

		update_post_meta( $id, '_convoca_product_desc', $concepto );
		update_post_meta( $id, '_convoca_amount_cents', $cents );
		update_post_meta( $id, '_convoca_open_amount', $abierto ? '1' : '' );
		update_post_meta( $id, '_convoca_method', $method );
		update_post_meta( $id, '_convoca_recipient_email', $email );
		update_post_meta( $id, '_convoca_expires_at', $expira );

		// El token (`_convoca_link_key`) NO se toca a propósito: la URL publicada sigue valiendo.
		$order_id = (string) get_post_meta( $id, '_convoca_order_id', true );
		$titulo   = $abierto
			? sprintf( '%s — %s — Enlace de donativo (importe libre)', $order_id, $concepto )
			: sprintf( '%s — %s€ — Enlace de pago', $order_id, number_format( $cents / 100, 2, ',', '.' ) );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $titulo,
			)
		);

		\Convoca\Core\Logger::info(
			sprintf(
				'Enlace #%d editado: concepto «%s», importe %s, método %s, caduca %s.',
				$id,
				$concepto,
				$abierto ? 'libre' : number_format( $cents / 100, 2, ',', '.' ) . '€',
				$method,
				$expira ? wp_date( 'd/m/Y', $expira ) : 'nunca'
			),
			'Gateway/LinkEdit',
			$id
		);

		wp_safe_redirect( add_query_arg( 'convoca_saved', 1, $destino ) );
		exit;
	}
}
