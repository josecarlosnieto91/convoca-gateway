<?php
/**
 * REST API endpoints for the gateway (webhooks).
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_API {


	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'convoca/v1',
			'/gateway/redsys-notify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_redsys_notify' ),
				'permission_callback' => '__return_true', // Redsys needs to call this without auth.
			)
		);
	}

	/**
	 * Handle Redsys notification via REST API.
	 */
	public function handle_redsys_notify( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();

		// Pass to the payment handler logic.
		$handler = new Payment_Handler();
		$result  = $handler->process_notification( $params );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		// Redsys expects 'OK' in the body.
		$response = new \WP_REST_Response( 'OK', 200 );
		$response->set_headers( array( 'Content-Type' => 'text/plain' ) );

		return $response;
	}
}
