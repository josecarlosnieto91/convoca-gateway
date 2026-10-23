<?php

/**
 * La consulta del listado de enlaces y la pantalla de editar un enlace.
 *
 * `prepare_items()` decide qué sale en el listado: solo las **plantillas de enlace** (los pagos con
 * `origin = link_payment`), ordenadas por fecha de creación. Si esa condición se pierde, el listado
 * de enlaces se llena de cobros reales y deja de servir para lo que sirve.
 *
 * `render_edit()` es la pantalla donde se cambia un enlace: empieza por comprobar que el id es de
 * un pago de verdad, y si no lo es lo dice en vez de pintar un formulario roto.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Links;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Admin_Links.php';

class AdminLinksPantallaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true, 'manage_options' => true];
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_posts'] = [];
        $_GET = [];
        $_POST = [];
        \WP_Query::$ultimo = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_meta'], $GLOBALS['__gw_caps'], $GLOBALS['__gw_options'], $GLOBALS['__gw_posts']);
        $_GET = [];
        $_POST = [];
        \WP_Query::$ultimo = [];
    }

    // ── la consulta del listado ─────────────────────────────────────────────────────────

    public function test_el_listado_solo_trae_plantillas_de_enlace(): void
    {
        (new Admin_Links())->prepare_items();

        $args = \WP_Query::$ultimo;

        $this->assertSame('pago', $args['post_type']);
        $this->assertSame(20, $args['posts_per_page']);
        $this->assertSame(1, $args['paged']);

        $condiciones = array_map(
            static fn ($c) => is_array($c) ? array($c['key'] ?? '', $c['value'] ?? '') : $c,
            $args['meta_query']
        );
        $this->assertContains(['_convoca_origin', 'link_payment'], $condiciones, 'un cobro normal no es un enlace');
    }

    public function test_el_listado_ordena_por_fecha_de_creacion(): void
    {
        (new Admin_Links())->prepare_items();

        $args = \WP_Query::$ultimo;

        $this->assertSame('meta_value', $args['orderby']);
        $this->assertSame('_convoca_created_at', $args['meta_key'], 'ordenar por fecha de publicación no es lo mismo');
        $this->assertSame('desc', $args['order']);
    }

    public function test_el_sentido_del_orden_se_puede_cambiar(): void
    {
        $_GET['order'] = 'asc';

        (new Admin_Links())->prepare_items();

        $this->assertSame('asc', \WP_Query::$ultimo['order']);
    }

    // ── la pantalla de editar ──────────────────────────────────────────────────────────

    public function test_un_id_que_no_es_un_pago_no_pinta_formulario(): void
    {
        $GLOBALS['__gw_posts'] = [42 => 'page']; // existe, pero es una página

        ob_start();
        (new Admin_Links())->render_edit(42);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Enlace no encontrado', $html, 'hay que decirlo, no pintar un formulario roto');
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_un_id_cero_tampoco(): void
    {
        ob_start();
        (new Admin_Links())->render_edit(0);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Enlace no encontrado', $html);
    }

    public function test_un_pago_de_verdad_pinta_su_formulario(): void
    {
        $GLOBALS['__gw_posts'] = [7 => 'pago'];
        $GLOBALS['__gw_meta'][7] = [
            '_convoca_order_id' => 'LINK-007',
            '_convoca_link_key' => 'clave-007',
            '_convoca_expires_at' => time() + 86400,
            '_convoca_open_amount' => 0,
            '_convoca_concepto' => 'Donativo',
        ];

        ob_start();
        (new Admin_Links())->render_edit(7);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Editar enlace de pago', $html);
        $this->assertStringContainsString('<form', $html, 'la pantalla es para editar: necesita formulario');
        $this->assertStringContainsString('Volver a los enlaces', $html);
        $this->assertStringNotContainsString('Enlace no encontrado', $html);
    }
}
