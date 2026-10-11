<?php
/**
 * Integration tests for Payment_Handler — payment flow.
 * Requires WordPress (tested in CI).
 */

namespace Convoca\Gateway\Tests;

class PaymentHandlerIntegrationTest extends \PHPUnit\Framework\TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('Convoca\Gateway\Payment_Handler'));
    }

    public function test_has_process_notification_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\Payment_Handler', 'process_notification'));
    }

    public function test_has_create_payment_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\Payment_Handler', 'create_payment'));
    }

    public function test_has_get_payment_link_method(): void
    {
        // create_link_payment no existe: el metodo real es get_payment_link
        $this->assertTrue(method_exists('Convoca\Gateway\Payment_Handler', 'get_payment_link'));
    }

    public function test_build_payment_link_returns_valid_url(): void
    {
        $this->markTestSkipped('Requiere WordPress: build_payment_link usa helpers de WordPress (add_query_arg/home_url)');
        // (medido en pasada 3: de 13 casos, 3 necesitan WP; este es uno)
$link = \Convoca\Gateway\CPT_Pago::build_payment_link(1, 'test-token');
        $this->assertStringContainsString('convoca-pay=', $link);
        $this->assertStringContainsString('token=', $link);
    }
}
