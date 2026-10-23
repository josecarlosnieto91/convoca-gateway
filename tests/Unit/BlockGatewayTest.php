<?php

/**
 * Los bloques de Gutenberg del gateway: página de pago, pago correcto y pago fallido.
 *
 * Estaban al 0 % (56 statements). Un bloque sin `render_callback` no da error: sale vacío en el
 * sitio, y eso se descubre cuando alguien intenta pagar. Aquí se comprueba que los tres se
 * registran con su devolución de llamada, su script de editor y su categoría.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Block_Gateway;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Block_Gateway.php';

class BlockGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_bloques'] = [];
        $GLOBALS['__gw_scripts'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_bloques'], $GLOBALS['__gw_scripts']);
    }

    public function test_los_tres_bloques_se_registran(): void
    {
        (new Block_Gateway())->register_blocks();

        $this->assertSame(
            ['convoca-gateway/pagina-pago', 'convoca-gateway/pago-ok', 'convoca-gateway/pago-ko'],
            array_keys($GLOBALS['__gw_bloques']),
            'los tres bloques del flujo de pago tienen que existir'
        );
    }

    public function test_cada_bloque_tiene_su_render_callback(): void
    {
        (new Block_Gateway())->register_blocks();

        foreach ($GLOBALS['__gw_bloques'] as $nombre => $args) {
            $this->assertArrayHasKey('render_callback', $args, "sin render_callback, {$nombre} sale vacío en el sitio");
            $this->assertIsCallable($args['render_callback']);
            $this->assertSame('conv-gateway-blocks-editor', $args['editor_script'], "{$nombre} necesita su script de editor");
            $this->assertSame('convoca-gateway', $args['category']);
            $this->assertSame(3, $args['apiVersion']);
        }
    }

    public function test_cada_bloque_apunta_a_su_propio_render(): void
    {
        (new Block_Gateway())->register_blocks();

        // El callback es [objeto, método]: se compara el método, que es lo que tiene que cuadrar.
        $esperado = [
            'convoca-gateway/pagina-pago' => 'render_pago',
            'convoca-gateway/pago-ok' => 'render_ok',
            'convoca-gateway/pago-ko' => 'render_ko',
        ];

        foreach ($esperado as $bloque => $metodo) {
            $callback = $GLOBALS['__gw_bloques'][ $bloque ]['render_callback'];
            $this->assertInstanceOf(Block_Gateway::class, $callback[0], "{$bloque} lo atiende la clase de bloques");
            $this->assertSame($metodo, $callback[1], "{$bloque} tiene que acabar en {$metodo}");
        }
    }

    public function test_el_script_del_editor_declara_sus_dependencias(): void
    {
        (new Block_Gateway())->register_blocks();

        $script = $GLOBALS['__gw_scripts']['conv-gateway-blocks-editor'] ?? null;
        $this->assertNotNull($script, 'el editor necesita su script');
        $this->assertStringContainsString('blocks-editor.js', $script['src']);
        $this->assertContains('wp-blocks', $script['deps']);
        $this->assertContains('wp-server-side-render', $script['deps'], 'el bloque pide el render en servidor');
    }

    public function test_el_gancho_heredado_no_revienta(): void
    {
        // Es un no-op a propósito (ganchos antiguos que aún llaman aquí): si dejara de existir,
        // un sitio con la versión anterior enganchada se caería al arrancar.
        (new Block_Gateway())->editor_assets();

        $this->assertTrue(true, 'llamarlo no puede lanzar nada');
    }
}
