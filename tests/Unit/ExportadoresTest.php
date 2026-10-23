<?php

/**
 * Los dos exportadores del historial de pagos: el CSV y el PDF.
 *
 * `export()` de los dos termina en `exit` y no se puede recorrer en una prueba. Lo que sí se
 * recorría mal era lo de dentro: hasta ahora había **0 de 90** statements cubiertos en el PDF y
 * 12 de 57 en el CSV, con pruebas que solo miraban dos ayudantes. La decisión que importa —qué
 * columnas salen, cómo se formatea el importe, qué pasa con un historial vacío— se ha sacado a
 * `PDF_Exporter::build_document()` y `CSV_Exporter::filas()`, que sí se pueden ejercitar.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\CSV_Exporter;
use Convoca\Gateway\PDF_Exporter;
use PHPUnit\Framework\TestCase;

class ExportadoresTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_meta'], $GLOBALS['__gw_caps']);
    }

    /**
     * Un pago con sus metadatos, sin prefijo en las claves.
     *
     * @param int                  $id   Identificador.
     * @param array<string, mixed> $meta Metadatos.
     */
    private function pago(int $id, array $meta): \WP_Post
    {
        foreach ($meta as $clave => $valor) {
            $GLOBALS['__gw_meta'][$id]['_convoca_' . $clave] = $valor;
        }

        return new \WP_Post($id);
    }

    // ── el CSV: lo que va en cada columna ──────────────────────────────────────────────

    public function test_una_fila_del_csv_lleva_el_importe_en_coma_decimal(): void
    {
        $post = $this->pago(10, [
            'order_id' => 'PED-10',
            'amount_cents' => 12345,
            'method' => 'tarjeta',
            'status' => 'paid',
            'origin' => 'enroll',
            'origin_id' => 0,
            'created_at' => '2026-01-01 10:00:00',
            'paid_at' => '2026-01-01 10:05:00',
        ]);

        $filas = iterator_to_array(CSV_Exporter::filas([$post]));

        $this->assertCount(1, $filas);
        $this->assertSame(10, $filas[0][0]);
        $this->assertSame('PED-10', $filas[0][1]);
        $this->assertSame('123,45', $filas[0][2], 'una hoja de cálculo española espera coma decimal');
        $this->assertSame('Tarjeta', $filas[0][3], 'el método va con mayúscula, como en el listado');
        $this->assertSame('Pagado', $filas[0][4]);
        $this->assertSame('Inscripción', $filas[0][5]);
        $this->assertSame('2026-01-01 10:00:00', $filas[0][7]);
        $this->assertSame('2026-01-01 10:05:00', $filas[0][8]);
    }

    public function test_un_pago_sin_fecha_de_cobro_sale_con_guion(): void
    {
        $post = $this->pago(11, [
            'order_id' => 'PED-11',
            'amount_cents' => 500,
            'method' => 'bizum',
            'status' => 'pending',
            'origin' => 'members',
            'origin_id' => 0,
            'created_at' => '2026-01-02 09:00:00',
            'paid_at' => '',
        ]);

        $filas = iterator_to_array(CSV_Exporter::filas([$post]));

        $this->assertSame('—', $filas[0][8], 'un pendiente no puede inventarse una fecha de pago');
        $this->assertSame('Socio/a', $filas[0][5]);
    }

    public function test_un_estado_desconocido_no_vacía_la_columna(): void
    {
        $post = $this->pago(12, [
            'order_id' => 'PED-12',
            'amount_cents' => 100,
            'method' => 'tarjeta',
            'status' => 'estado-raro',
            'origin' => 'enroll',
            'origin_id' => 0,
            'created_at' => '2026-01-03 09:00:00',
            'paid_at' => '',
        ]);

        $filas = iterator_to_array(CSV_Exporter::filas([$post]));

        $this->assertSame('estado-raro', $filas[0][4], 'mejor el código que una celda vacía');
    }

    public function test_sin_pagos_no_salen_filas(): void
    {
        $this->assertSame([], iterator_to_array(CSV_Exporter::filas([])));
    }

    public function test_la_fila_lleva_el_correo_del_origen(): void
    {
        $post = $this->pago(13, [
            'order_id' => 'PED-13',
            'amount_cents' => 100,
            'method' => 'tarjeta',
            'status' => 'paid',
            'origin' => 'members',
            'origin_id' => 13,
            'created_at' => '2026-01-04 09:00:00',
            'paid_at' => '2026-01-04 09:01:00',
        ]);
        $GLOBALS['__gw_meta'][13]['_convoca_email'] = 'socia@example.com';

        $filas = iterator_to_array(CSV_Exporter::filas([$post]));

        $this->assertSame('socia@example.com', $filas[0][6]);
    }

    // ── la guarda de la exportación CSV ───────────────────────────────────────────────

    public function test_sin_permiso_no_se_exporta_el_csv(): void
    {
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => false];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tienes permisos');

        CSV_Exporter::export([]);
    }

    // ── el PDF: el documento montado ──────────────────────────────────────────────────

    public function test_el_documento_del_pdf_lleva_la_cabecera_y_el_total(): void
    {
        $uno = $this->pago(20, [
            'order_id' => 'PED-20',
            'amount_cents' => 5000,
            'method' => 'tarjeta',
            'status' => 'paid',
            'origin' => 'enroll',
            'origin_id' => 20,
            'created_at' => '2026-01-05 10:00:00',
            'paid_at' => '2026-01-05 10:02:00',
        ]);
        $dos = $this->pago(21, [
            'order_id' => 'PED-21',
            'amount_cents' => 2500,
            'method' => 'bizum',
            'status' => 'pending',
            'origin' => 'members',
            'origin_id' => 21,
            'created_at' => '2026-01-06 10:00:00',
            'paid_at' => '',
        ]);
        $GLOBALS['__gw_meta'][20]['_convoca_email'] = 'una@example.com';
        $GLOBALS['__gw_meta'][21]['_convoca_email'] = 'otra@example.com';

        $html = PDF_Exporter::build_document([$uno, $dos]);

        $this->assertStringContainsString('Entidad de prueba', $html, 'el documento dice de qué entidad es');
        $this->assertStringContainsString('2 pagos', $html, 'y cuántos pagos lleva');
        $this->assertStringContainsString('75,00', $html, 'el total suma los dos importes, en coma decimal');
        $this->assertStringContainsString('PED-20', $html);
        $this->assertStringContainsString('PED-21', $html);
        $this->assertStringContainsString('50,00', $html);
        $this->assertStringContainsString('Tarjeta', $html);
        $this->assertStringContainsString('Bizum', $html);
        $this->assertStringContainsString('una@example.com', $html);
        $this->assertStringContainsString('—', $html, 'el pendiente no tiene fecha de cobro');
        $this->assertStringNotContainsString('No hay pagos registrados', $html);
    }

    public function test_un_historial_vacio_lo_dice_en_el_pdf(): void
    {
        $html = PDF_Exporter::build_document([]);

        $this->assertStringContainsString('No hay pagos registrados', $html);
        $this->assertStringContainsString('0 pagos', $html);
        $this->assertStringContainsString('0,00', $html, 'un documento sin pagos no puede decir un total inventado');
    }

    public function test_sin_permiso_no_se_exporta_el_pdf(): void
    {
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => false];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tienes permisos');

        PDF_Exporter::export([]);
    }

    public function test_sin_la_libreria_dompdf_el_pdf_avisa(): void
    {
        if (class_exists('\Dompdf\Dompdf')) {
            $this->markTestSkipped('Dompdf está instalado en este entorno: la rama del aviso no es alcanzable.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dompdf');

        PDF_Exporter::export([]);
    }
}
