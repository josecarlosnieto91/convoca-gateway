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
 * Link expiration: configurable default validity, expiry notices and regeneration.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Expiry {

	/** Default validity of a payment link, in days. */
	public const DEFAULT_DAYS = 7;

	/** Default advance notice before a link expires, in hours. */
	public const DEFAULT_NOTICE_HOURS = 24;

	/**
	 * Orígenes cuyo enlace se cierra en cuanto el pago se confirma.
	 *
	 * Members y Enroll crean un pago con su propio enlace, para una sola cuota o
	 * una sola inscripción: cobrado ese pago, el enlace ya ha hecho su trabajo y
	 * deja de admitir pagos. Los enlaces del generador (donativos y plantillas)
	 * no se cierran nunca así: siguen vivos y solo caducan por fecha.
	 */
	public const CLOSE_ON_PAYMENT_ORIGINS = array( 'members', 'enroll' );

	/** Meta: instante en que el enlace se cerró por cobro (vacío = abierto). */
	public const CLOSED_AT_META = '_convoca_link_closed_at';

	/** Seconds in a day (avoids coupling the class to the WP constant in tests). */
	private const SECONDS_PER_DAY = 86400;

	/** Seconds in an hour. */
	private const SECONDS_PER_HOUR = 3600;

	/**
	 * Cron hook name for the daily expiry notice pass.
	 */
	public const CRON_HOOK = 'convoca_gateway_expiry_notice';

	/**
	 * Resolve the configured default link validity in days.
	 *
	 * @return int Days (>= 1), defaulting to 7.
	 */
	public static function default_expiry_days(): int {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$days     = (int) ( $settings['link_expiry_days'] ?? 0 );

		return $days > 0 ? $days : self::DEFAULT_DAYS;
	}

	/**
	 * Resolve the configured advance-notice window in hours.
	 *
	 * @return int Hours (>= 1), defaulting to 24.
	 */
	public static function notice_hours(): int {
		$settings = get_option( 'convoca_gateway_settings', array() );
		$hours    = (int) ( $settings['link_expiry_notice_hours'] ?? 0 );

		return $hours > 0 ? $hours : self::DEFAULT_NOTICE_HOURS;
	}

	/**
	 * Compute an expiration timestamp for a given number of days from $now.
	 *
	 * @param int      $days Number of days of validity.
	 * @param int|null $now  Base timestamp (defaults to current time).
	 * @return int Expiration timestamp.
	 */
	public static function compute_expiry_timestamp( int $days, ?int $now = null ): int {
		$now = $now ?? time();

		return $now + ( $days * self::SECONDS_PER_DAY );
	}

	/**
	 * Regenerate the payment link for an existing payment (D21/D22).
	 *
	 * Keeps the SAME payment (and therefore the same Redsys order id): only the
	 * expiration timestamp is refreshed and the notice flag reset. Returns the
	 * fresh signed URL.
	 *
	 * @param int $pago_id Payment post ID.
	 * @return string|\WP_Error Fresh payment URL or error.
	 */
	public static function regenerate_link( int $pago_id ): string|\WP_Error {
		$post = get_post( $pago_id );
		if ( ! $post || 'pago' !== $post->post_type ) {
			return new \WP_Error( 'pago_not_found', __( 'Pago no encontrado.', 'convoca-gateway' ) );
		}

		if ( self::is_closed( $pago_id ) ) {
			return new \WP_Error( 'link_closed', __( 'Este pago ya se ha cobrado: su enlace está cerrado y no se genera otro.', 'convoca-gateway' ) );
		}

		$expires_ts = self::compute_expiry_timestamp( self::default_expiry_days() );
		update_post_meta( $pago_id, '_convoca_expires_at', $expires_ts );

		// Reset the notice flag so the renewed link can be announced again.
		delete_post_meta( $pago_id, '_convoca_expiry_notice_sent' );

		$token = get_post_meta( $pago_id, '_convoca_link_key', true );

		return Payment_Handler::get_payment_link( $pago_id, is_string( $token ) ? $token : '', $expires_ts );
	}

	/**
	 * Whether the payment link is closed because its payment was already collected.
	 *
	 * @param int $pago_id Payment post ID.
	 * @return bool
	 */
	public static function is_closed( int $pago_id ): bool {
		return self::closed_at( $pago_id ) > 0;
	}

	/**
	 * When the link was closed after collection (0 while it is still open).
	 *
	 * @param int $pago_id Payment post ID.
	 * @return int Timestamp, or 0 when open.
	 */
	public static function closed_at( int $pago_id ): int {
		return (int) get_post_meta( $pago_id, self::CLOSED_AT_META, true );
	}

	/**
	 * Close the link of a payment that has just been collected.
	 *
	 * Solo para orígenes que cobran una vez (Members, Enroll). Un pago fallido no
	 * pasa por aquí, así que su enlace sigue vivo para reintentar. No se toca
	 * `_convoca_expires_at`: una cosa es «caducado por fecha» y otra «cobrado».
	 *
	 * @param int      $pago_id Payment post ID.
	 * @param string   $origin  Origen del pago (members|enroll|link_payment|…).
	 * @param int|null $now     Base timestamp (defaults to now).
	 * @return bool True cuando el enlace queda cerrado (o ya lo estaba).
	 */
	public static function close_after_payment( int $pago_id, string $origin, ?int $now = null ): bool {
		if ( ! in_array( $origin, self::CLOSE_ON_PAYMENT_ORIGINS, true ) ) {
			return false;
		}

		if ( self::is_closed( $pago_id ) ) {
			return true;
		}

		update_post_meta( $pago_id, self::CLOSED_AT_META, $now ?? time() );

		\Convoca\Core\Logger::info(
			sprintf( 'Enlace cerrado tras el cobro (origen: %s).', $origin ),
			'Gateway/LinkExpiry',
			$pago_id
		);

		return true;
	}

	/**
	 * Hook `convoca_gateway_payment_completed`: cierra el enlace de un pago que
	 * cobra una sola vez. El hook de pago fallido NO llama aquí, por diseño.
	 *
	 * @param int    $pago_id Payment post ID.
	 * @param string $origin  Origen del pago.
	 */
	public static function on_payment_completed( $pago_id, $origin = '' ): void {
		self::close_after_payment( (int) $pago_id, (string) $origin );
	}

	/**
	 * Schedule the daily expiry-notice cron event.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + self::SECONDS_PER_HOUR, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily expiry-notice cron event.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Daily cron callback: email members whose payment link expires within the
	 * configured notice window.
	 */
	public static function run_expiry_notices(): void {
		$notice_seconds = self::notice_hours() * self::SECONDS_PER_HOUR;
		$now            = time();
		$soon           = $now + $notice_seconds;

		$payments = get_posts(
			array(
				'post_type'      => 'pago',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_convoca_expires_at',
						'value'   => $now,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_convoca_expires_at',
						'value'   => $soon,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_convoca_expiry_notice_sent',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$notifier = new Email_Notifications();

		foreach ( $payments as $payment ) {
			$email = get_post_meta( $payment->ID, '_convoca_recipient_email', true );
			if ( empty( $email ) || 'paid' === get_post_meta( $payment->ID, '_convoca_status', true ) ) {
				continue;
			}

			if ( $notifier->send_expiry_notice( $payment->ID ) ) {
				update_post_meta( $payment->ID, '_convoca_expiry_notice_sent', time() );
			}
		}
	}
}
