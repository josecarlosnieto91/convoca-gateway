<?php

/**
 * La consulta que alimenta la exportación y los avisos que se ven al guardar un enlace.
 *
 * Son dos trozos pequeños pero con consecuencias: la exportación tiene que llevarse **lo mismo que
 * se está viendo** (con la búsqueda y los filtros puestos), y el aviso de «no se pudo guardar» tiene
 * que decir por qué —concepto vacío o importe por debajo del mínimo—, no un «error» genérico.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Links;
use Convoca\Gateway\Admin_Payments;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Admin_Payments.php';
require_once dirname(__DIR__, 2) . '/includes/Admin_Links.php';

class AdminExportYFacilitosTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true, 'manage_options' => true];
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_meta'] = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_caps'], $GLOBALS['__gw_options'], $GLOBALS['__gw_meta']);
        $_GET = [];
    }

    private function argsExportacion(): array
    {
        $metodo = new \ReflectionMethod(Admin_Payments::class, 'build_export_query_args');

        return $metodo->invoke(new Admin_Payments());
    }

    private function avisos(): string
    {
        $metodo = new \ReflectionMethod(Admin_Links::class, 'save_notices');
        ob_start();
        $metodo->invoke(null);

        return (string) ob_get_clean();
    }

    // ── la exportación ──────────────────────────────────────────────────────────────────

    public function test_la_exportacion_se_lleva_los_pagos_publicados(): void
    {
        $args = $this->argsExportacion();

        $this->assertSame('pago', $args['post_type']);
        $this->assertSame('publish', $args['post_status'], 'no se exportan borradores');
    }

    public function test_la_exportacion_ordena_por_fecha_de_creacion(): void
    {
        $args = $this->argsExportacion();

        $this->assertSame('meta_value', $args['orderby']);
        $this->assertSame('_convoca_created_at', $args['meta_key']);
        $this->assertSame('desc', $args['order']);
    }

    public function test_la_exportacion_respeta_la_busqueda(): void
    {
        $_GET['s'] = 'PED-777';

        $args      = $this->argsExportacion();
        $buscadas  = array_values(array_filter($args['meta_query'] ?? [], static fn ($c) => ($c['key'] ?? '') === '_convoca_order_id'));

        $this->assertNotEmpty($buscadas, 'exportar con búsqueda tiene que exportar lo buscado');
        $this->assertSame('LIKE', $buscadas[0]['compare']);
    }

    public function test_la_exportacion_respeta_los_filtros_de_estado_metodo_y_origen(): void
    {
        $_GET['status_filter'] = 'paid';
        $_GET['method_filter'] = 'bizum';
        $_GET['origin_filter'] = 'members';

        $claves = array_map(
            static fn ($c) => $c['key'] ?? '',
            $this->argsExportacion()['meta_query'] ?? []
        );

        $this->assertContains('_convoca_status', $claves, 'filtrar y exportar tienen que dar lo mismo');
        $this->assertContains('_convoca_method', $claves);
        $this->assertContains('_convoca_origin', $claves);
    }

    public function test_sin_filtros_la_exportacion_no_se_inventa_condiciones(): void
    {
        $args = $this->argsExportacion();

        $this->assertEmpty($args['meta_query'] ?? [], 'sin filtros se exporta todo');
    }

    // ── los avisos al guardar un enlace ────────────────────────────────────────────────

    public function test_sin_nada_que_avisar_no_se_pinta_nada(): void
    {
        $this->assertSame('', $this->avisos());
    }

    public function test_el_aviso_de_concepto_vacio_dice_que_falta_el_concepto(): void
    {
        $_GET['convoca_save_error'] = 'concepto';

        $html = $this->avisos();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('concepto es obligatorio', $html);
    }

    public function test_el_aviso_de_importe_explica_el_minimo(): void
    {
        $_GET['convoca_save_error'] = 'importe';

        $html = $this->avisos();

        $this->assertStringContainsString('0,50', $html, 'hay que decir el mínimo, no solo que está mal');
        $this->assertStringContainsString('importe libre', $html, 'y cómo se hace un enlace de importe libre');
    }

    public function test_un_error_desconocido_no_deja_el_aviso_en_blanco(): void
    {
        $_GET['convoca_save_error'] = 'loquesea';

        $this->assertStringContainsString('No se pudo guardar', $this->avisos());
    }

    public function test_guardar_bien_se_confirma(): void
    {
        $_GET['convoca_saved'] = '1';

        $html = $this->avisos();

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('Enlace actualizado', $html);
    }
}
