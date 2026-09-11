<?php

/**
 * Convoca Gateway
 *
 * @package    Convoca\Gateway
 * @subpackage Convoca-gateway
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
 * Uninstall handler for Convoca Gateway.
 *
 * @package Convoca\Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ─── Keep data mode ───
// Define CONVOCA_KEEP_DATA_ON_UNINSTALL in wp-config.php to preserve all data
// when uninstalling. Useful for temporary deactivation + reactivation.
if (
	( defined( 'CONVOCA_KEEP_DATA_ON_UNINSTALL' ) && CONVOCA_KEEP_DATA_ON_UNINSTALL )
	|| 1 === (int) get_option( 'convoca_uninstall_keep_data', 0 )
) {
	return;
}

// Clear scheduled hooks.
wp_clear_scheduled_hook( 'convoca_gateway_cleanup_pending' );
wp_clear_scheduled_hook( 'convoca_gateway_retry_notifications' );
// Estos dos son los cron vivos: el de caducidad de enlaces y el recordatorio de
// pagos sin terminar. Los dos de arriba son de versiones antiguas y se dejan para
// que una instalación que venga de ellas no se quede con un evento huérfano.
wp_clear_scheduled_hook( 'convoca_gateway_expiry_notice' );
wp_clear_scheduled_hook( 'convoca_gateway_pending_reminder' );

// Delete options.
delete_option( 'convoca_gateway_redsys_config' );
delete_option( 'convoca_gateway_db_version' );
delete_option( 'convoca_gateway_notification_retry_limit' );
delete_option( 'convoca_gateway_settings' );

// Delete posts of CPT 'pago'.
$payments = get_posts(
	array(
		'post_type'   => 'pago',
		'numberposts' => -1,
		'post_status' => 'any',
	)
);
foreach ( $payments as $payment ) {
	wp_delete_post( $payment->ID, true );
}

// ─── Transients con prefijo del plugin ───
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_convoca_gateway_%'
	    OR option_name LIKE '_transient_timeout_convoca_gateway_%'"
);
