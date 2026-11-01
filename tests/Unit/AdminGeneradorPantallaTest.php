<?php

/**
 * La pantalla del generador de enlaces.
 *
 * `render_page()` pinta el formulario con el que se crea un enlace de pago. Sin POST solo pinta;
 * con POST delega en `process_generation()`. Estaba al 25 % de líneas: media pantalla sin recorrer.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Generador;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/admin/class-admin-generador.php';

class AdminGeneradorPantallaTest extends TestCase
{
    /**
     * Sin envío, la pantalla pinta el formulario completo y se registra en el menú.
     */
    public function test_pinta_el_formulario_y_se_registra(): void
    {
        $_POST = [];
        $_GET  = [];

        $generador = new Admin_Generador();

        ob_start();
        $generador->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Generador de Enlaces de Pago', $html);
        $this->assertStringContainsString('convoca_gateway_generate_link_nonce', $html);
        $this->assertStringContainsString('convoca_gateway_amount', $html);
        $this->assertStringContainsString('convoca_gateway_concepto', $html);
        $this->assertStringContainsString('convoca_gateway_method', $html);

        $GLOBALS['__gw_submenus'] = [];
        $generador->add_menu();

        $this->assertArrayHasKey('conv-gateway-generador', $GLOBALS['__gw_submenus'], 'sin entrada de menú la pantalla no se alcanza');
    }

    /**
     * Las validaciones del formulario, con el código de error que ve quien rellena.
     *
     * `process_generation()` es privado: se llama por reflexión para no abrir la API solo por una prueba.
     *
     * @dataProvider casosInvalidos
     *
     * @param array<string, mixed> $post  Datos del formulario.
     * @param string               $codigo Código de error esperado.
     */
    public function test_rechaza_los_datos_invalidos(array $post, string $codigo): void
    {
        $metodo = new \ReflectionMethod(Admin_Generador::class, 'process_generation');
        $error  = $metodo->invoke(new Admin_Generador(), $post);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame($codigo, $error->get_error_code());
    }

    /**
     * Casos inválidos.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function casosInvalidos(): array
    {
        return [
            'importe por debajo del mínimo' => [['convoca_gateway_amount' => '0.10', 'convoca_gateway_concepto' => 'Cuota'], 'invalid_amount'],
            'concepto vacío sin donativo'   => [['convoca_gateway_amount' => '10'], 'missing_concept'],
            'concepto demasiado largo'      => [['convoca_gateway_amount' => '10', 'convoca_gateway_concepto' => str_repeat('a', 126)], 'long_concept'],
            'método desconocido'            => [['convoca_gateway_amount' => '10', 'convoca_gateway_concepto' => 'Cuota', 'convoca_gateway_method' => 'criptomoneda'], 'invalid_method'],
        ];
    }
}
