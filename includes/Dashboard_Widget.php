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
 * Dashboard widget for payment summaries.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Widget {

	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget(): void {
		if ( ! current_user_can( 'convoca_view_payments' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'convoca_gateway_payment_summary',
			__( 'Resumen de Pagos Convoca', 'convoca-gateway' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render the widget content.
	 */
	public function render_widget(): void {
		$data = $this->get_dashboard_data();

		?>
		<div class="conv-gateway-dashboard-widget">
			<div class="conv-gateway-stats-grid">
				<div class="conv-gateway-stat">
					<span class="conv-gateway-stat-label"><?php esc_html_e( 'Total este mes', 'convoca-gateway' ); ?></span>
					<span class="conv-gateway-stat-value"><?php echo esc_html( CPT_Pago::format_amount( $data['total_month'] ) ); ?></span>
				</div>
				<div class="conv-gateway-stat">
					<span class="conv-gateway-stat-label"><?php esc_html_e( 'Pagos hoy', 'convoca-gateway' ); ?></span>
					<span class="conv-gateway-stat-value"><?php echo esc_html( $data['count_today'] ); ?></span>
				</div>
				<div class="conv-gateway-stat">
					<span class="conv-gateway-stat-label"><?php esc_html_e( 'Pagos este mes', 'convoca-gateway' ); ?></span>
					<span class="conv-gateway-stat-value"><?php echo esc_html( $data['count_month'] ); ?></span>
				</div>
			</div>

			<div class="conv-gateway-section">
				<h4><?php esc_html_e( 'Uso de Métodos (Mes)', 'convoca-gateway' ); ?></h4>
				<div class="conv-gateway-methods">
					<div class="conv-gateway-method-bar">
						<div class="conv-gateway-method-fill card" style="width: <?php echo esc_attr( $data['methods']['tarjeta_pct'] ); ?>%;"></div>
						<div class="conv-gateway-method-fill bizum" style="width: <?php echo esc_attr( $data['methods']['bizum_pct'] ); ?>%;"></div>
					</div>
					<div class="conv-gateway-method-labels">
						<span>💳 <?php echo esc_html( $data['methods']['tarjeta_pct'] ); ?>% <?php esc_html_e( 'Tarjeta', 'convoca-gateway' ); ?></span>
						<span>📱 <?php echo esc_html( $data['methods']['bizum_pct'] ); ?>% <?php esc_html_e( 'Bizum', 'convoca-gateway' ); ?></span>
					</div>
				</div>
			</div>

			<div class="conv-gateway-section">
				<h4><?php esc_html_e( 'Evolución (7 días)', 'convoca-gateway' ); ?></h4>
				<div class="conv-gateway-mini-bars">
					<?php
					$max_total = 0;
					foreach ( $data['last_7_days'] as $day ) {
						$max_total = max( $max_total, $day['total'] );
					}
					?>
					<table class="conv-gateway-chart-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Día', 'convoca-gateway' ); ?></th>
								<th><?php esc_html_e( 'Resumen', 'convoca-gateway' ); ?></th>
								<th style="text-align: right;"><?php esc_html_e( 'Total', 'convoca-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['last_7_days'] as $day ) : ?>
								<?php
								$height = $max_total > 0 ? ( $day['total'] / $max_total ) * 100 : 0;
								?>
								<tr>
									<td><?php echo esc_html( $day['extra_label'] ); ?></td>
									<td>
										<?php /* translators: 1: number of payments, 2: formatted total amount */ ?>
										<div class="conv-gateway-bar-container" title="<?php echo esc_attr( sprintf( __( '%1$d pagos, %2$s', 'convoca-gateway' ), $day['count'], CPT_Pago::format_amount( $day['total'] ) ) ); ?>">
											<div class="conv-gateway-bar-fill" style="width: <?php echo esc_attr( $height ); ?>%"></div>
											<span class="conv-gateway-bar-count"><?php echo esc_html( $day['count'] ); ?></span>
										</div>
									</td>
									<td style="text-align: right;"><?php echo esc_html( CPT_Pago::format_amount( $day['total'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<p class="conv-gateway-footer-links">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=pago' ) ); ?>" class="button"><?php esc_html_e( 'Ver todos los pagos', 'convoca-gateway' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=conv-gateway-settings' ) ); ?>" class="button"><?php esc_html_e( 'Configuración', 'convoca-gateway' ); ?></a>
			</p>
		</div>

		<style>
			.conv-gateway-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
			.conv-gateway-stat { background: #f6f7f7; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa; }
			.conv-gateway-stat-label { display: block; font-size: 11px; color: #646970; white-space: nowrap; overflow: hidden; }
			.conv-gateway-stat-value { display: block; font-size: 16px; font-weight: 600; margin-top: 5px; }
			.conv-gateway-section h4 { margin: 15px 0 10px; border-bottom: 1px solid #dcdcde; padding-bottom: 5px; font-size: 13px; }
			.conv-gateway-method-bar { height: 12px; background: #dcdcde; border-radius: 6px; overflow: hidden; display: flex; margin-bottom: 5px; }
			.conv-gateway-method-fill.card { background: #0073aa; }
			.conv-gateway-method-fill.bizum { background: #46b450; }
			.conv-gateway-method-labels { display: flex; justify-content: space-between; font-size: 11px; }
			.conv-gateway-chart-table { width: 100%; border-collapse: collapse; font-size: 11px; }
			.conv-gateway-chart-table th { text-align: left; padding: 5px; background: #f6f7f7; color: #646970; }
			.conv-gateway-chart-table td { padding: 4px 5px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
			.conv-gateway-bar-container { background: #f0f0f1; border-radius: 2px; height: 16px; position: relative; overflow: hidden; display: flex; align-items: center; }
			.conv-gateway-bar-fill { background: #0073aa; height: 100%; transition: width 0.3s ease; }
			.conv-gateway-bar-count { position: absolute; right: 5px; font-size: 9px; color: #646970; font-weight: 600; }
			.conv-gateway-footer-links { margin-top: 20px; border-top: 1px solid #dcdcde; padding-top: 15px; display: flex; gap: 10px; }
			@media (max-width: 400px) { .conv-gateway-stats-grid { grid-template-columns: 1fr; } }
		</style>
		<?php
	}

	/**
	 * Get data for the dashboard widget with caching.
	 */
	private function get_dashboard_data(): array {
		global $wpdb;

		$data = get_transient( 'convoca_gateway_dashboard_stats' );
		if ( false !== $data ) {
			return $data;
		}

		$now         = time();
		$month_start = strtotime( 'first day of this month 00:00:00', $now );
		$today_start = strtotime( 'today 00:00:00', $now );
		$posts       = $wpdb->posts;
		$postmeta    = $wpdb->postmeta;

		// Single aggregation query: all paid payments this month.
		$paid_posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM $posts p
             INNER JOIN $postmeta m ON m.post_id = p.ID AND m.meta_key = '_convoca_status' AND m.meta_value = 'paid'
             WHERE p.post_type = 'pago'
               AND p.post_status = 'publish'
               AND p.post_date >= %s",
				wp_date( 'Y-m-d H:i:s', $month_start )
			)
		);

		if ( empty( $paid_posts ) ) {
			$data = array(
				'total_month' => 0,
				'count_today' => 0,
				'count_month' => 0,
				'methods'     => array(
					'tarjeta_pct' => 0,
					'bizum_pct'   => 0,
				),
				'last_7_days' => array(),
			);
			set_transient( 'convoca_gateway_dashboard_stats', $data, 300 );
			return $data;
		}

		update_meta_cache( 'post', $paid_posts );

		$total_month   = 0;
		$count_today   = 0;
		$methods_count = array(
			'tarjeta' => 0,
			'bizum'   => 0,
		);

		foreach ( $paid_posts as $pid ) {
			$total_month += (int) get_post_meta( $pid, '_convoca_amount_cents', true );
			$method       = get_post_meta( $pid, '_convoca_method', true );
			if ( isset( $methods_count[ $method ] ) ) {
				++$methods_count[ $method ];
			}

			$paid_at = get_post_meta( $pid, '_convoca_paid_at', true );
			if ( $paid_at && strtotime( $paid_at ) >= $today_start ) {
				++$count_today;
			}
		}

		$total_methods = array_sum( $methods_count );
		$methods_pct   = array(
			'tarjeta_pct' => $total_methods > 0 ? round( ( $methods_count['tarjeta'] / $total_methods ) * 100 ) : 0,
			'bizum_pct'   => $total_methods > 0 ? round( ( $methods_count['bizum'] / $total_methods ) * 100 ) : 0,
		);

		// Last 7 days via a single GROUP BY query.
		$week_ago = $today_start - 6 * DAY_IN_SECONDS;
		$day_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(p.post_date) AS day,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(CAST(ma.meta_value AS UNSIGNED)), 0) AS total
             FROM $posts p
             INNER JOIN $postmeta ms ON ms.post_id = p.ID AND ms.meta_key = '_convoca_status' AND ms.meta_value = 'paid'
             INNER JOIN $postmeta ma ON ma.post_id = p.ID AND ma.meta_key = '_convoca_amount_cents'
             WHERE p.post_type = 'pago'
               AND p.post_status = 'publish'
               AND p.post_date >= %s
               AND p.post_date < %s
             GROUP BY DATE(p.post_date)
             ORDER BY day ASC",
				wp_date( 'Y-m-d H:i:s', $week_ago ),
				wp_date( 'Y-m-d H:i:s', $today_start + DAY_IN_SECONDS )
			)
		);

		$day_map = array();
		foreach ( $day_rows as $row ) {
			$day_map[ $row->day ] = array(
				'count' => (int) $row->cnt,
				'total' => (int) $row->total,
			);
		}

		$last_7_days = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$ts            = strtotime( "-{$i} days", $today_start );
			$key           = wp_date( 'Y-m-d', $ts );
			$last_7_days[] = array(
				'label'       => wp_date( 'd M', $ts ),
				'extra_label' => ucfirst( wp_date( 'D d', $ts ) ),
				'count'       => $day_map[ $key ]['count'] ?? 0,
				'total'       => $day_map[ $key ]['total'] ?? 0,
			);
		}

		$data = array(
			'total_month' => $total_month,
			'count_today' => $count_today,
			'count_month' => count( $paid_posts ),
			'methods'     => $methods_pct,
			'last_7_days' => $last_7_days,
		);

		set_transient( 'convoca_gateway_dashboard_stats', $data, HOUR_IN_SECONDS );

		return $data;
	}
}
