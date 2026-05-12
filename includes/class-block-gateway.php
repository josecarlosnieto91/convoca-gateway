<?php
/**
 * Gutenberg block registration for all Gateway blocks.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class Block_Gateway
{

    public function __construct()
    {
        add_action('init', [$this, 'register_blocks']);
    }

    public function register_blocks(): void
    {
        // Register editor script first
        wp_register_script(
            'bdg-blocks-editor',
            BDG_URL . 'assets/js/blocks-editor.js',
            ['wp-blocks', 'wp-element', 'wp-server-side-render'],
            BDG_VERSION,
            true
        );

        // 1. Página de Pago
        register_block_type('biodevas-gateway/pagina-pago', [
            'apiVersion'      => 3,
            'title'           => __('Página de Pago', 'convoca-gateway'),
            'category'        => 'convoca-gateway',
            'icon'            => 'money-alt',
            'description'     => __('Página de procesamiento de pago con selección de método (tarjeta/bizum).', 'convoca-gateway'),
            'keywords'        => ['pago', 'tarjeta', 'bizum', 'redsys'],
            'render_callback' => [$this, 'render_pago'],
            'editor_script'   => 'bdg-blocks-editor',
        ]);

        // 2. Pago Correcto
        register_block_type('biodevas-gateway/pago-ok', [
            'apiVersion'      => 3,
            'title'           => __('Pago Correcto', 'convoca-gateway'),
            'category'        => 'convoca-gateway',
            'icon'            => 'yes-alt',
            'description'     => __('Página de confirmación tras un pago exitoso.', 'convoca-gateway'),
            'keywords'        => ['pago', 'éxito', 'confirmación'],
            'render_callback' => [$this, 'render_ok'],
            'editor_script'   => 'bdg-blocks-editor',
        ]);

        // 3. Pago Fallido
        register_block_type('biodevas-gateway/pago-ko', [
            'apiVersion'      => 3,
            'title'           => __('Pago Fallido', 'convoca-gateway'),
            'category'        => 'convoca-gateway',
            'icon'            => 'dismiss',
            'description'     => __('Página de error cuando un pago no se ha podido procesar.', 'convoca-gateway'),
            'keywords'        => ['pago', 'error', 'fallido'],
            'render_callback' => [$this, 'render_ko'],
            'editor_script'   => 'bdg-blocks-editor',
        ]);
    }

    public function render_pago(array $attrs): string
    {
        $handler = new Payment_Handler();
        return $handler->render_payment_page($attrs);
    }

    public function render_ok(array $attrs): string
    {
        $handler = new Payment_Handler();
        return $handler->render_ok_page($attrs);
    }

    public function render_ko(array $attrs): string
    {
        $handler = new Payment_Handler();
        return $handler->render_ko_page($attrs);
    }

    /**
     * Dummy method to prevent fatal errors from legacy hooks.
     */
    public function editor_assets(): void
    {
        // No-op.
    }
}
