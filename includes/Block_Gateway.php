<?php
/**
 * Gutenberg block registration for all Gateway blocks.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Block_Gateway {


	public function __construct() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	public function register_blocks(): void {
		// Register editor script first.
		wp_register_script(
			'bdg-blocks-editor',
			CONV_GATEWAY_URL . 'assets/js/blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render' ),
			CONV_GATEWAY_VERSION,
			true
		);

		// 1. Página de Pago
		register_block_type(
			'convoca-gateway/pagina-pago',
			array(
				'apiVersion'      => 3,
				'title'           => __( 'Página de Pago', 'convoca-gateway' ),
				'category'        => 'convoca-gateway',
				'icon'            => 'money-alt',
				'description'     => __( 'Página de procesamiento de pago con selección de método (tarjeta/bizum).', 'convoca-gateway' ),
				'keywords'        => array( 'pago', 'tarjeta', 'bizum', 'redsys' ),
				'render_callback' => array( $this, 'render_pago' ),
				'editor_script'   => 'bdg-blocks-editor',
			)
		);

		// 2. Pago Correcto
		register_block_type(
			'convoca-gateway/pago-ok',
			array(
				'apiVersion'      => 3,
				'title'           => __( 'Pago Correcto', 'convoca-gateway' ),
				'category'        => 'convoca-gateway',
				'icon'            => 'yes-alt',
				'description'     => __( 'Página de confirmación tras un pago exitoso.', 'convoca-gateway' ),
				'keywords'        => array( 'pago', 'éxito', 'confirmación' ),
				'render_callback' => array( $this, 'render_ok' ),
				'editor_script'   => 'bdg-blocks-editor',
			)
		);

		// 3. Pago Fallido
		register_block_type(
			'convoca-gateway/pago-ko',
			array(
				'apiVersion'      => 3,
				'title'           => __( 'Pago Fallido', 'convoca-gateway' ),
				'category'        => 'convoca-gateway',
				'icon'            => 'dismiss',
				'description'     => __( 'Página de error cuando un pago no se ha podido procesar.', 'convoca-gateway' ),
				'keywords'        => array( 'pago', 'error', 'fallido' ),
				'render_callback' => array( $this, 'render_ko' ),
				'editor_script'   => 'bdg-blocks-editor',
			)
		);
	}

	public function render_pago( array $attrs ): string {
		$handler = new Payment_Handler();
		return $handler->render_payment_page( $attrs );
	}

	public function render_ok( array $attrs ): string {
		$handler = new Payment_Handler();
		return $handler->render_ok_page( $attrs );
	}

	public function render_ko( array $attrs ): string {
		$handler = new Payment_Handler();
		return $handler->render_ko_page( $attrs );
	}

	/**
	 * Dummy method to prevent fatal errors from legacy hooks.
	 */
	public function editor_assets(): void {
		// No-op.
	}
}
