<?php
/**
 * Unit tests for Convoca Gateway — Diagnostic checks.
 */

namespace Convoca\Gateway\Tests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Diagnostic.php';

class DiagnosticTest extends TestCase
{
    public function test_check_php_version_returns_array(): void
    {
        $result = \Convoca\Gateway\Diagnostic::check_php_version();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('severity', $result);
    }

    public function test_check_php_version_current_is_ok(): void
    {
        $result = \Convoca\Gateway\Diagnostic::check_php_version();
        // PHP 8.1+ should be ok
        if (version_compare(PHP_VERSION, '7.4', '>=')) {
            $this->assertEquals('ok', $result['severity']);
        }
    }

    public function test_check_openssl_returns_array(): void
    {
        $result = \Convoca\Gateway\Diagnostic::check_openssl();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('severity', $result);
        // On this system, openssl should be available
        if (extension_loaded('openssl')) {
            $this->assertEquals('ok', $result['severity']);
        }
    }

    public function test_has_errors_detects_errors(): void
    {
        $results = [
            ['severity' => 'ok'],
            ['severity' => 'warning'],
            ['severity' => 'error'],
        ];
        $this->assertTrue(\Convoca\Gateway\Diagnostic::has_errors($results));
    }

    public function test_has_errors_no_errors(): void
    {
        $results = [
            ['severity' => 'ok'],
            ['severity' => 'warning'],
        ];
        $this->assertFalse(\Convoca\Gateway\Diagnostic::has_errors($results));
    }

    public function test_has_warnings_detects_warnings(): void
    {
        $results = [
            ['severity' => 'ok'],
            ['severity' => 'warning'],
        ];
        $this->assertTrue(\Convoca\Gateway\Diagnostic::has_warnings($results));
    }

    public function test_has_warnings_no_warnings(): void
    {
        $results = [
            ['severity' => 'ok'],
        ];
        $this->assertFalse(\Convoca\Gateway\Diagnostic::has_warnings($results));
    }

    public function test_check_environment_returns_array(): void
    {
        $result = \Convoca\Gateway\Diagnostic::check_environment();
        $this->assertIsArray($result);
    }

    public function test_run_all_returns_array(): void
    {
        $result = \Convoca\Gateway\Diagnostic::run_all(false);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }
}
