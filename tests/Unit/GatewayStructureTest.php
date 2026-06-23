<?php
/**
 * Unit tests for Convoca Gateway — structural tests (no WP needed).
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class GatewayStructureTest extends TestCase
{
    private function loadClass(string $file): void
    {
        $path = dirname(__DIR__, 2) . "/includes/$file";
        if (file_exists($path)) {
            require_once $path;
        }
    }

    public function test_diagnostic_class_loads(): void
    {
        $this->loadClass('Diagnostic.php');
        $this->assertTrue(class_exists('Convoca\Gateway\Diagnostic'));
    }

    public function test_diagnostic_has_required_methods(): void
    {
        $this->loadClass('Diagnostic.php');
        $this->assertTrue(method_exists('Convoca\Gateway\Diagnostic', 'run_all'));
        $this->assertTrue(method_exists('Convoca\Gateway\Diagnostic', 'has_errors'));
        $this->assertTrue(method_exists('Convoca\Gateway\Diagnostic', 'has_warnings'));
        $this->assertTrue(method_exists('Convoca\Gateway\Diagnostic', 'check_environment'));
        $this->assertTrue(method_exists('Convoca\Gateway\Diagnostic', 'check_php_version'));
        $this->assertTrue(method_exists('Convoca\Gateway\Diagnostic', 'check_openssl'));
    }

    public function test_payment_handler_class_loads(): void
    {
        $this->loadClass('Payment_Handler.php');
        $this->assertTrue(class_exists('Convoca\Gateway\Payment_Handler'));
    }

    public function test_redsys_client_class_loads(): void
    {
        $this->loadClass('Redsys_Client.php');
        $this->assertTrue(class_exists('Convoca\Gateway\Redsys_Client'));
    }

    public function test_csv_exporter_class_loads(): void
    {
        $this->loadClass('CSV_Exporter.php');
        $this->assertTrue(class_exists('Convoca\Gateway\CSV_Exporter'));
    }

    public function test_admin_settings_class_loads(): void
    {
        $this->loadClass('Admin_Settings.php');
        $this->assertTrue(class_exists('Convoca\Gateway\Admin_Settings'));
    }
}
