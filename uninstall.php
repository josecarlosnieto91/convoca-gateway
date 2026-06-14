<?php
/**
 * Uninstall handler for Convoca Gateway.
 *
 * @package Convoca\Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─── Keep data mode ───
// Define CONVOCA_KEEP_DATA_ON_UNINSTALL in wp-config.php to preserve all data
// when uninstalling. Useful for temporary deactivation + reactivation.
if ( defined( 'CONVOCA_KEEP_DATA_ON_UNINSTALL' ) && CONVOCA_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

// Clear scheduled hooks.
wp_clear_scheduled_hook( 'conv_gateway_cleanup_pending' );
wp_clear_scheduled_hook( 'conv_gateway_retry_notifications' );

// Delete options.
delete_option( 'conv_gateway_redsys_config' );
delete_option( 'conv_gateway_db_version' );
delete_option( 'conv_gateway_notification_retry_limit' );
delete_option( 'conv_gateway_settings' );

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
