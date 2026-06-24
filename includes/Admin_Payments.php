<?php
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
		echo '<option value="">— ' . __( 'Todos los estados', 'convoca-gateway' ) . ' —</option>';
		foreach ( CPT_Pago::STATUS as $key => $label ) {
			$sel = selected( $status_filter, $key, false );
			echo "<option value='" . esc_attr( $key ) . "' $sel>" . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		// Method Filter.
		echo '<select name="method_filter">';
		echo '<option value="">— ' . __( 'Todos los métodos', 'convoca-gateway' ) . ' —</option>';
		foreach ( array(
			'tarjeta'       => 'Tarjeta',
			'bizum'         => 'Bizum',
			'transferencia' => 'Transferencia',
		) as $key => $label ) {
			$sel = selected( $method_filter, $key, false );
			echo "<option value='" . esc_attr( $key ) . "' $sel>" . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		// Origin Filter.
		echo '<select name="origin_filter">';
		echo '<option value="">— ' . __( 'Todos los orígenes', 'convoca-gateway' ) . ' —</option>';
		foreach ( array(
			'enroll'  => 'Actividades',
			'members' => 'Socio/a',
		) as $key => $label ) {
			$sel = selected( $origin_filter, $key, false );
			echo "<option value='" . esc_attr( $key ) . "' $sel>" . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		submit_button( __( 'Filtrar', 'convoca-gateway' ), '', 'filter_action', false );

		// Export CSV button.
		echo ' <a href="' . esc_url( wp_nonce_url( add_query_arg( 'action', 'export_csv' ), 'convoca_gateway_export_csv' ) ) . '" class="button button-secondary">' . __( 'Exportar CSV', 'convoca-gateway' ) . '</a>';

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
			$args['meta_key'] = '_conv_amount_cents';
		} else {
			$args['orderby'] = 'date';
		}

		// Search by order_id.
		if ( ! empty( $search ) ) {
			$args['meta_query'][] = array(
				'key'     => '_conv_order_id',
				'value'   => $search,
				'compare' => 'LIKE',
			);
		}

		// Filters.
		foreach ( array( 'status', 'method', 'origin' ) as $key ) {
			if ( ! empty( $get_data[ $key . '_filter' ] ) ) {
				$args['meta_query'][] = array(
					'key'   => '_conv_' . $key,
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
			<h1 class="wp-heading-inline"><?php _e( 'Pagos Convoca', 'convoca-gateway' ); ?></h1>
			<a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=conv-gateway-payments&action=export_csv' ), 'convoca_gateway_export_csv' ); ?>" class="page-title-action"><?php _e( 'Exportar a CSV', 'convoca-gateway' ); ?></a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=conv_gateway_export_payments_pdf' ), 'convoca_gateway_export_payments_pdf' ) ); ?>" class="page-title-action"><?php _e( 'Exportar PDF', 'convoca-gateway' ); ?></a>
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
		$id = (int) ( $_GET['id'] ?? 0 );
		if ( ! $id || 'pago' !== get_post_type( $id ) ) {
			wp_die( __( 'Pago no encontrado.', 'convoca-gateway' ) );
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
			echo '<div class="updated"><p>' . __( 'Email reenviado a la cola.', 'convoca-gateway' ) . '</p></div>';
		}

		// Handle refund.
		if ( isset( $_POST['convoca_gateway_refund_payment'] ) && check_admin_referer( 'convoca_gateway_refund_' . $id ) ) {
			$this->handle_refund( $id );
			echo '<div class="updated"><p>' . __( 'Pago marcado como reembolsado.', 'convoca-gateway' ) . '</p></div>';
			$meta = CPT_Pago::get_meta( $id ); // Refresh meta.
		}

		// Handle manual mark as paid.
		if ( isset( $_POST['convoca_gateway_mark_paid'] ) && check_admin_referer( 'convoca_gateway_mark_paid_' . $id ) ) {
			$this->handle_manual_paid( $id );
			echo '<div class="updated"><p>' . __( 'Pago marcado como PAGADO manualmente.', 'convoca-gateway' ) . '</p></div>';
			$meta = CPT_Pago::get_meta( $id ); // Refresh meta.
		}

		?>
		<div class="wrap">
			<h1><?php printf( esc_html__( 'Pago #%s', 'convoca-gateway' ), esc_html( $meta['order_id'] ) ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=conv-gateway-payments' ) ); ?>" class="button">&lsaquo; <?php echo esc_html__( 'Volver al listado', 'convoca-gateway' ); ?></a>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						
						<div class="postbox">
							<h2 class="hndle"><span><?php _e( 'Información del Pago', 'convoca-gateway' ); ?></span></h2>
							<div class="inside">
								<table class="form-table">
									<tr>
										<th><?php _e( 'ID Pedido', 'convoca-gateway' ); ?></th>
										<td><code><?php echo esc_html( $meta['order_id'] ); ?></code></td>
									</tr>
									<tr>
										<th><?php _e( 'Estado', 'convoca-gateway' ); ?></th>
										<td><?php echo CPT_Pago::badge( $meta['status'] ); ?></td>
									</tr>
									<tr>
										<th><?php _e( 'Importe', 'convoca-gateway' ); ?></th>
										<td><strong><?php echo CPT_Pago::format_amount( $meta['amount_cents'] ); ?></strong></td>
									</tr>
									<tr>
										<th><?php _e( 'Método', 'convoca-gateway' ); ?></th>
										<td><?php echo ucfirst( $meta['method'] ); ?></td>
									</tr>
									<tr>
										<th><?php _e( 'Origen', 'convoca-gateway' ); ?></th>
										<td>
											<?php echo esc_html( $meta['origin'] ); ?> 
											(#<?php echo (int) $meta['origin_id']; ?>)
										</td>
									</tr>
									<tr>
										<th><?php _e( 'Descripción producto', 'convoca-gateway' ); ?></th>
										<td><?php echo esc_html( $meta['product_desc'] ); ?></td>
									</tr>
									<tr>
										<th><?php _e( 'Código Autorización', 'convoca-gateway' ); ?></th>
										<td><?php echo esc_html( $meta['redsys_auth_code'] ?: '—' ); ?></td>
									</tr>
									<tr>
										<th><?php _e( 'Respuesta Redsys', 'convoca-gateway' ); ?></th>
										<td><?php echo esc_html( $meta['redsys_response'] ?: '—' ); ?></td>
									</tr>
									<?php if ( ! empty( $meta['proof_file'] ) ) : ?>
									<tr>
										<th><?php _e( 'Justificante de pago', 'convoca-gateway' ); ?></th>
										<td>
											<a href="<?php echo esc_url( $meta['proof_file'] ); ?>" class="button" target="_blank">
												<span class="dashicons dashicons-media-document" style="vertical-align: middle;"></span>
												<?php _e( 'Ver justificante', 'convoca-gateway' ); ?>
											</a>
										</td>
									</tr>
									<?php endif; ?>
								</table>
							</div>
						</div>

						<div class="postbox">
							<h2 class="hndle"><span><?php _e( 'Registro de eventos (Logs)', 'convoca-gateway' ); ?></span></h2>
							<div class="inside">
								<table class="widefat striped">
									<thead>
										<tr>
											<th><?php _e( 'Fecha', 'convoca-gateway' ); ?></th>
											<th><?php _e( 'Nivel', 'convoca-gateway' ); ?></th>
											<th><?php _e( 'Mensaje', 'convoca-gateway' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php if ( empty( $logs ) ) : ?>
											<tr><td colspan="3"><?php _e( 'No hay eventos registrados.', 'convoca-gateway' ); ?></td></tr>
										<?php else : ?>
											<?php foreach ( array_reverse( $logs ) as $log ) : ?>
												<tr>
													<td><?php echo esc_html( $log['timestamp'] ?? '—' ); ?></td>
													<td><?php echo CPT_Pago::badge( $log['level'] ); ?></td>
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
							<h2 class="hndle"><span><?php _e( 'Acciones', 'convoca-gateway' ); ?></span></h2>
							<div class="inside">
								<form method="post">
									<?php wp_nonce_field( 'convoca_gateway_resend_' . $id ); ?>
									<button type="submit" name="conv_gateway_resend_email" class="button button-large" <?php echo $meta['status'] !== 'paid' ? 'disabled' : ''; ?>>
										<?php _e( 'Reenviar email de confirmación', 'convoca-gateway' ); ?>
									</button>
									<p class="description"><?php _e( 'Solo disponible para pagos completados.', 'convoca-gateway' ); ?></p>
								</form>
								<?php if ( $meta['status'] !== 'paid' ) : ?>
									<hr>
									<form method="post" onsubmit="return confirm('¿Confirmas que has recibido el dinero de este pago?');">
										<?php wp_nonce_field( 'convoca_gateway_mark_paid_' . $id ); ?>
										<button type="submit" name="conv_gateway_mark_paid" class="button button-primary full-width"><?php _e( 'Confirmar Pago Manual', 'convoca-gateway' ); ?></button>
										<p class="description"><?php _e( 'Úsalo para confirmar transferencias recibidas.', 'convoca-gateway' ); ?></p>
									</form>
								<?php endif; ?>
								<?php if ( $meta['status'] === 'paid' ) : ?>
									<hr>
									<form method="post" onsubmit="return confirm('¿Estás seguro de marcar este pago como reembolsado?');">
										<?php wp_nonce_field( 'convoca_gateway_refund_' . $id ); ?>
										<button type="submit" name="conv_gateway_refund_payment" class="button button-link-delete" style="color: #d63638;"><?php _e( 'Marcar como Reembolsado', 'convoca-gateway' ); ?></button>
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
			'meta_key'    => '_conv_created_at',
			'order'       => $order,
		);

		// Search by order_id.
		if ( ! empty( $search ) ) {
			$args['meta_query'][] = array(
				'key'     => '_conv_order_id',
				'value'   => $search,
				'compare' => 'LIKE',
			);
		}

		// Filters from GET.
		foreach ( array( 'status', 'method', 'origin' ) as $key ) {
			if ( ! empty( $get_data[ $key . '_filter' ] ) ) {
				$args['meta_query'][] = array(
					'key'   => '_conv_' . $key,
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
		update_post_meta( $id, '_conv_status', 'refunded' );

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
		update_post_meta( $id, '_conv_status', 'paid' );
		update_post_meta( $id, '_conv_paid_at', current_time( 'mysql' ) );

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
