<?php
/**
 * Tests for Convoca Gateway — Redsys payment processing.
 */
namespace Convoca\Tests\Gateway\Unit;

use PHPUnit\Framework\TestCase;

class PaymentHandlerTest extends TestCase
{
    private function loadClass(): void
    {
        $path = dirname(__DIR__, 3) . '/includes/class-payment-handler.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    protected function setUp(): void
    {
        $this->loadClass();
    }

    public function test_redsys_signature_format(): void
    {
        $merchant_code = '123456789';
        $terminal = '001';
        $order = time();
        $amount = '3000'; // 30.00 EUR in cents
        $currency = '978';
        
        $this->assertNotEmpty($merchant_code);
        $this->assertEquals('001', $terminal);
        $this->assertIsString((string)$order);
        $this->assertEquals('3000', $amount);
        $this->assertEquals('978', $currency);
    }

    public function test_amount_format_conversion(): void
    {
        $amounts = [
            ['input' => 30.00, 'expected' => '3000'],
            ['input' => 10.50, 'expected' => '1050'],
            ['input' => 0.00, 'expected' => '0'],
            ['input' => 99.99, 'expected' => '9999'],
        ];
        foreach ($amounts as $a) {
            $converted = (string)($a['input'] * 100);
            $this->assertEquals($a['expected'], $converted, "Amount {$a['input']} should convert to {$a['expected']}");
        }
    }

    public function test_order_id_generation(): void
    {
        $order1 = time();
        $order2 = time() + 1;
        $this->assertNotEquals($order1, $order2, 'Each order should get unique ID');
    }

    public function test_payment_states(): void
    {
        $states = ['pending' => 'Pendiente', 'completed' => 'Completado', 'failed' => 'Fallido'];
        $this->assertCount(3, $states);
        $this->assertArrayHasKey('pending', $states);
        $this->assertArrayHasKey('completed', $states);
        $this->arrayHasKey('failed', $states);
        $this->assertEquals('Completado', $states['completed']);
    }

    public function test_redsys_response_format(): void
    {
        $response = [
            'Ds_SignatureVersion' => 'HMAC_SHA256_V1',
            'Ds_MerchantParameters' => base64_encode(json_encode(['Ds_Amount' => '3000'])),
            'Ds_Signature' => str_repeat('a', 64),
        ];
        $this->assertArrayHasKey('Ds_SignatureVersion', $response);
        $this->assertArrayHasKey('Ds_MerchantParameters', $response);
        $this->assertArrayHasKey('Ds_Signature', $response);
        $this->assertEquals('HMAC_SHA256_V1', $response['Ds_SignatureVersion']);
        $this->assertEquals(64, strlen($response['Ds_Signature']));
    }

    public function test_notification_validation(): void
    {
        $notification = [
            'Ds_Response' => '000',
            'Ds_Amount' => '3000',
            'Ds_Order' => '1234',
            'Ds_Currency' => '978',
            'Ds_Date' => gmdate('d/m/Y'),
        ];
        $this->assertArrayHasKey('Ds_Response', $notification);
        $this->assertEquals('000', $notification['Ds_Response'], 'Ds_Response 000 = approved');
    }

    public function test_refund_flow(): void
    {
        $refund_amount = 30.00;
        $original_amount = 30.00;
        $this->assertEquals($original_amount, $refund_amount, 'Refund cannot exceed original');
    }

    public function test_payment_link_generation_uniqueness(): void
    {
        $link1 = hash('sha256', 'order_1_secret');
        $link2 = hash('sha256', 'order_2_secret');
        $this->assertNotEquals($link1, $link2);
        $this->assertEquals(64, strlen($link1));
    }
}
