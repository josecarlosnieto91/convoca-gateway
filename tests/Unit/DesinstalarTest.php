<?php

/**
 * Desinstalación del gateway.
 *
 * `uninstall.php` estaba al 0 % (29 statements) y corre en cualquier desinstalación: es el sitio
 * donde se borran las opciones del TPV —incluida la sal de firma— y los pagos del CPT. Lo que se
 * fija aquí: con la guarda de conservar datos NO se borra nada, y sin la guarda se borra todo lo
 * que el plugin escribió (ni media desinstalación, ni datos huérfanos para siempre).
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class DesinstalarTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'convoca-gateway/convoca-gateway.php');
        }

        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_hijos'] = [];
        $GLOBALS['__gw_deleted'] = [];
        $GLOBALS['__gw_sql'] = [];
        $GLOBALS['__gw_cron_limpiados'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__gw_options'],
            $GLOBALS['__gw_hijos'],
            $GLOBALS['__gw_deleted'],
            $GLOBALS['__gw_sql'],
            $GLOBALS['__gw_cron_limpiados']
        );
    }

    /**
     * Ejecuta el desinstalador (se incluye, no `require_once`: hay que poder correrlo por ramas).
     */
    private function desinstalar(): void
    {
        include dirname(__DIR__, 2) . '/uninstall.php';
    }

    /**
     * Siembra las opciones que escribe el plugin.
     */
    private function sembrarOpciones(): void
    {
        foreach ([
            'convoca_gateway_redsys_config',
            'convoca_gateway_db_version',
            'convoca_gateway_notification_retry_limit',
            'convoca_gateway_settings',
            'convoca_gateway_persistent_salt',
            'convoca_gateway_secret_needs_reentry',
        ] as $clave) {
            $GLOBALS['__gw_options'][$clave] = 'x';
        }
    }

    /**
     * Con la guarda de conservar datos no se borra NADA: ni opciones ni cron.
     */
    public function test_con_la_guarda_no_se_borra_nada(): void
    {
        $this->sembrarOpciones();
        $GLOBALS['__gw_options']['convoca_uninstall_keep_data'] = 1;
        $GLOBALS['__gw_hijos'] = [(object) ['ID' => 501]];

        $this->desinstalar();

        $this->assertArrayHasKey('convoca_gateway_persistent_salt', $GLOBALS['__gw_options'], 'la sal de firma sigue ahí');
        $this->assertArrayHasKey('convoca_gateway_settings', $GLOBALS['__gw_options']);
        $this->assertSame([], $GLOBALS['__gw_cron_limpiados'], 'no se toca el cron');
        $this->assertSame([], $GLOBALS['__gw_deleted'], 'no se borra ningún pago');
        $this->assertSame([], $GLOBALS['__gw_sql'], 'no se lanza ni la limpieza de transients');
    }

    /**
     * Sin la guarda: opciones fuera, los cuatro cron limpios y los pagos borrados de verdad.
     */
    public function test_sin_la_guarda_se_borra_lo_del_plugin(): void
    {
        $this->sembrarOpciones();
        $GLOBALS['__gw_hijos'] = [(object) ['ID' => 501], (object) ['ID' => 502]];

        $this->desinstalar();

        foreach ([
            'convoca_gateway_redsys_config',
            'convoca_gateway_db_version',
            'convoca_gateway_notification_retry_limit',
            'convoca_gateway_settings',
            'convoca_gateway_persistent_salt',
            'convoca_gateway_secret_needs_reentry',
        ] as $clave) {
            $this->assertArrayNotHasKey($clave, $GLOBALS['__gw_options'], "la opción {$clave} debería estar borrada");
        }

        $this->assertSame(
            [
                'convoca_gateway_cleanup_pending',
                'convoca_gateway_retry_notifications',
                'convoca_gateway_expiry_notice',
                'convoca_gateway_pending_reminder',
            ],
            $GLOBALS['__gw_cron_limpiados'],
            'los dos cron vivos y los dos de versiones antiguas'
        );

        $this->assertSame(
            [
                ['id' => 501, 'force' => true],
                ['id' => 502, 'force' => true],
            ],
            $GLOBALS['__gw_deleted'],
            'los pagos se borran forzados (uno en la papelera sigue en la base)'
        );
        $this->assertNotEmpty($GLOBALS['__gw_sql'], 'los transients del plugin se limpian');
        $this->assertStringContainsString('_transient_convoca_gateway_%', $GLOBALS['__gw_sql'][0]);
    }
}
