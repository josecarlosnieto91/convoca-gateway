<?php
/**
 * CSV Exporter for Payments.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CSV_Exporter {


	/**
	 * Export payments to CSV.
	 *
	 * @param array $args Filter arguments for WP_Query.
	 */
	public static function export( array $args ): void {
		if ( ! current_user_can( 'bdg_view_payments' ) ) {
			wp_die(
				esc_html__( 'No tienes permisos suficientes para exportar el historial de pagos.', 'convoca-gateway' ),
				esc_html__( 'Acceso Denegado', 'convoca-gateway' ),
				array( 'back_link' => true )
			);
		}

		// Limit export to prevent memory exhaustion.
		$args['posts_per_page'] = 5000;
		$args['paged']          = 1;
		$args['no_found_rows']  = true;

		$query    = new \WP_Query( $args );
		$payments = $query->posts;

		$filename = 'pagos-biodevas-' . current_time( 'Y-m-d-His' ) . '.csv';

		if ( ob_get_length() ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' ); .

		// Add BOM for Excel UTF-8 compatibility.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Headers.
		fputcsv(
			$output,
			array(
				__( 'ID', 'convoca-gateway' ),
				__( 'ID Pedido', 'convoca-gateway' ),
				__( 'Importe (€)', 'convoca-gateway' ),
				__( 'Método', 'convoca-gateway' ),
				__( 'Estado', 'convoca-gateway' ),
				__( 'Origen', 'convoca-gateway' ),
				__( 'Email Usuario', 'convoca-gateway' ),
				__( 'Fecha Creación', 'convoca-gateway' ),
				__( 'Fecha Pago', 'convoca-gateway' ),
			),
			';'
		);

		foreach ( $payments as $post ) {
			$meta = CPT_Pago::get_meta( $post->ID );

			$email = self::get_user_email( $meta['origin'], $meta['origin_id'] );

			$row = array(
				$post->ID,
				$meta['order_id'],
				number_format( $meta['amount_cents'] / 100, 2, ',', '.' ),
				ucfirst( $meta['method'] ),
				CPT_Pago::STATUS[ $meta['status'] ] ?? $meta['status'],
				self::format_origin( $meta['origin'] ),
				$email,
				$meta['created_at'],
				$meta['paid_at'] ?: '—',
			);

			// Protect against CSV Injection.
			foreach ( $row as &$field ) {
				if ( is_string( $field ) && ! empty( $field ) && in_array( $field[0], array( '=', '+', '-', '@' ), true ) ) {
					$field = "'" . $field;
				}
			}

			fputcsv( $output, $row, ';', '"' );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Get user email based on origin.
	 */
	private static function get_user_email( string $origin, int $origin_id ): string {
		if ( ! $origin_id ) {
			return '—';
		}

		switch ( $origin ) {
			case 'enroll':
				return (string) get_post_meta( $origin_id, '_bde_email', true );
			case 'members':
				return (string) get_post_meta( $origin_id, '_bdv_email', true );
			default:
				return '—';
		}
	}

	/**
	 * Format origin label.
	 */
	private static function format_origin( string $origin ): string {
		return match ( $origin ) {
			'enroll' => __( 'Inscripción', 'convoca-gateway' ),
			'members' => __( 'Socio/a', 'convoca-gateway' ),
			default => $origin,
		};
	}
}
