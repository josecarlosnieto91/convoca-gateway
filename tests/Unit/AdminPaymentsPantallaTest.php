<?php

/**
 * La pantalla de pagos: listado, detalle y acciones de fila.
 *
 * `render_page()` es el enrutador de la pantalla —confirmación de borrado, detalle o
 * listado— y `render_detail()` la ficha del pago con sus cuatro acciones (reenviar el
 * correo, reembolsar, marcar pagado a mano y marcar donación). Estaba casi todo sin
 * cubrir: 122 de 370 statements. Lo que se comprueba aquí es la decisión, no el HTML:
 * qué rama se toma, qué metadato se escribe y qué gancho sale.
 *
 * El listado en sí (`prepare_items`) y las columnas ya tienen sus propias pruebas
 * (`AdminPaymentsConsultaTest`, `AdminPaymentsColumnasTest`); aquí solo se miran las
 * variantes que faltaban (orden por importe, búsqueda, filtros) y la pantalla.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Core\Logger;
use Convoca\Gateway\Admin_Payments;
use PHPUnit\Framework\TestCase;

class AdminPaymentsPantallaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_posts'] = [];
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true, 'manage_options' => true];
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_hooks'] = [];
        $GLOBALS['__gw_menus'] = [];
        $GLOBALS['__gw_submenus'] = [];
        $GLOBALS['__gw_actions'] = [];
        $GLOBALS['__gw_busquedas'] = [];
        $GLOBALS['__gw_paginacion'] = [];
        $GLOBALS['__gw_list_table_args'] = [];
        $GLOBALS['__gw_nonce_ok'] = true;
        unset($GLOBALS['__gw_submit_buttons']);
        Logger::$escritos = [];
        Logger::$logs = [];
        \WP_Query::$ultimo = [];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__gw_meta'],
            $GLOBALS['__gw_posts'],
            $GLOBALS['__gw_caps'],
            $GLOBALS['__gw_options'],
            $GLOBALS['__gw_hooks'],
            $GLOBALS['__gw_menus'],
            $GLOBALS['__gw_submenus'],
            $GLOBALS['__gw_actions'],
            $GLOBALS['__gw_busquedas'],
            $GLOBALS['__gw_paginacion'],
            $GLOBALS['__gw_list_table_args'],
            $GLOBALS['__gw_nonce_ok'],
            $GLOBALS['__gw_submit_buttons']
        );
        Logger::$escritos = [];
        Logger::$logs = [];
        \WP_Query::$ultimo = [];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    /**
     * Prepara un pago con metadatos, sin prefijo en las claves.
     *
     * @param int                  $id   Identificador del pago.
     * @param array<string, mixed> $meta Metadatos.
     */
    private function pago(int $id, array $meta = []): void
    {
        $GLOBALS['__gw_posts'][$id] = 'pago';
        foreach ($meta as $clave => $valor) {
            $GLOBALS['__gw_meta'][$id]['_convoca_' . $clave] = $valor;
        }
    }

    private function pantalla(): Admin_Payments
    {
        $tabla = new Admin_Payments();
        $tabla->init_list_table();

        return $tabla;
    }

    /**
     * Invoca un método privado del listado.
     *
     * @param string $metodo Nombre del método.
     * @param array  $args   Argumentos.
     * @return mixed
     */
    private function privado(string $metodo, array $args = [])
    {
        $ref = new \ReflectionMethod(Admin_Payments::class, $metodo);

        return $ref->invokeArgs(new Admin_Payments(), $args);
    }

    // ── el alta de la pantalla ──────────────────────────────────────────────────────────

    public function test_el_constructor_engancha_las_cuatro_acciones(): void
    {
        new Admin_Payments();

        $ganchos = array_column($GLOBALS['__gw_hooks'], 0);

        $this->assertContains('admin_menu', $ganchos);
        $this->assertContains('admin_init', $ganchos);
        $this->assertContains('admin_post_convoca_gateway_export_payments_pdf', $ganchos, 'el PDF se pide por admin-post');
        $this->assertContains('admin_post_convoca_gateway_refund', $ganchos, 'la devolución se pide por admin-post');
        $this->assertSame(5, count($GLOBALS['__gw_hooks']), 'cinco ganchos, ni uno más');
    }

    public function test_la_tabla_se_construye_con_su_slug(): void
    {
        (new Admin_Payments())->init_list_table();

        $args = end($GLOBALS['__gw_list_table_args']);

        $this->assertSame('pago', $args['singular']);
        $this->assertSame('pagos', $args['plural']);
        $this->assertSame('conv-gateway-payments', $args['screen']);
        $this->assertFalse($args['ajax']);
    }

    public function test_el_menu_de_pagos_se_registra_con_su_capacidad(): void
    {
        (new Admin_Payments())->add_menu();

        $this->assertArrayHasKey('conv-gateway-payments', $GLOBALS['__gw_menus']);
        $this->assertSame('convoca_view_payments', $GLOBALS['__gw_menus']['conv-gateway-payments']['capability'], 'sin capacidad se entra sin permiso');
        $this->assertArrayHasKey('conv-gateway-payments', $GLOBALS['__gw_submenus']);
    }

    // ── las guardas de las exportaciones ────────────────────────────────────────────────

    public function test_una_peticion_que_no_es_de_exportar_no_exporta(): void
    {
        $_GET = ['page' => 'otra-cosa', 'action' => 'export_csv'];
        $tabla = $this->pantalla();

        ob_start();
        $tabla->handle_csv_export_early();
        $salida = (string) ob_get_clean();

        $this->assertSame('', $salida, 'no debe llegar a exportar ni escribir nada');
    }

    public function test_el_exportar_por_get_sin_la_accion_tampoco(): void
    {
        $_GET = ['page' => 'conv-gateway-payments', 'action' => 'ver'];
        $tabla = $this->pantalla();

        ob_start();
        $tabla->handle_csv_export_early();
        $salida = (string) ob_get_clean();

        $this->assertSame('', $salida);
    }

    public function test_el_pdf_no_se_genera_si_el_nonce_no_vale(): void
    {
        $GLOBALS['__gw_nonce_ok'] = false;
        $tabla = $this->pantalla();

        ob_start();
        $tabla->handle_pdf_export_request();
        $salida = (string) ob_get_clean();

        $this->assertSame('', $salida, 'sin nonce válido no hay documento');
    }

    // ── acciones en bloque ─────────────────────────────────────────────────────────────

    public function test_borrar_se_ofrece_a_quien_puede(): void
    {
        $this->assertSame(['convoca_delete' => 'Eliminar'], $this->privado('get_bulk_actions'));
    }

    public function test_sin_permiso_no_se_ofrece_borrar(): void
    {
        $GLOBALS['__gw_caps'] = ['convoca_manage_payments' => false, 'manage_options' => false];

        $this->assertSame([], $this->privado('get_bulk_actions'), 'una acción que no se puede hacer no se pinta');
    }

    // ── la barra de filtros ────────────────────────────────────────────────────────────

    public function test_los_filtros_solo_salen_arriba(): void
    {
        ob_start();
        $this->privado('extra_tablenav', ['bottom']);
        $html = (string) ob_get_clean();

        $this->assertSame('', $html, 'abajo no se repiten los filtros');
    }

    public function test_arriba_salen_los_filtros_y_la_exportacion(): void
    {
        $_GET = ['status_filter' => 'paid', 'method_filter' => 'bizum', 'origin_filter' => 'enroll'];

        ob_start();
        $this->privado('extra_tablenav', ['top']);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="status_filter"', $html);
        $this->assertStringContainsString('name="method_filter"', $html);
        $this->assertStringContainsString('name="origin_filter"', $html);
        $this->assertStringContainsString("selected='selected'", $html, 'el filtro vigente tiene que salir marcado');
        $this->assertStringContainsString('Exportar CSV', $html);
        $this->assertContains('Filtrar', $GLOBALS['__gw_submit_buttons']);
    }

    // ── la consulta del listado: variantes que faltaban ────────────────────────────────

    public function test_ordenar_por_importe_usa_el_metadato_numerico(): void
    {
        $_GET = ['orderby' => 'amount', 'order' => 'asc'];
        $tabla = $this->pantalla();
        $tabla->prepare_items();

        $args = \WP_Query::$ultimo;

        $this->assertSame('meta_value_num', $args['orderby'], 'ordenar el importe como texto pone 9 € por encima de 100 €');
        $this->assertSame('_convoca_amount_cents', $args['meta_key']);
        $this->assertSame('asc', $args['order']);
    }

    public function test_ordenar_por_cualquier_otra_cosa_es_por_fecha(): void
    {
        $_GET = ['orderby' => 'order_id'];
        $tabla = $this->pantalla();
        $tabla->prepare_items();

        $this->assertSame('date', \WP_Query::$ultimo['orderby'], 'las columnas que no son ordenables no inventan orden');
    }

    public function test_la_busqueda_filtra_por_pedido(): void
    {
        $_GET = ['s' => 'PED-77'];
        $tabla = $this->pantalla();
        $tabla->prepare_items();

        $claves = array_map(
            static fn ($c) => is_array($c) ? ($c['key'] ?? '') : $c,
            \WP_Query::$ultimo['meta_query']
        );

        $this->assertContains('_convoca_order_id', $claves);
    }

    public function test_los_tres_filtros_entran_en_la_consulta(): void
    {
        $_GET = ['status_filter' => 'paid', 'method_filter' => 'tarjeta', 'origin_filter' => 'members'];
        $tabla = $this->pantalla();
        $tabla->prepare_items();

        $claves = array_map(
            static fn ($c) => is_array($c) ? ($c['key'] ?? '') : $c,
            \WP_Query::$ultimo['meta_query']
        );

        $this->assertContains('_convoca_status', $claves);
        $this->assertContains('_convoca_method', $claves);
        $this->assertContains('_convoca_origin', $claves);
    }

    // ── la consulta de las exportaciones ───────────────────────────────────────────────

    public function test_la_exportacion_respeta_busqueda_y_filtros(): void
    {
        $_GET = ['s' => 'PED-1', 'status_filter' => 'paid', 'order' => 'asc'];

        $args = $this->privado('build_export_query_args');

        $this->assertSame('pago', $args['post_type']);
        $this->assertSame('asc', $args['order']);

        $claves = array_map(static fn ($c) => $c['key'], $args['meta_query']);
        $this->assertContains('_convoca_order_id', $claves);
        $this->assertContains('_convoca_status', $claves);
    }

    public function test_la_exportacion_sin_datos_no_lleva_filtros_de_mas(): void
    {
        $args = $this->privado('build_export_query_args');

        $this->assertArrayNotHasKey('meta_query', $args, 'sin filtros no se añade una consulta vacía');
        $this->assertSame('desc', $args['order']);
    }

    // ── el enrutador de la pantalla ────────────────────────────────────────────────────

    public function test_una_accion_de_borrado_lleva_a_la_confirmacion(): void
    {
        $this->pago(12, ['order_id' => 'PED-12', 'status' => 'paid', 'amount_cents' => 3000]);
        $_REQUEST = ['action' => 'convoca_delete', 'id' => 12];
        $_GET = ['action' => 'convoca_delete', 'id' => 12];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Eliminar pagos', $html, 'prudencia antes de borrar');
        $this->assertStringContainsString('PED-12', $html, 'y decir qué se va a borrar');
        $this->assertStringNotContainsString('Pagos Convoca', $html, 'no es el listado');
    }

    public function test_sin_nada_que_borrar_se_dice(): void
    {
        $_REQUEST = ['action' => 'convoca_delete'];
        $_GET = ['action' => 'convoca_delete'];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('No hay nada que eliminar', $html);
    }

    public function test_el_listado_pinta_la_tabla(): void
    {
        $this->pago(21, ['order_id' => 'PED-21', 'status' => 'paid', 'amount_cents' => 1000, 'method' => 'tarjeta', 'origin' => 'enroll', 'created_at' => '2026-01-01 10:00:00']);
        $_GET = ['page' => 'conv-gateway-payments'];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Pagos Convoca', $html);
        $this->assertStringContainsString('wp-list-table', $html);
        $this->assertStringContainsString('Exportar PDF', $html);
        $this->assertContains('Buscar pedido', $GLOBALS['__gw_busquedas'], 'el listado tiene buscador');
        $this->assertSame(20, $GLOBALS['__gw_paginacion']['per_page']);
    }

    // ── el detalle del pago ────────────────────────────────────────────────────────────

    public function test_un_id_que_no_es_un_pago_no_pinta_ficha(): void
    {
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 0];

        ob_start();
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Pago no encontrado');
            $this->pantalla()->render_page();
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertSame('', $html, 'no se pinta media ficha');
    }

    public function test_una_pagina_que_no_es_un_pago_tampoco(): void
    {
        $GLOBALS['__gw_posts'][30] = 'page';
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 30];

        ob_start();
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Pago no encontrado');
            $this->pantalla()->render_page();
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertSame('', $html, 'una página no es un pago: no hay ficha');
    }

    public function test_la_ficha_muestra_los_datos_y_los_eventos(): void
    {
        $this->pago(40, [
            'order_id' => 'PED-40',
            'status' => 'paid',
            'amount_cents' => 5000,
            'method' => 'tarjeta',
            'origin' => 'enroll',
            'origin_id' => 7,
            'product_desc' => 'Curso de setas',
            'redsys_auth_code' => 'AUTH40',
            'redsys_response' => '0000',
            'receipt_number' => 'D-2026-003',
            'receipt_pdf' => 'https://example.test/recibo/40',
            'proof_file' => 'https://example.test/justificante/40.pdf',
        ]);
        Logger::$logs = [
            ['timestamp' => '2026-01-01 10:00', 'level' => 'info', 'message' => 'Pago completado'],
        ];
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 40];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Pago #PED-40', $html);
        $this->assertStringContainsString('Curso de setas', $html);
        $this->assertStringContainsString('AUTH40', $html);
        $this->assertStringContainsString('D-2026-003', $html);
        $this->assertStringContainsString('Ver recibo', $html);
        $this->assertStringContainsString('Ver justificante', $html);
        $this->assertStringContainsString('Pago completado', $html, 'el registro de eventos es lo que hace útil la ficha');
        $this->assertStringContainsString('Ver inscripción original', $html, 'un pago de inscripción enlaza a su inscripción');
        $this->assertStringContainsString('Reembolsado', $html, 'un pago cobrado se puede reembolsar');
    }

    public function test_una_ficha_sin_eventos_lo_dice(): void
    {
        $this->pago(41, ['order_id' => 'PED-41', 'status' => 'pending', 'amount_cents' => 500]);
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 41];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('No hay eventos registrados', $html);
        $this->assertStringContainsString('Confirmar Pago Manual', $html, 'un pago pendiente se puede confirmar a mano');
        $this->assertStringNotContainsString('Reembolsado', $html, 'lo que no está cobrado no se reembolsa');
        $this->assertStringNotContainsString('Ver inscripción original', $html);
    }

    public function test_reenviar_el_correo_lanza_su_gancho(): void
    {
        $this->pago(42, ['order_id' => 'PED-42', 'status' => 'paid', 'amount_cents' => 100]);
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 42];
        $_POST = ['convoca_gateway_resend_email' => '1'];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $ganchos = array_column($GLOBALS['__gw_actions'], 0);
        $this->assertContains('convoca_gateway_resend_email', $ganchos);
        $this->assertStringContainsString('Email reenviado', $html);
    }

    public function test_el_reembolso_devuelve_por_el_tpv(): void
    {
        $this->pago(43, ['order_id' => 'PED-43', 'status' => 'paid', 'amount_cents' => 100, 'origin' => 'members', 'origin_id' => 3]);

        $aprobar = static function () {
            return ['approved' => true, 'response' => '0900', 'order_id' => 'PED-43'];
        };
        add_filter('convoca_gateway_refund_result', $aprobar);

        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 43];
        $_POST = ['convoca_gateway_refund_payment' => '1'];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        remove_filter('convoca_gateway_refund_result', $aprobar);

        $this->assertSame('refunded', $GLOBALS['__gw_meta'][43]['_convoca_status'], 'reembolsar ejecuta la devolución');
        $this->assertSame(100, $GLOBALS['__gw_meta'][43]['_convoca_refunded_cents']);
        $this->assertContains('convoca_gateway_payment_refunded', array_column($GLOBALS['__gw_actions'], 0));
        $this->assertStringContainsString('Devolución ejecutada', $html);
    }

    public function test_el_reembolso_rechazado_no_toca_el_pago(): void
    {
        $this->pago(45, ['order_id' => 'PED-45', 'status' => 'paid', 'amount_cents' => 100, 'origin' => 'members', 'origin_id' => 3]);

        $rechazar = static function () {
            return ['approved' => false, 'response' => '0190', 'order_id' => 'PED-45'];
        };
        add_filter('convoca_gateway_refund_result', $rechazar);

        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 45];
        $_POST = ['convoca_gateway_refund_payment' => '1'];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        remove_filter('convoca_gateway_refund_result', $rechazar);

        $this->assertSame('paid', $GLOBALS['__gw_meta'][45]['_convoca_status'], 'si el TPV rechaza, el pago no cambia');
        $this->assertStringContainsString('No se pudo devolver', $html);
    }

    public function test_marcar_pagado_a_mano_cierra_el_pago(): void
    {
        $this->pago(44, ['order_id' => 'PED-44', 'status' => 'pending', 'amount_cents' => 100, 'origin' => 'enroll', 'origin_id' => 9]);
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 44];
        $_POST = ['convoca_gateway_mark_paid' => '1'];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $this->assertSame('paid', $GLOBALS['__gw_meta'][44]['_convoca_status']);
        $this->assertSame('2025-01-01 00:00:00', $GLOBALS['__gw_meta'][44]['_convoca_paid_at'], 'sin fecha de pago el recibo sale sin fecha');
        $this->assertStringContainsString('PAGADO manualmente', $html);
        $this->assertNotEmpty(\Convoca\Core\Utils::$lanzados, 'el pago completado tiene que avisar al ecosistema');
    }

    public function test_marcar_un_pago_como_donacion(): void
    {
        $this->pago(45, ['order_id' => 'PED-45', 'status' => 'paid', 'amount_cents' => 2000]);
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 45];
        $_POST = ['convoca_gateway_toggle_donacion' => '1', 'convoca_gateway_es_donacion' => '1'];

        ob_start();
        $this->pantalla()->render_page();
        $html = (string) ob_get_clean();

        $this->assertSame('1', $GLOBALS['__gw_meta'][45]['_convoca_es_donacion']);
        $this->assertStringContainsString('donación', $html);
        $this->assertSame('Gateway/Donation', Logger::$escritos[0][2]);
    }

    public function test_desmarcar_la_donacion_lo_deja_en_cero(): void
    {
        $this->pago(46, ['order_id' => 'PED-46', 'status' => 'paid', 'amount_cents' => 2000, 'es_donacion' => '1']);
        $_GET = ['page' => 'conv-gateway-payments-detail', 'id' => 46];
        $_POST = ['convoca_gateway_toggle_donacion' => '1'];

        ob_start();
        $this->pantalla()->render_page();
        ob_end_clean();

        $this->assertSame('0', $GLOBALS['__gw_meta'][46]['_convoca_es_donacion'], 'desmarcar escribe 0, no borra el metadato');
        $this->assertStringContainsString('desmarcado', Logger::$escritos[0][1]);
    }
}
