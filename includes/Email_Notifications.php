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
	 * Default email templates (used when the setting is empty).
	 *
	 * @return array{email_success_subject:string,email_success_body:string,email_pending_subject:string,email_pending_body:string,email_failed_subject:string,email_failed_body:string}
	 */
	public static function default_templates(): array {
		return array(
			'email_success_subject' => __( 'Confirmación de pago: {producto}', 'convoca-gateway' ),
			'email_success_body'    => '<h2>' . __( 'Resumen de tu pago', 'convoca-gateway' ) . '</h2>
                   <p>' . __( 'Hemos recibido correctamente tu pago. Detalles:', 'convoca-gateway' ) . '</p>
                   <ul>
                       <li><strong>' . __( 'Importe', 'convoca-gateway' ) . ':</strong> {importe}</li>
                       <li><strong>' . __( 'Concepto', 'convoca-gateway' ) . ':</strong> {producto}</li>
                       <li><strong>' . __( 'Fecha', 'convoca-gateway' ) . ':</strong> {fecha}</li>
                   </ul>
                   <p>' . __( 'Puedes descargar tu recibo aquí:', 'convoca-gateway' ) . ' <a href="{recibo_url}">{recibo_url}</a></p>',
			'email_pending_subject' => __( 'Tu pago de {importe} quedó sin completar', 'convoca-gateway' ),
			'email_pending_body'    => '<h2>' . __( 'Un pago se quedó a medias', 'convoca-gateway' ) . '</h2>
                   <p>' . __( 'Empezaste un pago de {importe} para {producto}, pero no llegó a completarse.', 'convoca-gateway' ) . '</p>
                   <p><strong>' . __( 'No se te ha cobrado nada.', 'convoca-gateway' ) . '</strong></p>
                   <p>' . __( 'Si lo dejaste a medias sin querer, puedes retomarlo donde lo dejaste:', 'convoca-gateway' ) . '</p>
                   <p style="margin:24px 0;">
                       <a href="{enlace_pago}" style="display:inline-block;padding:13px 24px;background:#e8590c;color:#ffffff;border-radius:8px;text-decoration:none;font-weight:600;font-family:Arial,Helvetica,sans-serif;">' . __( 'Completar el pago', 'convoca-gateway' ) . '</a>
                   </p>
                   <p>' . __( 'Y si ya lo hiciste por otra vía, o simplemente cambiaste de idea, ignora este mensaje: no vamos a insistirte.', 'convoca-gateway' ) . '</p>
                   <p style="font-size:13px;color:#64748b;margin-top:24px;">' . __( 'Si el botón no te funciona, copia esta dirección en tu navegador:', 'convoca-gateway' ) . '<br>{enlace_pago}</p>',
			'email_failed_subject'  => __( 'Problema con tu pago: {producto}', 'convoca-gateway' ),
			'email_failed_body'     => '<h2>' . __( 'Error en el pago', 'convoca-gateway' ) . '</h2>
                   <p>' . __( 'No hemos podido procesar tu pago para {producto}.', 'convoca-gateway' ) . '</p>
                   <p>' . __( 'Motivo', 'convoca-gateway' ) . ': {motivo}</p>
                   <p>' . __( 'Puedes volver a intentarlo aquí:', 'convoca-gateway' ) . ' <a href="{enlace_pago}">{enlace_pago}</a></p>',
		);
	}

	/**
	 * Generic sender logic.
	 */
	/**
	 * ¿Toca enviar el recibo de una cuota o una inscripción?
	 *
	 * Funcionalidad PRO: sin licencia no se envía (queda el aviso general de
	 * confirmaciones). Con licencia, manda el ajuste, activado por defecto.
	 *
	 * @param int $payment_id ID del pago.
	 */
	private static function envia_recibo_de_cuota( int $payment_id ): bool {
		$origin = (string) get_post_meta( $payment_id, '_convoca_origin', true );
		if ( ! in_array( $origin, array( 'members', 'enroll' ), true ) ) {
			return false;
		}

		if ( ! class_exists( 'Convoca\Core\License_Manager' ) || ! \Convoca\Core\License_Manager::has_pro( 'gateway' ) ) {
			return false;
		}

		$settings = get_option( 'convoca_gateway_settings', array() );

		return '1' === (string) ( $settings['auto_receipt_fees'] ?? '1' );
	}

	/** Minutos que se espera antes de recordar un pago sin terminar. */
	public const REMINDER_AFTER = 1800;

	/** Evento de cron del recordatorio. */
	public const REMINDER_HOOK = 'convoca_gateway_pending_reminder';

	/** Frecuencia propia: cada cuarto de hora, para que el aviso salga a los 30 minutos. */
	public const REMINDER_SCHEDULE = 'convoca_gateway_quarter_hourly';

	/**
	 * Añade la frecuencia de cuarto de hora al listado de WordPress.
	 *
	 * @param array $schedules Frecuencias registradas.
	 * @return array
	 */
	public static function register_schedule( array $schedules ): array {
		$schedules[ self::REMINDER_SCHEDULE ] = array(
			'interval' => 900, // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 15 minutos es justo el retardo que el aviso necesita para salir a la media hora.
			'display'  => __( 'Cada 15 minutos (Convoca)', 'convoca-gateway' ),
		);

		return $schedules;
	}

	/**
	 * Programa el barrido de pagos sin terminar.
	 */
	public static function schedule_reminders(): void {
		if ( ! wp_next_scheduled( self::REMINDER_HOOK ) ) {
			wp_schedule_event( time() + 900, self::REMINDER_SCHEDULE, self::REMINDER_HOOK );
		}
	}

	/**
	 * Retira el barrido.
	 */
	public static function unschedule_reminders(): void {
		wp_clear_scheduled_hook( self::REMINDER_HOOK );
	}

	/**
	 * Avisa a quien dejó su correo de un pago que se quedó a medias.
	 *
	 * Se manda una sola vez por pago: la promesa del mensaje («no vamos a insistirte»)
	 * solo se sostiene si es verdad. Sin correo no se puede avisar, y si el enlace ya
	 * caducó no se manda un botón que no llevaría a ninguna parte.
	 *
	 * @param int      $pago_id Pago pendiente.
	 * @param int|null $now     Base de tiempo (pruebas).
	 * @return bool True si el correo salió.
	 */
	/**
	 * Cuándo se creó el pago, en marca de tiempo.
	 *
	 * Los pagos nuevos traen `created_ts`. Los antiguos solo tienen la fecha local
	 * (`current_time('mysql')`), así que se interpreta con el desfase del sitio. Un
	 * pago sin fecha reconocible no se recuerda: mejor no avisar que avisar mal.
	 *
	 * @param array $meta Meta del pago.
	 * @return int 0 si no se puede saber.
	 */
	public static function created_ts( array $meta ): int {
		$ts = (int) ( $meta['_convoca_created_ts'] ?? 0 );
		if ( $ts > 0 ) {
			return $ts;
		}

		$fecha = (string) ( $meta['_convoca_created_at'] ?? '' );
		if ( '' === $fecha ) {
			return 0;
		}

		$desfase = (float) ( get_option( 'gmt_offset', 0 ) );

		return (int) strtotime( $fecha ) - (int) ( $desfase * HOUR_IN_SECONDS );
	}

	/**
	 * ¿Toca recordar este pago? Reglas, todas juntas y sin consultar nada.
	 *
	 * @param array $meta Meta del pago.
	 * @param int   $now  Momento actual.
	 * @return bool
	 */
	public static function should_remind( array $meta, int $now ): bool {
		// Ya se cobró (o se anuló): no hay nada que recordar.
		if ( 'pending' !== (string) ( $meta['_convoca_status'] ?? '' ) ) {
			return false;
		}

		// Un enlace es una plantilla, no un cobro: no hay nada que recordarle a nadie.
		if ( 'link_payment' === (string) ( $meta['_convoca_origin'] ?? '' ) ) {
			return false;
		}

		// Un importe de cero no es un pago que se pueda completar (una plantilla de
		// donativo antes de usarse, por ejemplo).
		if ( (int) ( $meta['_convoca_amount_cents'] ?? 0 ) <= 0 ) {
			return false;
		}

		// Sin correo no hay a quién avisar.
		if ( '' === self::reminder_email( $meta ) ) {
			return false;
		}

		// Un aviso, no una campaña.
		if ( '' !== (string) ( $meta['_convoca_reminder_sent'] ?? '' ) ) {
			return false;
		}

		// Si el enlace ya caducó, el botón no llevaría a ninguna parte.
		$expira = (int) ( $meta['_convoca_expires_at'] ?? 0 );
		if ( $expira > 0 && $expira < $now ) {
			return false;
		}

		// Todavía está a tiempo de terminarlo sin que le demos la lata.
		$creado = self::created_ts( $meta );
		if ( 0 === $creado || ( $now - $creado ) < self::REMINDER_AFTER ) {
			return false;
		}

		return true;
	}

	/**
	 * El correo al que se avisa: el de quien paga y, si no lo hay, el del enlace.
	 *
	 * @param array $meta Meta del pago.
	 * @return string Cadena vacía si no hay ninguno válido.
	 */
	public static function reminder_email( array $meta ): string {
		foreach ( array( '_convoca_payer_email', '_convoca_recipient_email' ) as $clave ) {
			$email = (string) ( $meta[ $clave ] ?? '' );
			if ( '' !== $email && is_email( $email ) ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Recuerda a quien dejó su correo que dejó un pago a medias.
	 *
	 * @param int      $pago_id Pago.
	 * @param int|null $now     Base de tiempo (pruebas).
	 * @return bool True si el correo salió.
	 */
	public static function send_pending_reminder( int $pago_id, ?int $now = null ): bool {
		$now  = $now ?? time();
		$meta = array();
		foreach ( array( '_convoca_status', '_convoca_payer_email', '_convoca_recipient_email', '_convoca_reminder_sent', '_convoca_expires_at', '_convoca_created_ts', '_convoca_created_at', '_convoca_origin', '_convoca_amount_cents' ) as $clave ) {
			$meta[ $clave ] = get_post_meta( $pago_id, $clave, true );
		}

		if ( ! self::should_remind( $meta, $now ) ) {
			return false;
		}

		$settings = get_option( 'convoca_gateway_settings', array() );
		$defaults = self::default_templates();

		$subject = (string) ( $settings['email_pending_subject'] ?? '' );
		$body    = (string) ( $settings['email_pending_body'] ?? '' );
		$subject = '' !== $subject ? $subject : (string) $defaults['email_pending_subject'];
		$body    = '' !== $body ? $body : (string) $defaults['email_pending_body'];

		$instancia = new self();
		$vars      = $instancia->get_template_vars( $pago_id );

		return $instancia->deliver(
			self::reminder_email( $meta ),
			str_replace( array_keys( $vars ), array_values( $vars ), $subject ),
			str_replace( array_keys( $vars ), array_values( $vars ), $body )
		);
	}

	/**
	 * Busca los pagos empezados que nunca terminaron y avisa una vez a cada uno.
	 *
	 * El corte se hace en la consulta por la marca de tiempo numérica, para no
	 * depender de la zona horaria; las reglas de verdad viven en `should_remind()`.
	 *
	 * @param int|null $now Base de tiempo (pruebas).
	 * @return int Cuántos avisos salieron.
	 */
	public static function maybe_send_pending_reminders( ?int $now = null ): int {
		$now = $now ?? time();

		$pagos = get_posts(
			array(
				'post_type'      => 'pago',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_convoca_status',
						'value' => 'pending',
					),
					array(
						'key'     => '_convoca_created_ts',
						'value'   => $now - self::REMINDER_AFTER,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_convoca_reminder_sent',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$enviados = 0;

		foreach ( $pagos as $id ) {
			$id = (int) $id;

			if ( self::send_pending_reminder( $id, $now ) ) {
				++$enviados;
			}

			// Se haya podido avisar o no, no se vuelve a mirar: un aviso, no una campaña.
			update_post_meta( $id, '_convoca_reminder_sent', $now );
		}

		return $enviados;
	}

	private function maybe_send( int $payment_id, string $type, array $extra = array() ): void {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$enabled  = ( $settings['email_confirmation'] ?? '0' ) === '1';

		// Los donativos llevan marca propia: el donante espera su recibo y no depende
		// del aviso general de confirmaciones, que puede estar apagado.
		$forced = '1' === (string) get_post_meta( $payment_id, '_convoca_receipt_always', true );

		// Cuotas e inscripciones: el recibo automático tiene su propio interruptor
		// (activado por defecto) y es una funcionalidad PRO. Si está apagado o no hay
		// licencia, se sigue rigiendo por el aviso general de confirmaciones.
		if ( ! $enabled && ! $forced && ! self::envia_recibo_de_cuota( $payment_id ) ) {
			return;
		}

		$email = get_post_meta( $payment_id, '_convoca_payer_email', true );
		if ( empty( $email ) ) {
			return;
		}

		$subject = $settings[ "email_{$type}_subject" ] ?? '';
		$body    = $settings[ "email_{$type}_body" ] ?? '';

		$defaults = self::default_templates();

		// Fallbacks if empty.
		if ( empty( $subject ) ) {
			$subject = $defaults[ "email_{$type}_subject" ];
		}

		if ( empty( $body ) ) {
			$body = $defaults[ "email_{$type}_body" ];
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
	/**
	 * Cómo se llama el pago para quien lo hace: el concepto que él ve, no el
	 * nombre interno (`donativo`, `members_cuota`). Si el pago no trae concepto,
	 * se usa el nombre legible de su origen.
	 *
	 * @param int    $payment_id Pago.
	 * @param string $origin     Origen del pago.
	 * @return string
	 */
	private static function producto_legible( int $payment_id, string $origin ): string {
		$descripcion = (string) get_post_meta( $payment_id, '_convoca_product_desc', true );

		if ( '' !== trim( $descripcion ) ) {
			return $descripcion;
		}

		return CPT_Pago::origin_label( $origin );
	}

	private function get_template_vars( int $payment_id, array $extra = array() ): array {
		$amount_cents = (int) get_post_meta( $payment_id, '_convoca_amount_cents', true );
		$method       = get_post_meta( $payment_id, '_convoca_method', true );
		$origin       = get_post_meta( $payment_id, '_convoca_origin', true );
		$enroll_url   = get_post_meta( $payment_id, '_convoca_enroll_url', true );
		$response     = (string) ( $extra['response_code'] ?? get_post_meta( $payment_id, '_convoca_redsys_response', true ) );

		// Enlace de pago: con token si el registro lo tiene (así el reintento sigue
		// valiendo y respeta su caducidad), y si no, el enlace firmado de siempre.
		$link_token  = (string) get_post_meta( $payment_id, '_convoca_link_key', true );
		$payment_url = $link_token
			? Payment_Handler::get_payment_link( $payment_id, $link_token, (int) get_post_meta( $payment_id, '_convoca_expires_at', true ) )
			: Payment_Handler::get_payment_link( $payment_id );

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
			'{producto}'           => esc_html( self::producto_legible( $payment_id, (string) $origin ) ),
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
