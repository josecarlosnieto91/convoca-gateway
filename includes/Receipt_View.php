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
 * Receipt / donation justification as a standalone printable document (D23/D24).
 *
 * Antes el recibo se pintaba dentro de la página del tema: salía con su cabecera,
 * su menú y su pie, y al imprimir quedaba un tercio de página en blanco porque el
 * hueco de la cabecera se reservaba igual. Ahora es un documento propio, sin
 * depender del tema, con la entidad, sus datos fiscales, el pagador, el método, el
 * estado y el texto legal del donativo.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Receipt_View {

	/**
	 * Sirve el recibo si la petición lo pide, y termina.
	 *
	 * Va en `template_redirect` a prioridad 0 para salir antes de que el tema
	 * pinte nada. Si no viene el parámetro, no hace absolutamente nada.
	 */
	public static function maybe_render(): void {
		if ( ! isset( $_GET['convoca_gateway_recibo'] ) ) {
			return;
		}

		$pago_id = (int) ( $_GET['convoca_gateway_pago'] ?? 0 );
		$key     = sanitize_text_field( wp_unslash( $_GET['convoca_gateway_receipt_key'] ?? '' ) );

		$datos = self::data( $pago_id, $key );

		nocache_headers();

		if ( isset( $datos['error'] ) ) {
			status_header( 404 );
		} else {
			status_header( 200 );
		}

		header( 'Content-Type: text/html; charset=UTF-8' );

		// Documento propio: no pasa por el tema.
		echo self::html( $datos ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado dentro de html().

		exit;
	}

	/**
	 * Todos los datos que salen en el recibo. Sin imprimir nada.
	 *
	 * @param int    $pago_id Pago.
	 * @param string $key     Clave del recibo (la guardada en el pago).
	 * @return array{error?:string} Datos o error.
	 */
	public static function data( int $pago_id, string $key ): array {
		if ( ! $pago_id || '' === $key ) {
			return array( 'error' => __( 'Recibo no encontrado.', 'convoca-gateway' ) );
		}

		$post = get_post( $pago_id );
		if ( ! $post || 'pago' !== $post->post_type ) {
			return array( 'error' => __( 'Recibo no encontrado.', 'convoca-gateway' ) );
		}

		$stored_key = (string) get_post_meta( $pago_id, '_convoca_receipt_key', true );
		if ( '' === $stored_key || ! hash_equals( $stored_key, $key ) ) {
			return array( 'error' => __( 'Enlace de recibo no válido.', 'convoca-gateway' ) );
		}

		if ( 'paid' !== (string) get_post_meta( $pago_id, '_convoca_status', true ) ) {
			return array( 'error' => __( 'El recibo estará disponible una vez confirmado el pago.', 'convoca-gateway' ) );
		}

		$meta        = CPT_Pago::get_meta( $pago_id );
		$es_donativo = Receipt_Generator::is_donation( $pago_id );
		$org         = Receipt_Generator::org_data();
		$paid_at_ts  = ! empty( $meta['paid_at'] ) ? strtotime( (string) $meta['paid_at'] ) : 0;

		$pagador = (string) get_post_meta( $pago_id, '_convoca_payer_email', true );
		if ( '' === $pagador ) {
			$pagador = (string) get_post_meta( $pago_id, '_convoca_recipient_email', true );
		}

		/**
		 * Permite añadir el nombre de quien paga (members/enroll lo tienen en su ficha).
		 *
		 * @param string $nombre  Nombre a mostrar (vacío por defecto).
		 * @param int    $pago_id Pago.
		 */
		$nombre_pagador = (string) apply_filters( 'convoca_gateway_receipt_payer_name', '', $pago_id );

		return array(
			'title'       => $es_donativo
				? __( 'Justificante de donación', 'convoca-gateway' )
				: __( 'Recibo de pago', 'convoca-gateway' ),
			'number'      => Receipt_Generator::assign_number( $pago_id ),
			'org'         => $org,
			'logo'        => self::logo_url(),
			'site'        => home_url( '/' ),
			'site_name'   => get_bloginfo( 'name' ),
			'concept'     => (string) $meta['product_desc'],
			'amount'      => CPT_Pago::format_amount( (int) $meta['amount_cents'] ),
			'method'      => CPT_Pago::method_label( (string) $meta['method'] ),
			'reference'   => (string) $meta['order_id'],
			'status'      => __( 'Pagado', 'convoca-gateway' ),
			'paid_at'     => $paid_at_ts ? wp_date( 'd/m/Y H:i', $paid_at_ts ) : '',
			'payer_name'  => $nombre_pagador,
			'payer_email' => $pagador,
			'legal'       => $es_donativo ? Receipt_Generator::donation_legal_text() : '',
			'issued_at'   => wp_date( 'd/m/Y H:i' ),
		);
	}

	/**
	 * URL del logo del sitio, si lo hay.
	 *
	 * @return string Cadena vacía si el sitio no tiene logo.
	 */
	public static function logo_url(): string {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $logo_id, 'medium' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * El documento completo, listo para imprimir. Sin imprimir nada.
	 *
	 * @param array $datos Salida de data().
	 * @return string HTML.
	 */
	public static function html( array $datos ): string {
		$naranja = '#ff8700';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( ( $datos['title'] ?? '' ) . ' ' . ( $datos['number'] ?? '' ) ); ?></title>
	<style>
		@page { size: A4; margin: 18mm; }
		* { box-sizing: border-box; }
		body { margin: 0; background: #f1f5f9; color: #1f2937; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5; }
		.hoja { background: #fff; max-width: 210mm; margin: 24px auto; padding: 18mm; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
		.cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; border-bottom: 2px solid #e5e7eb; padding-bottom: 16px; }
		.emisor { display: flex; gap: 14px; align-items: flex-start; }
		.emisor img { max-height: 56px; width: auto; }
		.emisor__nombre { font-size: 17px; font-weight: 700; }
		.emisor__dato { color: #4b5563; font-size: 13px; }
		.titulo { text-align: right; }
		.titulo h1 { margin: 0; font-size: 22px; color: <?php echo esc_attr( $naranja ); ?>; }
		.titulo__numero { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 15px; font-weight: 700; color: #111827; }
		.tabla { width: 100%; border-collapse: collapse; margin: 28px 0 0; table-layout: fixed; }
		.tabla th, .tabla td { text-align: left; padding: 11px 12px; border-bottom: 1px solid #eef2f7; vertical-align: top; overflow-wrap: break-word; }
		.tabla th { width: 32%; color: #6b7280; font-weight: 600; }
		.tabla tr:last-child th, .tabla tr:last-child td { border-bottom: none; }
		.total { margin-top: 8px; padding: 16px 12px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; display: flex; justify-content: space-between; align-items: baseline; }
		.total__etiqueta { color: #4b5563; font-weight: 600; }
		.total__importe { font-size: 24px; font-weight: 700; }
		.estado { display: inline-block; padding: 3px 10px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 13px; font-weight: 600; }
		.legal { margin-top: 26px; padding: 12px 14px; background: #f8fafc; border-left: 3px solid <?php echo esc_attr( $naranja ); ?>; color: #4b5563; font-size: 13px; }
		.pie { margin-top: 34px; padding-top: 12px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 12px; display: flex; justify-content: space-between; gap: 16px; }
		.acciones { max-width: 210mm; margin: 0 auto 32px; text-align: right; }
		.acciones button { font: inherit; font-weight: 600; color: #fff; background: <?php echo esc_attr( $naranja ); ?>; border: 0; border-radius: 8px; padding: 12px 22px; cursor: pointer; }
		.error { max-width: 640px; margin: 48px auto; padding: 24px; background: #fff; border-radius: 10px; color: #991b1b; }
		@media print {
			body { background: #fff; }
			.hoja { margin: 0; padding: 0; box-shadow: none; max-width: none; }
			.acciones { display: none; }
			.estado { border: 1px solid #86efac; }
		}
	</style>
</head>
<body>
		<?php if ( isset( $datos['error'] ) ) : ?>
	<div class="error"><?php echo esc_html( (string) $datos['error'] ); ?></div>
		<?php else : ?>
	<div class="acciones">
		<button type="button" onclick="window.print()"><?php esc_html_e( 'Imprimir / Guardar PDF', 'convoca-gateway' ); ?></button>
	</div>
	<div class="hoja">
		<div class="cabecera">
			<div class="emisor">
				<?php if ( '' !== (string) $datos['logo'] ) : ?>
					<img src="<?php echo esc_url( (string) $datos['logo'] ); ?>" alt="">
				<?php endif; ?>
				<div>
					<div class="emisor__nombre"><?php echo esc_html( (string) $datos['org']['name'] ); ?></div>
					<?php if ( '' !== (string) $datos['org']['cif'] ) : ?>
						<div class="emisor__dato"><?php esc_html_e( 'CIF/NIF', 'convoca-gateway' ); ?>: <?php echo esc_html( (string) $datos['org']['cif'] ); ?></div>
					<?php endif; ?>
					<?php if ( '' !== (string) $datos['org']['address'] ) : ?>
						<div class="emisor__dato"><?php echo esc_html( (string) $datos['org']['address'] ); ?></div>
					<?php endif; ?>
				</div>
			</div>
			<div class="titulo">
				<h1><?php echo esc_html( (string) $datos['title'] ); ?></h1>
				<div class="titulo__numero"><?php echo esc_html( (string) $datos['number'] ); ?></div>
			</div>
		</div>

		<table class="tabla">
			<tr>
				<th><?php esc_html_e( 'Concepto', 'convoca-gateway' ); ?></th>
				<td><?php echo esc_html( (string) $datos['concept'] ); ?></td>
			</tr>
			<?php if ( '' !== (string) $datos['payer_name'] || '' !== (string) $datos['payer_email'] ) : ?>
				<tr>
					<th><?php esc_html_e( 'Pagador', 'convoca-gateway' ); ?></th>
					<td>
						<?php if ( '' !== (string) $datos['payer_name'] ) : ?>
							<?php echo esc_html( (string) $datos['payer_name'] ); ?>
						<?php endif; ?>
						<?php if ( '' !== (string) $datos['payer_email'] ) : ?>
							<?php
							if ( '' !== (string) $datos['payer_name'] ) :
								?>
								<br><?php endif; ?>
							<span class="emisor__dato"><?php echo esc_html( (string) $datos['payer_email'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><?php esc_html_e( 'Método de pago', 'convoca-gateway' ); ?></th>
				<td><?php echo esc_html( (string) $datos['method'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Referencia del pago', 'convoca-gateway' ); ?></th>
				<td><?php echo esc_html( (string) $datos['reference'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Fecha de pago', 'convoca-gateway' ); ?></th>
				<td>
					<?php if ( '' !== (string) $datos['paid_at'] ) : ?>
						<?php echo esc_html( (string) $datos['paid_at'] ); ?>
					<?php endif; ?>
					<span class="estado"><?php echo esc_html( (string) $datos['status'] ); ?></span>
				</td>
			</tr>
		</table>

		<div class="total">
			<span class="total__etiqueta"><?php esc_html_e( 'Importe total', 'convoca-gateway' ); ?></span>
			<span class="total__importe"><?php echo esc_html( (string) $datos['amount'] ); ?></span>
		</div>

			<?php if ( '' !== (string) $datos['legal'] ) : ?>
			<p class="legal"><?php echo esc_html( (string) $datos['legal'] ); ?></p>
		<?php endif; ?>

		<div class="pie">
			<span><?php echo esc_html( (string) $datos['site_name'] ); ?> · <?php echo esc_html( (string) $datos['site'] ); ?></span>
			<span><?php esc_html_e( 'Emitido el', 'convoca-gateway' ); ?> <?php echo esc_html( (string) $datos['issued_at'] ); ?></span>
		</div>
	</div>
		<?php endif; ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
