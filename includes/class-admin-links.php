<?php
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
				'screen'   => 'bdg-links',
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'order_id' => __( 'Pedido', 'convoca-gateway' ),
			'concepto' => __( 'Concepto', 'convoca-gateway' ),
			'amount'   => __( 'Importe', 'convoca-gateway' ),
			'method'   => __( 'Método', 'convoca-gateway' ),
			'origin'   => __( 'Origen', 'convoca-gateway' ),
			'email'    => __( 'Email Destinatario', 'convoca-gateway' ),
			'status'   => __( 'Estado Pago', 'convoca-gateway' ),
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
		$orderby      = $_GET['orderby'] ?? 'date';
		$order        = $_GET['order'] ?? 'desc';

		$args = array(
			'post_type'      => 'pago',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'orderby'        => 'meta_value',
			'meta_key'       => '_conv_created_at',
			'order'          => $order,
			'meta_query'     => array(
				array(
					'key'   => '_conv_origin',
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

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="enlace[]" value="%s" />', $item->ID );
	}

	public function column_order_id( $item ): string {
		$meta     = CPT_Pago::get_meta( $item->ID );
		$url      = admin_url( 'admin.php?page=bdg-payments-detail&id=' . $item->ID );
		$order_id = $meta['order_id'] ?? '—';
		return '<a href="' . esc_url( $url ) . '"><strong>' . esc_html( $order_id ) . '</strong></a>';
	}

	public function column_concepto( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		$url  = admin_url( 'admin.php?page=bdg-payments-detail&id=' . $item->ID );

		// Build the actual link for quick copy.
		// expires_at puede ser string vacío cuando el meta no existe (PHP 8.1+ TypeError si pasamos string a ?int).
		$expires_ts = ! empty( $meta['expires_at'] ) ? (int) $meta['expires_at'] : null;
		$link_url   = Payment_Handler::get_payment_link( $item->ID, $meta['link_key'], $expires_ts );

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Ver detalles', 'convoca-gateway' ) ),
			'copy' => sprintf( '<a href="#" class="bdg-copy-link" data-link="%s">%s</a>', esc_attr( $link_url ), esc_html__( 'Copiar enlace', 'convoca-gateway' ) ),
		);

		return sprintf( '<strong>%s</strong> %s', esc_html( $meta['product_desc'] ), $this->row_actions( $actions ) );
	}

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

	public function column_origin( $item ): string {
		return '<span class="convoca-badge convoca-badge--info">' . __( 'Enlace de pago', 'convoca-gateway' ) . '</span>';
	}

	public function column_amount( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return CPT_Pago::format_amount( $meta['amount_cents'] );
	}

	public function column_email( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return esc_html( $meta['recipient_email'] ?: '—' );
	}

	public function column_status( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return CPT_Pago::badge( $meta['status'] );
	}

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
		$used    = ( $meta['status'] === 'paid' );

		if ( $used ) {
			return '<span class="convoca-badge convoca-badge--info">' . __( 'Usado', 'convoca-gateway' ) . '</span>';
		}

		if ( $expired ) {
			return '<span class="convoca-badge convoca-badge--danger">' . __( 'Caducado', 'convoca-gateway' ) . '</span>';
		}

		return '<span class="convoca-badge convoca-badge--success">' . __( 'Activo', 'convoca-gateway' ) . '</span>';
	}

	public function column_created( $item ): string {
		$meta = CPT_Pago::get_meta( $item->ID );
		return esc_html( $meta['created_at'] );
	}

	public function render_page(): void {
		$cols = array_keys( $this->get_columns() );
		$this->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html__( 'Enlaces de Pago Generados', 'convoca-gateway' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bdg-generador' ) ); ?>" class="page-title-action"><?php echo esc_html__( 'Generar nuevo', 'convoca-gateway' ); ?></a>
			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="page" value="bdg-links">
				<?php $this->display(); ?>
			</form>
		</div>
		<script>
		document.addEventListener('click', function(e) {
			if (e.target.classList.contains('bdg-copy-link')) {
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
}
