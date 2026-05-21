<?php
/**
 * Convoca Gateway - Uninstall cleanup.
 *
 * @package Convoca\Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clear scheduled hooks.
wp_clear_scheduled_hook( 'bdg_cleanup_pending' );
wp_clear_scheduled_hook( 'bdg_retry_notifications' );

// Delete options.
delete_option( 'bdg_redsys_config' );
delete_option( 'bdg_db_version' );
delete_option( 'bdg_notification_retry_limit' );

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
