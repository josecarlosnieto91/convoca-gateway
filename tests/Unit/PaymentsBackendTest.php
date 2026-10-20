<?php
/**
 * Pruebas del puente de cobros: el gateway se registra en la fachada del núcleo.
 *
 * Lo que se fija aquí es lo que el núcleo dejó de saber: que este plugin es la pasarela. El núcleo
 * (Core\Payments) pregunta por los filtros `convoca_payment_backend`, `_reader` y `_receipt`, y estos
 * tres enganches son la única respuesta. Si alguien los quita, los cobros se apagan en silencio.
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class PaymentsBackendTest extends TestCase
{
    private function loadClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/Receipt_Generator.php';
        require_once dirname(__DIR__, 2) . '/includes/CPT_Pago.php';
        require_once dirname(__DIR__, 2) . '/includes/Payment_Handler.php';
        require_once dirname(__DIR__, 2) . '/includes/Payments_Backend.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__gw_filtros'] = array();
        $GLOBALS['__gw_meta']    = array();
        $this->loadClass();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_filtros']);
    }

    // ── Registro ─────────────────────────────────

    public function test_register_engancha_los_tres_filtros(): void
    {
        \Convoca\Gateway\Payments_Backend::register();

        $this->assertTrue(has_filter('convoca_payment_backend'));
        $this->assertTrue(has_filter('convoca_payment_reader'));
        $this->assertTrue(has_filter('convoca_payment_receipt'));
    }

    public function test_register_no_engancha_nada_mas(): void
    {
        \Convoca\Gateway\Payments_Backend::register();

        $this->assertCount(3, $GLOBALS['__gw_filtros']);
    }

    // ── Backend ──────────────────────────────────

    public function test_backend_es_el_creador_de_pagos(): void
    {
        $backend = \Convoca\Gateway\Payments_Backend::backend(null);

        $this->assertSame(array('Convoca\Gateway\Payment_Handler', 'create_payment'), $backend);
        $this->assertTrue(is_callable($backend));
    }

    // ── Lector ───────────────────────────────────

    public function test_reader_devuelve_los_metadatos_del_pago(): void
    {
        $GLOBALS['__gw_meta'][42]['_convoca_order_id'] = 'P-42';
        $GLOBALS['__gw_meta'][42]['_convoca_amount_cents'] = 2500;

        $pago = \Convoca\Gateway\Payments_Backend::reader(null, 42);

        $this->assertSame('P-42', $pago['order_id']);
        $this->assertSame(2500, $pago['amount_cents']);
    }

    public function test_reader_ignora_identificadores_invalidos(): void
    {
        $this->assertSame(array(), \Convoca\Gateway\Payments_Backend::reader(null, 0));
        $this->assertSame(array(), \Convoca\Gateway\Payments_Backend::reader(null, -3));
    }

    public function test_reader_respeta_lo_que_ya_leyo_otro_backend_con_id_invalido(): void
    {
        $previo = array('status' => 'pagado');
        $this->assertSame($previo, \Convoca\Gateway\Payments_Backend::reader($previo, 0));
    }

    // ── Recibo ───────────────────────────────────

    public function test_receipt_no_inventa_url_sin_pago(): void
    {
        $this->assertSame('', \Convoca\Gateway\Payments_Backend::receipt('', 0));
        $this->assertSame('https://example.org/ya-estaba', \Convoca\Gateway\Payments_Backend::receipt('https://example.org/ya-estaba', -1));
    }
}
