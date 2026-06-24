<?php
/**
 * Integration tests for Payment_Handler — payment flow.
 * Requires WordPress (tested in CI).
 */

namespace Convoca\Gateway\Tests;

class PaymentHandlerIntegrationTest extends \WP_UnitTestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('Convoca\Gateway\Payment_Handler'));
    }

    public function test_has_process_notification_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\Payment_Handler', 'process_notification'));
    }

    public function test_has_process_payment_method(): void
    {
        $this->assertTrue(method_exists('Convoca\Gateway\Payment_Handler', 'process_payment'));
    }

    public function test_has_create_link_payment_method(): void
    {
        // Delegated to CPT_Pago::create_link_payment but accessible
        $this->assertTrue(method_exists('Convoca\Gateway\Payment_Handler', 'create_link_payment'));
    }

    public function test_build_payment_link_returns_valid_url(): void
    {
        $link = \Convoca\Gateway\CPT_Pago::build_payment_link(1, 'test-token');
        $this->assertStringContainsString('convoca-pay=', $link);
        $this->assertStringContainsString('token=', $link);
    }
}
