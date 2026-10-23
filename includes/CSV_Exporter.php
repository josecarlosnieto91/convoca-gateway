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
		if ( ! current_user_can( 'convoca_view_payments' ) ) {
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

		$filename = 'pagos-convoca-' . current_time( 'Y-m-d-His' ) . '.csv';

		// La ceremonia —cabeceras, BOM, separador y la protección contra fórmulas— vive en un solo sitio
		// del núcleo (issue convoca-core#16). Aquí estaba copiada a mano y **más corta** que el ayudante
		// del ecosistema: cubría `= + - @` pero no el tabulador, el retorno de carro ni el `;`, que sí
		// están en `Utils::escape_csv_field()`. Un solo sitio donde se decide qué es peligroso.
		\Convoca\Core\Utils::stream_csv(
			$filename,
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
			self::filas( $payments )
		);

		exit;
	}

	/**
	 * Las filas del CSV, de una en una y ya formateadas.
	 *
	 * Estaba dentro de `export()`, que termina en `exit`: así no había manera de comprobar qué se
	 * escribe en cada columna sin cortar el proceso. 5.000 pagos construidos de golpe serían 5.000
	 * arrays vivos, de ahí el generador.
	 *
	 * @param array<int, object> $payments Pagos ya consultados (WP_Post).
	 * @return iterable<int, array<int, mixed>>
	 */
	public static function filas( array $payments ): iterable {
		foreach ( $payments as $post ) {
			$meta = CPT_Pago::get_meta( $post->ID );

			yield array(
				$post->ID,
				$meta['order_id'],
				number_format( $meta['amount_cents'] / 100, 2, ',', '.' ),
				ucfirst( $meta['method'] ),
				CPT_Pago::status()[ $meta['status'] ] ?? $meta['status'],
				self::format_origin( $meta['origin'] ),
				self::get_user_email( (string) $meta['origin'], (int) $meta['origin_id'] ),
				$meta['created_at'],
				$meta['paid_at'] ?: '—',
			);
		}
	}

	/**
	 * Get user email based on origin.
	 */
	public static function get_user_email( string $origin, int $origin_id ): string {
		if ( ! $origin_id ) {
			return '—';
		}

		switch ( $origin ) {
			case 'enroll':
				return (string) get_post_meta( $origin_id, '_convoca_email', true );
			case 'members':
				return (string) get_post_meta( $origin_id, '_convoca_email', true );
			default:
				return '—';
		}
	}

	/**
	 * Format origin label.
	 */
	public static function format_origin( string $origin ): string {
		return match ( $origin ) {
			'enroll' => __( 'Inscripción', 'convoca-gateway' ),
			'members' => __( 'Socio/a', 'convoca-gateway' ),
			default => $origin,
		};
	}
}
