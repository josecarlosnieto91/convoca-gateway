<?php
/**
 * Convoca Gateway
 *
 * @package    Convoca\Gateway
 * @subpackage Includes
 *
 * @copyright  Copyright (C) 2026 Jose Carlos Nieto Ramos
 * @license    GPL-2.0-or-later
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export payments history as a PDF document (Dompdf).
 *
 * @package Convoca\Gateway
 */
class PDF_Exporter {

	/**
	 * Export payments to PDF.
	 *
	 * @param array $args Filter arguments for WP_Query.
	 */
	public static function export( array $args ): void {
		if ( ! current_user_can( 'convoca_view_payments' ) ) {
			wp_die(
				esc_html__( 'No tienes permisos suficientes para exportar el historial de pagos.', 'convoca-gateway' ),
				esc_html__( 'Acceso Denegado', 'convoca-gateway' ),
				array( 'back_link' => true )
			);
		}

		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die(
				esc_html__( 'La librería Dompdf no está disponible. Contacta con el administrador.', 'convoca-gateway' ),
				esc_html__( 'Error de exportación', 'convoca-gateway' ),
				array( 'back_link' => true )
			);
		}

		// Limit export to prevent memory exhaustion.
		$args['posts_per_page'] = 5000;
		$args['paged']          = 1;
		$args['no_found_rows']  = true;

		$query    = new \WP_Query( $args );
		$payments = $query->posts;

		$rows_html = '';
		$total     = 0;
		foreach ( $payments as $post ) {
			$meta   = CPT_Pago::get_meta( $post->ID );
			$total += (int) $meta['amount_cents'];

			$status_label = CPT_Pago::status()[ $meta['status'] ] ?? $meta['status'];
			$email        = CSV_Exporter::get_user_email( $meta['origin'], (int) $meta['origin_id'] );

			$rows_html .= '<tr>'
				. '<td>' . esc_html( (string) $post->ID ) . '</td>'
				. '<td>' . esc_html( $meta['order_id'] ) . '</td>'
				. '<td class="num">' . esc_html( number_format( (int) $meta['amount_cents'] / 100, 2, ',', '.' ) ) . ' €</td>'
				. '<td>' . esc_html( ucfirst( $meta['method'] ) ) . '</td>'
				. '<td>' . esc_html( $status_label ) . '</td>'
				. '<td>' . esc_html( CSV_Exporter::format_origin( $meta['origin'] ) ) . '</td>'
				. '<td>' . esc_html( $email ) . '</td>'
				. '<td>' . esc_html( $meta['created_at'] ) . '</td>'
				. '<td>' . esc_html( $meta['paid_at'] ?: '—' ) . '</td>'
				. '</tr>';
		}

		$site_name = get_bloginfo( 'name' );
		$date      = wp_date( 'd/m/Y H:i' );
		$count     = count( $payments );
		$total_e   = number_format( $total / 100, 2, ',', '.' );

		$html = '<html><head><meta charset="utf-8"><style>'
			. 'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1d2327;}'
			. 'h1{font-size:18px;margin:0 0 4px;}'
			. 'p.sub{color:#646970;margin:0 0 16px;}'
			. 'table{width:100%;border-collapse:collapse;}'
			. 'th{background:#f0f0f1;text-align:left;padding:5px 6px;border:1px solid #c3c4c7;font-size:9px;text-transform:uppercase;}'
			. 'td{padding:5px 6px;border:1px solid #dcdcde;}'
			. 'tr:nth-child(even) td{background:#f6f7f7;}'
			. 'td.num{text-align:right;white-space:nowrap;}'
			. '.foot{margin-top:14px;font-size:10px;color:#646970;}'
			. '</style></head><body>'
			. '<h1>' . esc_html(
				/* translators: %s: site name. */
				sprintf( __( 'Pagos — %s', 'convoca-gateway' ), $site_name )
			) . '</h1>'
			. '<p class="sub">' . esc_html(
				/* translators: 1: generation date, 2: number of payments, 3: total amount. */
				sprintf( __( 'Generado el %1$s · %2$d pagos · Total: %3$s €', 'convoca-gateway' ), $date, $count, $total_e )
			) . '</p>'
			. '<table><thead><tr>'
			. '<th>' . esc_html__( 'ID', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'ID Pedido', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'Importe', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'Método', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'Estado', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'Origen', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'Email', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'Creado', 'convoca-gateway' ) . '</th>'
			. '<th>' . esc_html__( 'Pagado', 'convoca-gateway' ) . '</th>'
			. '</tr></thead><tbody>'
			. ( $rows_html ?: '<tr><td colspan="9">' . esc_html__( 'No hay pagos registrados.', 'convoca-gateway' ) . '</td></tr>' )
			. '</tbody></table>'
			. '<p class="foot">' . esc_html(
				/* translators: 1: site name, 2: generation date. */
				sprintf( __( 'Generado automáticamente por %1$s — %2$s', 'convoca-gateway' ), $site_name, $date )
			) . '</p>'
			. '</body></html>';

		$dompdf = new \Dompdf\Dompdf();
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->loadHtml( $html );
		$dompdf->render();
		$pdf = $dompdf->output();

		$filename = 'pagos-convoca-' . current_time( 'Y-m-d-His' ) . '.pdf';

		if ( ob_get_length() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF content, escaping would corrupt the file.
		echo $pdf;
		exit;
	}
}
