<?php

/**
 * El resumen de pagos del escritorio de WordPress.
 *
 * Este fichero estaba al 0 % (138 statements): nadie lo había tocado nunca. Y es de los que
 * enseñan números de dinero, así que un fallo aquí no rompe nada visible: da una cifra falsa con
 * toda la confianza. Lo que se prueba es la agregación (totales, pagos de hoy, mezcla de métodos,
 * últimos siete días), la caché y quién puede verlo.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Dashboard_Widget;
use Convoca\Gateway\CPT_Pago;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Dashboard_Widget.php';

class DashboardWidgetTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_transients'] = [];
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_get_col'] = [];
        $GLOBALS['__gw_results'] = [];
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true];
        $GLOBALS['__gw_widgets'] = [];
        $GLOBALS['__gw_meta_cache'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__gw_transients'],
            $GLOBALS['__gw_meta'],
            $GLOBALS['__gw_get_col'],
            $GLOBALS['__gw_results'],
            $GLOBALS['__gw_caps'],
            $GLOBALS['__gw_widgets'],
            $GLOBALS['__gw_meta_cache']
        );
    }

    private function pintar(): string
    {
        ob_start();
        (new Dashboard_Widget())->render_widget();

        return (string) ob_get_clean();
    }

    /**
     * Deja dos pagos cobrados: uno hoy con tarjeta y otro este mes con Bizum.
     */
    private function conDosPagos(): void
    {
        $GLOBALS['__gw_get_col'] = [101, 102];
        $GLOBALS['__gw_meta'] = [
            101 => [
                '_convoca_amount_cents' => '2500',
                '_convoca_method' => 'tarjeta',
                '_convoca_paid_at' => gmdate('Y-m-d H:i:s'),
            ],
            102 => [
                '_convoca_amount_cents' => '7500',
                '_convoca_method' => 'bizum',
                '_convoca_paid_at' => gmdate('Y-m-d H:i:s', strtotime('-3 days')),
            ],
        ];
    }

    // ── quién lo ve ────────────────────────────────────────────────────────────────────────

    public function test_sin_permiso_no_se_registra_el_widget(): void
    {
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => false];

        (new Dashboard_Widget())->register_widget();

        $this->assertArrayNotHasKey('convoca_gateway_payment_summary', $GLOBALS['__gw_widgets'], 'quien no ve pagos no ve el resumen');
    }

    public function test_con_permiso_el_widget_se_registra(): void
    {
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true];

        (new Dashboard_Widget())->register_widget();

        $this->assertArrayHasKey('convoca_gateway_payment_summary', $GLOBALS['__gw_widgets'], 'el resumen es del escritorio');
    }

    // ── la agregación ─────────────────────────────────────────────────────────────────────

    public function test_sin_pagos_todo_a_cero_y_sin_barras(): void
    {
        $GLOBALS['__gw_get_col'] = [];

        $html = $this->pintar();

        $this->assertStringContainsString('Total este mes', $html);
        $this->assertStringContainsString('0,00', $html, 'sin pagos el total es cero, no vacío');
        $this->assertStringNotContainsString('NaN', $html, 'un cero no puede convertirse en NaN');
    }

    public function test_suma_los_importes_del_mes_y_cuenta_los_de_hoy(): void
    {
        $this->conDosPagos();

        $html = $this->pintar();

        // 2.500 + 7.500 = 100,00 €
        $this->assertStringContainsString('100,00', $html, 'el total del mes suma los dos pagos');
        $this->assertSame([101, 102], $GLOBALS['__gw_meta_cache'][0][1] ?? null, 'se precarga la meta de los pagos');
    }

    public function test_la_mezcla_de_metodos_se_calcula_en_porcentaje(): void
    {
        $this->conDosPagos();

        $html = $this->pintar();

        // Un pago de cada: 50 % y 50 %.
        $this->assertStringContainsString('50%', $html, 'la mezcla tarjeta/bizum sale en porcentaje');
        $this->assertStringContainsString('Tarjeta', $html);
        $this->assertStringContainsString('Bizum', $html);
    }

    public function test_los_ultimos_siete_dias_siempre_son_siete_filas(): void
    {
        $this->conDosPagos();

        $html = $this->pintar();

        // Siete filas aunque solo haya pagos en dos días: la gráfica no puede tener huecos.
        $this->assertSame(7, substr_count($html, 'class="conv-gateway-bar-container"'), 'siete días, siete barras (la del <style> no cuenta)');
    }

    public function test_un_pago_con_metodo_desconocido_no_rompe_los_porcentajes(): void
    {
        $GLOBALS['__gw_get_col'] = [101, 102];
        $GLOBALS['__gw_meta'] = [
            101 => ['_convoca_amount_cents' => '1000', '_convoca_method' => 'transferencia', '_convoca_paid_at' => ''],
            102 => ['_convoca_amount_cents' => '1000', '_convoca_method' => 'tarjeta', '_convoca_paid_at' => ''],
        ];

        $html = $this->pintar();

        $this->assertStringContainsString('100%', $html, 'solo cuenta la tarjeta: el método raro no suma en el total de métodos');
        $this->assertStringNotContainsString('NaN', $html);
    }

    // ── la caché ──────────────────────────────────────────────────────────────────────────

    public function test_si_hay_caché_no_se_consulta_la_base_de_datos(): void
    {
        $GLOBALS['__gw_transients']['convoca_gateway_dashboard_stats'] = [
            'total_month' => 123456,
            'count_today' => 9,
            'count_month' => 12,
            'methods' => ['tarjeta_pct' => 75, 'bizum_pct' => 25],
            'last_7_days' => [],
        ];
        $GLOBALS['__gw_get_col'] = [999]; // si se consultara, saldría otro número

        $html = $this->pintar();

        $this->assertStringContainsString('1.234,56', $html, 'se usa lo cacheado');
        $this->assertStringContainsString('75%', $html);
    }

    public function test_sin_caché_el_resultado_se_guarda(): void
    {
        $this->conDosPagos();

        $this->pintar();

        $this->assertArrayHasKey('convoca_gateway_dashboard_stats', $GLOBALS['__gw_transients'], 'la primera vez se guarda para no repetir la consulta');
        $guardado = $GLOBALS['__gw_transients']['convoca_gateway_dashboard_stats'];
        $this->assertSame(10000, $guardado['total_month']);
        $this->assertSame(2, $guardado['count_month']);
        $this->assertSame(1, $guardado['count_today'], 'solo uno de los dos pagos es de hoy');
    }

    // ── los enlaces ───────────────────────────────────────────────────────────────────────

    public function test_el_widget_lleva_al_listado_y_a_los_ajustes(): void
    {
        $GLOBALS['__gw_get_col'] = [];

        $html = $this->pintar();

        $this->assertStringContainsString('page=conv-gateway-payments', $html);
        $this->assertStringContainsString('page=conv-gateway-settings', $html);
    }
}
