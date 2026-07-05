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
 * Admin list table and management for payments.
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

class Admin_Payments extends \WP_List_Table {


	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_csv_export_early' ) );
		add_action( 'admin_init', array( $this, 'init_list_table' ) );
	}

	/**
	 * Initialize WP_List_Table parent; deferred because convert_to_screen()
	 * is only available after admin_init.
	 */
	public function init_list_table(): void {
		if ( ! function_exists( 'convert_to_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		parent::__construct(
			array(
				'singular' => 'pago',
				'plural'   => 'pagos',
				'ajax'     => false,
				'screen'   => 'conv-gateway-payments',
			)
		);
	}

	/**
	 * Handle CSV export before headers are sent.
	 */
	public function handle_csv_export_early(): void {
		if ( isset( $_GET['page'] ) && 'conv-gateway-payments' === $_GET['page'] && isset( $_GET['action'] ) && 'export_csv' === $_GET['action'] ) {
			if ( check_admin_referer( 'convoca_gateway_export_csv' ) ) {
				$this->handle_export_csv();
			}
		}
	}


	public function add_menu(): void {
		// Main Gateway Menu.
		add_menu_page(
			__( 'Convoca Pagos', 'convoca-gateway' ),
			__( 'Pagos', 'convoca-gateway' ),
			'convoca_gateway_view_payments',
			'conv-gateway-payments',
			array( $this, 'render_page' ),
			'dashicons-cart',
			26
		);

		// Submenu: Payments.
		add_submenu_page(
			'conv-gateway-payments',
			__( 'Todos los Pagos', 'convoca-gateway' ),
			__( 'Todos los Pagos', 'convoca-gateway' ),
			'convoca_gateway_view_payments',
			'conv-gateway-payments',
			array( $this, 'render_page' )
		);
	}

	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'order_id' => __( 'Pedido', 'convoca-gateway' ),
			'amount'   => __( 'Importe', 'convoca-gateway' ),
			'method'   => __( 'Método', 'convoca-gateway' ),
			'status'   => __( 'Estado', 'convoca-gateway' ),
			'origin'   => __( 'Origen', 'convoca-gateway' ),
			'date'     => __( 'Fecha', 'convoca-gateway' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'date'   => array( 'date', true ),
			'amount' => array( 'amount_cents', false ),
		);
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$get_data      = wp_unslash( $_GET );
		$status_filter = $get_data['status_filter'] ?? '';
		$method_filter = $get_data['method_filter'] ?? '';
		$origin_filter = $get_data['origin_filter'] ?? '';

		echo '<div class="alignleft actions">';

		// Status Filter.
		echo '<select name="status_filter">';
		printf( '<option value="">— %s —</option>', esc_html__( 'Todos los estados', 'convoca-gateway' ) );
		foreach ( CPT_Pago::STATUS as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $status_filter, $key, false ), esc_html( $label ) );
		}
		echo '</select>';

		// Method Filter.
		echo '<select name="method_filter">';
		printf( '<option value="">— %s —</option>', esc_html__( 'Todos los métodos', 'convoca-gateway' ) );
		foreach ( array(
			'tarjeta'       => 'Tarjeta',
			'bizum'         => 'Bizum',
			'transferencia' => 'Transferencia',
		) as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $method_filter, $key, false ), esc_html( $label ) );
		}
		echo '</select>';

		// Origin Filter.
		echo '<select name="origin_filter">';
		printf( '<option value="">— %s —</option>', esc_html__( 'Todos los orígenes', 'convoca-gateway' ) );
		foreach ( array(
			'enroll'  => 'Actividades',
			'members' => 'Socio/a',
		) as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $origin_filter, $key, false ), esc_html( $label ) );
		}
		echo '</select>';

		submit_button( __( 'Filtrar', 'convoca-gateway' ), '', 'filter_action', false );

		// Export CSV button.
		echo ' <a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'export_csv' ), 'convoca_gateway_export_csv' ) ) . '" class="button button-secondary">' . esc_html__( 'Exportar CSV', 'convoca-gateway' ) . '</a>';

		echo '</div>';
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$get_data     = wp_unslash( $_GET );
		$orderby      = $get_data['orderby'] ?? 'date';
		$order        = $get_data['order'] ?? 'desc';
		$search       = $get_data['s'] ?? '';

		$args = array(
			'post_type'      => 'pago',
			'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'future' ),
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'order'          => $order,
		);

		// Sortable columns.
		if ( 'amount' === $orderby ) {
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = '_convoca_amount_cents';
		} else {
			$args['orderby'] = 'date';
		}

		// Search by order_id.
		if ( ! empty( $search ) ) {
			$args['meta_query'][] = array(
				'key'     => '_convoca_order_id',
				'value'   => $search,
				'compare' => 'LIKE',
			);
		}

		// Filters.
		foreach ( array( 'status', 'method', 'origin' ) as $key ) {
			if ( ! empty( $get_data[ $key . '_filter' ] ) ) {
				$args['meta_query'][] = array(
					'key'   => '_convoca_' . $key,
					'value' => sanitize_text_field( $get_data[ $key . '_filter' ] ),
				);
			}
		}

		$query       = new \WP_Query( $args );
		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
			)
		);
	}

	public function column_default( $item, $column_name ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return $meta[ $column_name ] ?? '—';
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="pago[]" value="%s" />', $item->ID );
	}

	public function column_order_id( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		$url  = admin_url( 'admin.php?page=conv-gateway-payments-detail&id=' . $item->ID );

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Ver detalle', 'convoca-gateway' ) ),
		);

		if ( $meta['origin'] === 'enroll' && $meta['origin_id'] ) {
			$insc_url          = admin_url( 'admin.php?page=convoca-core-enroll&inscripcion_id=' . (int) $meta['origin_id'] );
			$actions['origin'] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $insc_url ), esc_html__( 'Ver inscripción', 'convoca-gateway' ) );
		}
		if ( $meta['origin'] === 'members' && $meta['origin_id'] ) {
			$member_url        = admin_url( 'admin.php?page=conv-members&member_id=' . (int) $meta['origin_id'] );
			$actions['origin'] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $member_url ), esc_html__( 'Ver miembro', 'convoca-gateway' ) );
		}

		return sprintf( '<strong><a href="%s">%s</a></strong> %s', esc_url( $url ), esc_html( $meta['order_id'] ), $this->row_actions( $actions ) );
	}

	public function column_amount( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return CPT_Pago::format_amount( $meta['amount_cents'] );
	}

	public function column_method( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return ucfirst( $meta['method'] );
	}

	public function column_status( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return CPT_Pago::badge( $meta['status'] );
	}

	public function column_origin( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return match ( $meta['origin'] ) {
			'enroll' => 'Inscripción',
			'members' => 'Socio/a',
			default => $meta['origin'],
		};
	}

	public function column_date( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return esc_html( $meta['created_at'] );
	}

	public function render_page(): void {

		if ( isset( $_GET['action'] ) && 'view' === $_GET['action'] || isset( $_GET['page'] ) && 'conv-gateway-payments-detail' === $_GET['page'] ) {
			$this->render_detail();
			return;
		}

		$this->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Pagos Convoca', 'convoca-gateway' ); ?></h1>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=conv-gateway-payments&action=export_csv' ), 'convoca_gateway_export_csv' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Exportar a CSV', 'convoca-gateway' ); ?></a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=convoca_gateway_export_payments_pdf' ), 'convoca_gateway_export_payments_pdf' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Exportar PDF', 'convoca-gateway' ); ?></a>
			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="page" value="conv-gateway-payments">
				<?php
				$this->search_box( __( 'Buscar pedido', 'convoca-gateway' ), 'search_id' );
				$this->display();
				?>
			</form>
		</div>
		<?php
	}

	/* ── Detail View ───────────────────────────────── */

	public function render_detail(): void {
		$id = (int) ( wp_unslash( $_GET['id'] ?? 0 ) );
		if ( ! $id || 'pago' !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Pago no encontrado.', 'convoca-gateway' ) );
		}

		$meta = CPT_Pago::get_meta( $id );
		$logs = \Convoca\Core\Logger::get_logs(
			array(
				'object_id' => $id,
				'limit'     => 50,
			)
		) ?: array();

		// Handle re-send email.
		if ( isset( $_POST['convoca_gateway_resend_email'] ) && check_admin_referer( 'convoca_gateway_resend_' . $id ) ) {
			do_action( 'convoca_gateway_resend_email', $id );
			echo '<div class="updated"><p>' . esc_html__( 'Email reenviado a la cola.', 'convoca-gateway' ) . '</p></div>';
		}

		// Handle refund.
		if ( isset( $_POST['convoca_gateway_refund_payment'] ) && check_admin_referer( 'convoca_gateway_refund_' . $id ) ) {
			$this->handle_refund( $id );
			echo '<div class="updated"><p>' . esc_html__( 'Pago marcado como reembolsado.', 'convoca-gateway' ) . '</p></div>';
			$meta = CPT_Pago::get_meta( $id ); // Refresh meta.
		}

		// Handle manual mark as paid.
		if ( isset( $_POST['convoca_gateway_mark_paid'] ) && check_admin_referer( 'convoca_gateway_mark_paid_' . $id ) ) {
			$this->handle_manual_paid( $id );
			echo '<div class="updated"><p>' . esc_html__( 'Pago marcado como PAGADO manualmente.', 'convoca-gateway' ) . '</p></div>';
			$meta = CPT_Pago::get_meta( $id ); // Refresh meta.
		}

		?>
		<div class="wrap">
			<?php /* translators: %s: order ID */ ?>
			<h1><?php printf( esc_html__( 'Pago #%s', 'convoca-gateway' ), esc_html( $meta['order_id'] ) ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=conv-gateway-payments' ) ); ?>" class="button">&lsaquo; <?php echo esc_html__( 'Volver al listado', 'convoca-gateway' ); ?></a>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Información del Pago', 'convoca-gateway' ); ?></span></h2>
							<div class="inside">
								<table class="form-table">
									<tr>
										<th><?php esc_html_e( 'ID Pedido', 'convoca-gateway' ); ?></th>
										<td><code><?php echo esc_html( $meta['order_id'] ); ?></code></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Estado', 'convoca-gateway' ); ?></th>
										<td><?php echo CPT_Pago::badge( $meta['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge returns safe HTML ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Importe', 'convoca-gateway' ); ?></th>
										<td><strong><?php echo CPT_Pago::format_amount( $meta['amount_cents'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_amount returns escaped output ?></strong></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Método', 'convoca-gateway' ); ?></th>
										<td><?php echo esc_html( ucfirst( $meta['method'] ) ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Origen', 'convoca-gateway' ); ?></th>
										<td>
											<?php echo esc_html( $meta['origin'] ); ?> 
											(#<?php echo (int) $meta['origin_id']; ?>)
										</td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Descripción producto', 'convoca-gateway' ); ?></th>
										<td><?php echo esc_html( $meta['product_desc'] ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Código Autorización', 'convoca-gateway' ); ?></th>
										<td><?php echo esc_html( $meta['redsys_auth_code'] ?: '—' ); ?></td>
									</tr>
									<tr>
										<th><?php esc_html_e( 'Respuesta Redsys', 'convoca-gateway' ); ?></th>
										<td><?php echo esc_html( $meta['redsys_response'] ?: '—' ); ?></td>
									</tr>
									<?php if ( ! empty( $meta['proof_file'] ) ) : ?>
									<tr>
										<th><?php esc_html_e( 'Justificante de pago', 'convoca-gateway' ); ?></th>
										<td>
											<a href="<?php echo esc_url( $meta['proof_file'] ); ?>" class="button" target="_blank">
												<span class="dashicons dashicons-media-document" style="vertical-align: middle;"></span>
												<?php esc_html_e( 'Ver justificante', 'convoca-gateway' ); ?>
											</a>
										</td>
									</tr>
									<?php endif; ?>
								</table>
							</div>
						</div>

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
													<td><?php echo esc_html( $log['timestamp'] ?? '—' ); ?></td>
													<td><?php echo CPT_Pago::badge( $log['level'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge returns safe HTML ?></td>
													<td><?php echo esc_html( $log['message'] ); ?></td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</tbody>
								</table>
							</div>
						</div>

					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="postbox">
							<h2 class="hndle"><span><?php esc_html_e( 'Acciones', 'convoca-gateway' ); ?></span></h2>
							<div class="inside">
								<form method="post">
									<?php wp_nonce_field( 'convoca_gateway_resend_' . $id ); ?>
									<button type="submit" name="convoca_gateway_resend_email" class="button button-large" <?php echo $meta['status'] !== 'paid' ? 'disabled' : ''; ?>>
										<?php esc_html_e( 'Reenviar email de confirmación', 'convoca-gateway' ); ?>
									</button>
									<p class="description"><?php esc_html_e( 'Solo disponible para pagos completados.', 'convoca-gateway' ); ?></p>
								</form>
								<?php if ( $meta['status'] !== 'paid' ) : ?>
									<hr>
									<form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Confirmas que has recibido el dinero de este pago?', 'convoca-gateway' ) ); ?>');">
										<?php wp_nonce_field( 'convoca_gateway_mark_paid_' . $id ); ?>
										<button type="submit" name="convoca_gateway_mark_paid" class="button button-primary full-width"><?php esc_html_e( 'Confirmar Pago Manual', 'convoca-gateway' ); ?></button>
										<p class="description"><?php esc_html_e( 'Úsalo para confirmar transferencias recibidas.', 'convoca-gateway' ); ?></p>
									</form>
								<?php endif; ?>
								<?php if ( $meta['status'] === 'paid' ) : ?>
									<hr>
									<form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Estás seguro de marcar este pago como reembolsado?', 'convoca-gateway' ) ); ?>');">
										<?php wp_nonce_field( 'convoca_gateway_refund_' . $id ); ?>
										<button type="submit" name="convoca_gateway_refund_payment" class="button button-link-delete" style="color: #d63638;"><?php esc_html_e( 'Marcar como Reembolsado', 'convoca-gateway' ); ?></button>
									</form>
								<?php endif; ?>
								<hr>
								<?php if ( $meta['origin'] === 'enroll' && $meta['origin_id'] ) : ?>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $meta['origin_id'] . '&action=edit' ) ); ?>" class="button full-width">
										<?php echo esc_html__( 'Ver inscripción original', 'convoca-gateway' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<style>
			.full-width { width: 100%; text-align: center; }
		</style>
		<?php
	}

	/* ── CSV Export ───────────────────────────────── */

	/**
	 * Handle CSV export request.
	 */
	private function handle_export_csv(): void {
		$get_data = wp_unslash( $_GET );
		$orderby  = $get_data['orderby'] ?? 'date';
		$order    = $get_data['order'] ?? 'desc';
		$search   = $get_data['s'] ?? '';

		$args = array(
			'post_type'   => 'pago',
			'post_status' => 'publish',
			'orderby'     => 'meta_value',
			'meta_key'    => '_convoca_created_at',
			'order'       => $order,
		);

		// Search by order_id.
		if ( ! empty( $search ) ) {
			$args['meta_query'][] = array(
				'key'     => '_convoca_order_id',
				'value'   => $search,
				'compare' => 'LIKE',
			);
		}

		// Filters from GET.
		foreach ( array( 'status', 'method', 'origin' ) as $key ) {
			if ( ! empty( $get_data[ $key . '_filter' ] ) ) {
				$args['meta_query'][] = array(
					'key'   => '_convoca_' . $key,
					'value' => sanitize_text_field( $get_data[ $key . '_filter' ] ),
				);
			}
		}

		CSV_Exporter::export( $args );
	}

	/**
	 * Mark payment as refunded.
	 */
	private function handle_refund( int $id ): void {
		update_post_meta( $id, '_convoca_status', 'refunded' );

		\Convoca\Core\Logger::log(
			__( 'Pago marcado como REEMBOLSADO manualmente.', 'convoca-gateway' ),
			'info',
			'Gateway/Refund',
			$id
		);

		do_action( 'convoca_gateway_payment_refunded', $id );
	}

	/**
	 * Mark payment as paid manually.
	 */
	private function handle_manual_paid( int $id ): void {
		update_post_meta( $id, '_convoca_status', 'paid' );
		update_post_meta( $id, '_convoca_paid_at', current_time( 'mysql' ) );

		\Convoca\Core\Logger::log(
			__( 'Pago marcado como PAGADO manualmente.', 'convoca-gateway' ),
			'info',
			'Gateway/ManualPaid',
			$id
		);

		$meta = CPT_Pago::get_meta( $id );
		\Convoca\Core\Utils::do_action( 'convoca_gateway_payment_completed', 'convoca_payment_completed', $id, $meta['origin'], (int) $meta['origin_id'], $meta );
	}
}
