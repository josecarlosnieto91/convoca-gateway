<?php
/**
 * Unit tests for CPT_Pago — pure functions (no WP needed).
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class CPTPagoTest extends TestCase
{
    private function loadClass(): void
    {
        $path = dirname(__DIR__, 2) . '/includes/CPT_Pago.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    protected function setUp(): void
    {
        $this->loadClass();
    }

    // ── format_amount ──────────────────────────────

    public function test_format_amount_zero(): void
    {
        $this->assertSame('0,00 €', \Convoca\Gateway\CPT_Pago::format_amount(0));
    }

    public function test_format_amount_one_cent(): void
    {
        $this->assertSame('0,01 €', \Convoca\Gateway\CPT_Pago::format_amount(1));
    }

    public function test_format_amount_one_euro(): void
    {
        $this->assertSame('1,00 €', \Convoca\Gateway\CPT_Pago::format_amount(100));
    }

    public function test_format_amount_with_decimals(): void
    {
        $this->assertSame('12,50 €', \Convoca\Gateway\CPT_Pago::format_amount(1250));
    }

    public function test_format_amount_large(): void
    {
        $this->assertSame('1.500,00 €', \Convoca\Gateway\CPT_Pago::format_amount(150000));
    }

    public function test_format_amount_negative(): void
    {
        $this->assertSame('-50,00 €', \Convoca\Gateway\CPT_Pago::format_amount(-5000));
    }

    // ── Class structure ────────────────────────────

    public function test_class_has_status_constants(): void
    {
        $this->assertIsArray(\Convoca\Gateway\CPT_Pago::STATUS);
        $this->assertNotEmpty(\Convoca\Gateway\CPT_Pago::STATUS);
    }

    public function test_class_has_badge_constants(): void
    {
        $this->assertIsArray(\Convoca\Gateway\CPT_Pago::BADGE);
    }

    public function test_class_has_format_amount_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\CPT_Pago', 'format_amount'));
    }

    public function test_class_has_create_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\CPT_Pago', 'create'));
    }

    public function test_class_has_find_by_order_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\CPT_Pago', 'find_by_order'));
    }

    public function test_class_has_build_payment_link_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\CPT_Pago', 'build_payment_link'));
    }
}
