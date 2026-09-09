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
 * Email notifications for payments.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Notifications {

	public function __construct() {
		add_action( 'convoca_payment_completed', array( $this, 'send_success_email' ), 10, 4 );
		add_action( 'convoca_payment_failed', array( $this, 'send_failed_email' ), 10, 2 );
	}

	/**
	 * Send success email.
	 */
	public function send_success_email( int $payment_id, string $origin, int $origin_id, array $data ): void {
		$this->maybe_send( $payment_id, 'success' );
	}

	/**
	 * Send failure email.
	 */
	public function send_failed_email( int $payment_id, string $response_code ): void {
		$this->maybe_send( $payment_id, 'failed', array( 'response_code' => $response_code ) );
	}

	/**
	 * Send the payment link to the recipient (D21).
	 *
	 * @param int $pago_id Payment post ID.
	 * @return bool True if an email was sent.
	 */
	public function send_link_email( int $pago_id ): bool {
		$email = get_post_meta( $pago_id, '_convoca_recipient_email', true );
		if ( empty( $email ) ) {
			return false;
		}

		$amount_cents = (int) get_post_meta( $pago_id, '_convoca_amount_cents', true );
		$concepto     = (string) get_post_meta( $pago_id, '_convoca_product_desc', true );
		$token        = get_post_meta( $pago_id, '_convoca_link_key', true );
		$expires      = get_post_meta( $pago_id, '_convoca_expires_at', true );
		$url          = Payment_Handler::get_payment_link( $pago_id, is_string( $token ) ? $token : '', is_numeric( $expires ) ? (int) $expires : null );

		$label = '' !== $concepto ? $concepto : __( 'pago pendiente', 'convoca-gateway' );

		/* translators: %s: payment concept. */
		$subject = sprintf( __( 'Tu enlace de pago: %s', 'convoca-gateway' ), $label );

		$body  = '<h2>' . esc_html__( 'Tienes un pago pendiente', 'convoca-gateway' ) . '</h2>';
		$body .= '<p>' . esc_html( sprintf( /* translators: %s: payment concept. */ __( 'Concepto: %s', 'convoca-gateway' ), $label ) ) . '</p>';
		$body .= '<p><strong>' . esc_html__( 'Importe', 'convoca-gateway' ) . ':</strong> ' . esc_html( CPT_Pago::format_amount( $amount_cents ) ) . '</p>';
		if ( is_numeric( $expires ) && (int) $expires > 0 ) {
			$body .= '<p>' . esc_html( sprintf( /* translators: %s: expiration date. */ __( 'El enlace caduca el %s.', 'convoca-gateway' ), wp_date( 'd/m/Y H:i', (int) $expires ) ) ) . '</p>';
		}
		$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Realizar el pago', 'convoca-gateway' ) . '</a></p>';

		return $this->deliver( $email, $subject, $body );
	}

	/**
	 * Send a "your link expires soon" notice (D21b).
	 *
	 * @param int $pago_id Payment post ID.
	 * @return bool True if an email was sent.
	 */
	public function send_expiry_notice( int $pago_id ): bool {
		$email = get_post_meta( $pago_id, '_convoca_recipient_email', true );
		if ( empty( $email ) ) {
			return false;
		}

		$concepto = (string) get_post_meta( $pago_id, '_convoca_product_desc', true );
		$label    = '' !== $concepto ? $concepto : __( 'pago pendiente', 'convoca-gateway' );
		$token    = get_post_meta( $pago_id, '_convoca_link_key', true );
		$expires  = get_post_meta( $pago_id, '_convoca_expires_at', true );
		$url      = Payment_Handler::get_payment_link( $pago_id, is_string( $token ) ? $token : '', is_numeric( $expires ) ? (int) $expires : null );

		$hours = Link_Expiry::notice_hours();

		/* translators: %s: payment concept. */
		$subject = sprintf( __( 'Tu enlace de pago caduca pronto: %s', 'convoca-gateway' ), $label );

		$body = '<h2>' . esc_html__( 'Tu enlace de pago está a punto de caducar', 'convoca-gateway' ) . '</h2>';
		/* translators: %d: number of hours. */
		$body .= '<p>' . esc_html( sprintf( __( 'Tu enlace de pago caducará en menos de %d horas. Si aún no lo has realizado, puedes completarlo ahora.', 'convoca-gateway' ), $hours ) ) . '</p>';
		if ( is_numeric( $expires ) && (int) $expires > 0 ) {
			$body .= '<p>' . esc_html( sprintf( /* translators: %s: expiration date. */ __( 'Fecha límite: %s.', 'convoca-gateway' ), wp_date( 'd/m/Y H:i', (int) $expires ) ) ) . '</p>';
		}
		$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Realizar el pago', 'convoca-gateway' ) . '</a></p>';

		return $this->deliver( $email, $subject, $body );
	}

	/**
	 * Generic sender logic.
	 */
	private function maybe_send( int $payment_id, string $type, array $extra = array() ): void {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$enabled  = ( $settings['email_confirmation'] ?? '0' ) === '1';

		if ( ! $enabled ) {
			return;
		}

		$email = get_post_meta( $payment_id, '_convoca_payer_email', true );
		if ( empty( $email ) ) {
			return;
		}

		$subject = $settings[ "email_{$type}_subject" ] ?? '';
		$body    = $settings[ "email_{$type}_body" ] ?? '';

		// Fallbacks if empty.
		if ( empty( $subject ) ) {
			$subject = ( $type === 'success' )
				? __( 'Confirmación de pago: {producto}', 'convoca-gateway' )
				: __( 'Problema con tu pago: {producto}', 'convoca-gateway' );
		}

		if ( empty( $body ) ) {
			$body = ( $type === 'success' )
				? '<h2>' . __( 'Resumen de tu pago', 'convoca-gateway' ) . '</h2>
                   <p>' . __( 'Hemos recibido correctamente tu pago. Detalles:', 'convoca-gateway' ) . '</p>
                   <ul>
                       <li><strong>' . __( 'Importe', 'convoca-gateway' ) . ':</strong> {importe}</li>
                       <li><strong>' . __( 'Concepto', 'convoca-gateway' ) . ':</strong> {producto}</li>
                       <li><strong>' . __( 'Fecha', 'convoca-gateway' ) . ':</strong> {fecha}</li>
                   </ul>
                   <p>' . __( 'Puedes descargar tu recibo aquí:', 'convoca-gateway' ) . ' <a href="{recibo_url}">{recibo_url}</a></p>'
				: '<h2>' . __( 'Error en el pago', 'convoca-gateway' ) . '</h2>
                   <p>' . __( 'No hemos podido procesar tu pago para {producto}.', 'convoca-gateway' ) . '</p>
                   <p>' . __( 'Motivo', 'convoca-gateway' ) . ': {motivo}</p>
                   <p>' . __( 'Puedes volver a intentarlo aquí:', 'convoca-gateway' ) . ' <a href="{enlace_pago}">{enlace_pago}</a></p>';
		}

		// Replace variables.
		$vars    = $this->get_template_vars( $payment_id, $extra );
		$subject = str_replace( array_keys( $vars ), array_values( $vars ), $subject );
		$body    = str_replace( array_keys( $vars ), array_values( $vars ), $body );

		$this->deliver( $email, $subject, $body );
	}

	/**
	 * Build variables for replacement.
	 */
	private function get_template_vars( int $payment_id, array $extra = array() ): array {
		$amount_cents = (int) get_post_meta( $payment_id, '_convoca_amount_cents', true );
		$method       = get_post_meta( $payment_id, '_convoca_method', true );
		$origin       = get_post_meta( $payment_id, '_convoca_origin', true );
		$enroll_url   = get_post_meta( $payment_id, '_convoca_enroll_url', true );
		$response     = (string) ( $extra['response_code'] ?? get_post_meta( $payment_id, '_convoca_redsys_response', true ) );

		// Build payment link.
		$payment_url = Payment_Handler::get_payment_link( $payment_id );

		$motivo = __( 'Tu banco rechazó la operación.', 'convoca-gateway' );
		if ( '' !== $response ) {
			$motivo = sprintf(
				/* translators: 1: human-readable reason, 2: numeric code. */
				__( '%1$s (código %2$s)', 'convoca-gateway' ),
				Redsys_Client::get_response_message( $response ),
				$response
			);
		}

		return array(
			'{importe}'            => CPT_Pago::format_amount( $amount_cents ),
			'{metodo}'             => ucfirst( (string) $method ),
			'{fecha}'              => get_the_date( 'd/m/Y H:i', $payment_id ),
			'{producto}'           => (string) $origin,
			'{enlace_pago}'        => $payment_url,
			'{enlace_inscripcion}' => $enroll_url ?: '',
			'{motivo}'             => $motivo,
			'{recibo_url}'         => Receipt_Generator::receipt_url( $payment_id ),
		);
	}

	/**
	 * Deliver an HTML email using the configured sender.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @return bool True on success.
	 */
	private function deliver( string $to, string $subject, string $body ): bool {
		$settings = get_option( 'convoca_gateway_settings', array() );

		$sender_name  = $settings['email_sender_name'] ?? get_bloginfo( 'name' );
		$sender_email = get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $sender_name, $sender_email ),
		);

		return wp_mail( $to, $subject, $body, $headers );
	}
}
