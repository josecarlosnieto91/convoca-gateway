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
 * Pagos empezados que no han terminado: la regla y el panel que los enseña.
 *
 * Un pago puede quedarse a medias para siempre (el cliente no vuelve del TPV, o
 * el aviso de Redsys no llega) y hasta ahora nadie se enteraba: se descubrió por
 * casualidad. Esto lo pone delante de quien puede arreglarlo.
 *
 * La regla vive aquí, en una sola función pura, y la usa también el recordatorio
 * por correo: si un pago cuenta como colgado para el panel, cuenta igual para el aviso.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pending_Payments {

	/** Minutos que se espera antes de dar un pago por colgado. */
	public const MIN_AGE = 1800;

	/** Cuántos pagos enseña el panel. */
	private const WIDGET_LIMIT = 8;

	/**
	 * ¿Este pago se quedó a medias? Sin consultar nada: solo con su ficha.
	 *
	 * Queda fuera lo que no es un cobro (una plantilla de enlace) y lo que no tiene
	 * importe (un enlace de donativo sin usar). El enlace caducado **no** lo excluye:
	 * justo entonces es cuando hay que mirarlo.
	 *
	 * @param array $meta    Meta del pago.
	 * @param int   $now     Momento actual.
	 * @param int   $min_age Segundos mínimos desde la creación.
	 * @return bool
	 */
	public static function is_stuck( array $meta, int $now, int $min_age = self::MIN_AGE ): bool {
		if ( 'pending' !== (string) ( $meta['_convoca_status'] ?? '' ) ) {
			return false;
		}

		if ( 'link_payment' === (string) ( $meta['_convoca_origin'] ?? '' ) ) {
			return false;
		}

		if ( (int) ( $meta['_convoca_amount_cents'] ?? 0 ) <= 0 ) {
			return false;
		}

		$creado = Email_Notifications::created_ts( $meta );
		if ( 0 === $creado || ( $now - $creado ) < $min_age ) {
			return false;
		}

		return true;
	}

	/**
	 * Los pagos colgados, del más reciente al más antiguo.
	 *
	 * @param int|null $now   Base de tiempo (pruebas).
	 * @param int      $limit Máximo de pagos.
	 * @return int[] IDs.
	 */
	public static function stuck_ids( ?int $now = null, int $limit = 50 ): array {
		$now = $now ?? time();

		$ids = get_posts(
			array(
				'post_type'      => 'pago',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_convoca_status',
						'value' => 'pending',
					),
					array(
						'relation' => 'OR',
						// Los pagos nuevos traen marca de tiempo. Los anteriores al
						// recordatorio solo tienen la fecha local del sitio, así que el
						// corte se compara con esa misma referencia horaria.
						array(
							'key'     => '_convoca_created_ts',
							'value'   => $now - self::MIN_AGE,
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
						array(
							'relation' => 'AND',
							array(
								'key'     => '_convoca_created_ts',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_convoca_created_at',
								'value'   => gmdate( 'Y-m-d H:i:s', $now - self::MIN_AGE + (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) ),
								'compare' => '<=',
								'type'    => 'DATETIME',
							),
						),
					),
				),
			),
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Registra el panel en el escritorio de administración.
	 *
	 * Va en `wp_dashboard_setup`: `wp_add_dashboard_widget()` solo existe ahí.
	 */
	public static function register_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'convoca_gateway_pendientes',
			__( 'Convoca · Pagos sin terminar', 'convoca-gateway' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	/**
	 * El panel: cuántos hay y cuáles, con lo justo para ponerse a ello.
	 */
	public static function render_widget(): void {
		$ids   = self::stuck_ids();
		$total = count( $ids );
		$lista = array_slice( $ids, 0, self::WIDGET_LIMIT );

		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'Ningún pago a medias. Todo lo empezado ha terminado.', 'convoca-gateway' ) . '</p>';
			return;
		}

		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %d: number of unfinished payments. */
					_n( 'Hay %d pago empezado que no ha terminado.', 'Hay %d pagos empezados que no han terminado.', $total, 'convoca-gateway' ),
					$total
				)
			)
		);

		echo '<p>' . esc_html__( 'Un pago sin respuesta del banco no se cobra solo: míralo en el panel de Redsys antes de darlo por bueno o por perdido.', 'convoca-gateway' ) . '</p>';

		echo '<table class="widefat striped"><tbody>';
		foreach ( $lista as $id ) {
			$meta   = CPT_Pago::get_meta( $id );
			$correo = (string) get_post_meta( $id, '_convoca_payer_email', true );
			$fecha  = Email_Notifications::created_ts(
				array(
					'_convoca_created_ts' => get_post_meta( $id, '_convoca_created_ts', true ),
					'_convoca_created_at' => get_post_meta( $id, '_convoca_created_at', true ),
				)
			);

			printf(
				'<tr><td><a href="%s">%s</a><br><span style="color:#666;">%s · %s · %s</span></td><td style="text-align:right;white-space:nowrap;">%s</td></tr>',
				esc_url( (string) get_edit_post_link( $id ) ),
				esc_html( '' !== (string) $meta['product_desc'] ? (string) $meta['product_desc'] : (string) $meta['order_id'] ),
				esc_html( $fecha ? wp_date( 'd/m/Y H:i', $fecha ) : '—' ),
				esc_html( CPT_Pago::method_label( (string) $meta['method'] ) ),
				'' !== $correo
					? esc_html__( 'con correo', 'convoca-gateway' )
					: esc_html__( 'sin correo', 'convoca-gateway' ),
				esc_html( CPT_Pago::format_amount( (int) $meta['amount_cents'] ) )
			);
		}
		echo '</tbody></table>';

		if ( $total > count( $lista ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html(
					sprintf(
						/* translators: %d: number of payments not shown. */
						__( 'y %d más en el listado.', 'convoca-gateway' ),
						$total - count( $lista )
					)
				)
			);
		}

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url( admin_url( 'edit.php?post_type=pago' ) ),
			esc_html__( 'Ver todos los pagos', 'convoca-gateway' )
		);
	}
}
