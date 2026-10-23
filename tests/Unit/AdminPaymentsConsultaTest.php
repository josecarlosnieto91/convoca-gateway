<?php

/**
 * La consulta del listado de pagos: qué se pide a la base de datos según lo que se elija arriba.
 *
 * `prepare_items()` es donde el listado decide qué filas trae: la página, el orden, la búsqueda por
 * pedido, los filtros y —lo más importante— que los **enlaces de pago no salgan aquí**, porque un
 * enlace es una plantilla, no un cobro pendiente. Un error en esa condición enseña como «pendiente»
 * algo que no lo es.
 *
 * El doble de `WP_Query` guarda los argumentos, así que se puede afirmar exactamente qué se pidió.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Payments;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Admin_Payments.php';

class AdminPaymentsConsultaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true, 'manage_options' => true];
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_hijos'] = [];
        $_GET = [];
        \WP_Query::$ultimo = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_caps'], $GLOBALS['__gw_options'], $GLOBALS['__gw_meta'], $GLOBALS['__gw_hijos']);
        $_GET = [];
        \WP_Query::$ultimo = [];
    }

    private function preparar(): array
    {
        (new Admin_Payments())->prepare_items();

        return \WP_Query::$ultimo;
    }

    public function test_pide_solo_pagos_y_de_veinte_en_veinte(): void
    {
        $args = $this->preparar();

        $this->assertSame('pago', $args['post_type']);
        $this->assertSame(20, $args['posts_per_page'], 'el listado va de 20 en 20');
        $this->assertSame(1, $args['paged']);
    }

    public function test_los_enlaces_de_pago_no_salen_en_el_listado_de_cobros(): void
    {
        $args = $this->preparar();

        $this->assertArrayHasKey('convoca_sin_enlaces', $args['meta_query'], 'hay que excluir los enlaces de pago');
        $condicion = $args['meta_query']['convoca_sin_enlaces'];

        $this->assertSame('OR', $condicion['relation']);
        // La condición es «origin != link_payment» O «origin no existe»: los registros antiguos,
        // que no tienen el metadato, no pueden quedarse fuera.
        $comparaciones = array_column($condicion, 'compare');
        $this->assertContains('!=', $comparaciones);
        $this->assertContains('NOT EXISTS', $comparaciones, 'sin el NOT EXISTS, los pagos antiguos desaparecen');
    }

    public function test_por_defecto_ordena_por_fecha_descendente(): void
    {
        $args = $this->preparar();

        $this->assertSame('date', $args['orderby']);
        $this->assertSame('desc', $args['order']);
    }

    public function test_ordenar_por_importe_usa_el_metadato_numerico(): void
    {
        $_GET['orderby'] = 'amount';

        $args = $this->preparar();

        $this->assertSame('meta_value_num', $args['orderby'], 'ordenar importes como texto pone el 9 por encima del 10');
        $this->assertSame('_convoca_amount_cents', $args['meta_key']);
    }

    public function test_se_respeta_el_sentido_elegido(): void
    {
        $_GET['order'] = 'asc';

        $this->assertSame('asc', $this->preparar()['order']);
    }

    public function test_la_busqueda_va_por_el_id_del_pedido(): void
    {
        $_GET['s'] = 'PED-001';

        $args     = $this->preparar();
        $buscadas = array_values(array_filter($args['meta_query'], static fn ($c) => is_array($c) && ($c['key'] ?? '') === '_convoca_order_id'));

        $this->assertNotEmpty($buscadas, 'buscar tiene que buscar por pedido');
        $this->assertSame('LIKE', $buscadas[0]['compare']);
        $this->assertSame('PED-001', $buscadas[0]['value']);
    }

    public function test_sin_busqueda_no_se_filtra_por_pedido(): void
    {
        $args     = $this->preparar();
        $buscadas = array_filter($args['meta_query'], static fn ($c) => is_array($c) && ($c['key'] ?? '') === '_convoca_order_id');

        $this->assertEmpty($buscadas);
    }

    public function test_un_filtro_de_estado_se_aplica(): void
    {
        $_GET['status_filter'] = 'paid';

        $args     = $this->preparar();
        $filtradas = array_values(array_filter($args['meta_query'], static fn ($c) => is_array($c) && ($c['key'] ?? '') === '_convoca_status'));

        $this->assertNotEmpty($filtradas, 'el filtro de estado tiene que llegar a la consulta');
        $this->assertSame('paid', $filtradas[0]['value']);
    }

    public function test_un_filtro_de_metodo_se_aplica(): void
    {
        $_GET['method_filter'] = 'bizum';

        $args      = $this->preparar();
        $filtradas = array_values(array_filter($args['meta_query'], static fn ($c) => is_array($c) && ($c['key'] ?? '') === '_convoca_method'));

        $this->assertNotEmpty($filtradas);
        $this->assertSame('bizum', $filtradas[0]['value']);
    }

    public function test_sin_filtros_no_se_añaden_condiciones_de_mas(): void
    {
        $args = $this->preparar();

        $claves = array_map(static fn ($c) => is_array($c) ? ($c['key'] ?? '') : $c, $args['meta_query']);
        $this->assertNotContains('_convoca_status', $claves, 'sin filtro no se pide un estado concreto');
        $this->assertNotContains('_convoca_method', $claves);
    }

    public function test_las_columnas_quedan_preparadas(): void
    {
        $tabla = new Admin_Payments();
        $tabla->prepare_items();

        // El doble de WP_List_Table no declara la propiedad: prepare_items la crea. Se lee sobre
        // el objeto (ReflectionObject) y no sobre la clase.
        $ref = new \ReflectionObject($tabla)->getProperty('_column_headers');
        $this->assertNotEmpty($ref->getValue($tabla), 'sin cabeceras la tabla no pinta las columnas');
    }
}
