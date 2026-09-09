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
 * Receipt / donation justification numbering and URL generation (D23/D24).
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Receipt_Generator {

	/** Prefix applied to donation justification numbers (D-AÑO-NNN). */
	public const DONATION_PREFIX = 'D';

	/** Option-key series for regular receipts. */
	private const SERIES_RECEIPT = 'recibo';

	/** Option-key series for donation justifications. */
	private const SERIES_DONATION = 'donacion';

	/**
	 * Format an annual receipt number.
	 *
	 * @param int    $year     Year.
	 * @param int    $sequence Zero-padded sequence (1 → 001).
	 * @param string $prefix   Optional prefix (e.g. 'D' for donations).
	 * @return string E.g. '2026-001' or 'D-2026-001'.
	 */
	public static function format_number( int $year, int $sequence, string $prefix = '' ): string {
		$base = sprintf( '%04d-%03d', $year, $sequence );

		return '' !== $prefix ? $prefix . '-' . $base : $base;
	}

	/**
	 * Reserve the next sequence for a series+year (per-year counter).
	 *
	 * @param string $series Series key ('recibo' or 'donacion').
	 * @param int    $year   Year.
	 * @return int Next sequence (1-based).
	 */
	public static function next_sequence( string $series, int $year ): int {
		$key = 'convoca_gateway_receipt_seq_' . $series . '_' . $year;
		$seq = (int) get_option( $key, 0 );
		++$seq;
		update_option( $key, $seq, false );

		return $seq;
	}

	/**
	 * Whether a payment is flagged as a donation.
	 *
	 * @param int $pago_id Payment post ID.
	 */
	public static function is_donation( int $pago_id ): bool {
		return '1' === (string) get_post_meta( $pago_id, '_convoca_es_donacion', true );
	}

	/**
	 * Assign (idempotently) an annual receipt number to a paid payment.
	 *
	 * @param int $pago_id Payment post ID.
	 * @return string Assigned number (e.g. '2026-001' or 'D-2026-001').
	 */
	public static function assign_number( int $pago_id ): string {
		$existing = get_post_meta( $pago_id, '_convoca_receipt_number', true );
		if ( ! empty( $existing ) ) {
			return (string) $existing;
		}

		$year     = (int) wp_date( 'Y' );
		$donation = self::is_donation( $pago_id );
		$prefix   = $donation ? self::DONATION_PREFIX : '';
		$series   = $donation ? self::SERIES_DONATION : self::SERIES_RECEIPT;

		$number = self::format_number( $year, self::next_sequence( $series, $year ), $prefix );

		update_post_meta( $pago_id, '_convoca_receipt_number', $number );
		update_post_meta( $pago_id, '_convoca_receipt_year', $year );

		return $number;
	}

	/**
	 * Get (or mint) the unguessable access key for a payment's receipt page.
	 *
	 * @param int $pago_id Payment post ID.
	 */
	public static function receipt_key( int $pago_id ): string {
		$key = get_post_meta( $pago_id, '_convoca_receipt_key', true );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 32, false );
			update_post_meta( $pago_id, '_convoca_receipt_key', $key );
		}

		return (string) $key;
	}

	/**
	 * Build the public URL of the receipt/justification print page.
	 *
	 * @param int $pago_id Payment post ID.
	 */
	public static function receipt_url( int $pago_id ): string {
		$base = Payment_Handler::get_payment_page_url();

		return add_query_arg(
			array(
				'convoca_gateway_pago'        => $pago_id,
				'convoca_gateway_receipt_key' => self::receipt_key( $pago_id ),
				'convoca_gateway_recibo'      => 1,
			),
			$base
		);
	}

	/**
	 * Org data (name, CIF, address) used on receipts, from settings.
	 *
	 * @return array{name:string, cif:string, address:string}
	 */
	public static function org_data(): array {
		$settings = get_option( 'convoca_gateway_settings', array() );

		return array(
			'name'    => ! empty( $settings['org_name'] ) ? (string) $settings['org_name'] : get_bloginfo( 'name' ),
			'cif'     => (string) ( $settings['org_cif'] ?? '' ),
			'address' => (string) ( $settings['org_address'] ?? '' ),
		);
	}

	/**
	 * Standard donation legal text (Ley 49/2002).
	 */
	public static function donation_legal_text(): string {
		return __( 'Donación a entidad sin ánimo de lucro acogida a la Ley 49/2002, de 23 de diciembre, de régimen fiscal de las entidades sin fines lucrativos y de los incentivos fiscales al mecenazgo.', 'convoca-gateway' );
	}

	/**
	 * On payment completion: assign the annual number and persist the receipt URL.
	 *
	 * @param int $pago_id Payment post ID.
	 */
	public static function maybe_generate( int $pago_id ): void {
		if ( 'pago' !== get_post_type( $pago_id ) ) {
			return;
		}

		self::assign_number( $pago_id );
		update_post_meta( $pago_id, '_convoca_receipt_pdf', self::receipt_url( $pago_id ) );
	}
}
