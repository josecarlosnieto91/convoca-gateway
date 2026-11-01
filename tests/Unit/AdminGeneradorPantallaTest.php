<?php

/**
 * La pantalla del generador de enlaces.
 *
 * `render_page()` pinta el formulario con el que se crea un enlace de pago: importe, concepto,
 * método, caducidad y cliente. Sin POST solo pinta; con POST delega en `process_generation()`.
 * Estaba al 25 % de líneas: media pantalla sin recorrer.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Generador;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/admin/class-admin-generador.php';

class AdminGeneradorPantallaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_caps'] = ['manage_options' => true, 'convoca_view_payments' => true];
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_posts'] = [];
        $_GET = [];
        $_POST = [];

        if (!class_exists('WP_Error')) {
            // El arnés ya lo define; si no, el test no puede ni construirse.
            $this->markTestSkipped('WP_Error no disponible');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_meta'], $GLOBALS['__gw_caps'], $GLOBALS['__gw_options'], $GLOBALS['__gw_posts']);
        $_GET = [];
        $_POST = [];
    }

    /**
     * Sin envío, la pantalla pinta el formulario completo.
     */
    public function test_pinta_el_formulario_de_generacion(): void
    {
        $generador = new Admin_Generador();

        ob_start();
        $generador->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Generador de Enlaces de Pago', $html);
        $this->assertStringContainsString('convoca_gateway_generate_link_nonce', $html);
        $this->assertStringContainsString('convoca_gateway_amount', $html);
        $this->assertStringContainsString('convoca_gateway_concepto', $html);
        $this->assertStringContainsString('convoca_gateway_method', $html);
    }

    /**
     * Las validaciones del formulario, sin crear nada.
     *
     * `process_generation()` es privado: se llama por reflexión para no abrir la API pública solo
     * por una prueba. Cada caso comprueba el código de error, que es lo que ve quien rellena.
     */
    public function test_rechaza_importe_por_debajo_del_minimo(): void
    {
        $error = $this->procesar(['convoca_gateway_amount' => '0.10', 'convoca_gateway_concepto' => 'Cuota']);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('invalid_amount', $error->get_error_code());
    }

    public function test_rechaza_concepto_vacio_si_no_es_donativo(): void
    {
        $error = $this->procesar(['convoca_gateway_amount' => '10']);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('missing_concept', $error->get_error_code());
    }

    public function test_rechaza_concepto_demasiado_largo(): void
    {
        $error = $this->procesar([
            'convoca_gateway_amount'   => '10',
            'convoca_gateway_concepto' => str_repeat('a', 126),
        ]);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('long_concept', $error->get_error_code());
    }

    public function test_rechaza_metodo_desconocido(): void
    {
        $error = $this->procesar([
            'convoca_gateway_amount'   => '10',
            'convoca_gateway_concepto' => 'Cuota',
            'convoca_gateway_method'   => 'criptomoneda',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('invalid_method', $error->get_error_code());
    }

    /**
     * Llama al método privado de generación.
     *
     * @param array<string, mixed> $post Datos del formulario.
     * @return mixed
     */
    private function procesar(array $post)
    {
        // Desde PHP 8.1 `setAccessible()` no hace falta (y en 8.5 avisa): el método privado se
        // invoca directamente por reflexión.
        $metodo = new \ReflectionMethod(Admin_Generador::class, 'process_generation');

        return $metodo->invoke(new Admin_Generador(), $post);
    }

    /**
     * La pantalla se registra en el menú de Convoca.
     */
    public function test_se_registra_en_el_menu(): void
    {
        $GLOBALS['__gw_submenus'] = [];

        ( new Admin_Generador() )->add_menu();

        $this->assertArrayHasKey( 'conv-gateway-generador', $GLOBALS['__gw_submenus'] ?? [], 'sin entrada de menú la pantalla no se alcanza' );
    }
}
