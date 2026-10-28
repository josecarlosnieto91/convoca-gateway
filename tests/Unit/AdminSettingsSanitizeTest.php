<?php

/**
 * El saneado de los ajustes del TPV: la última puerta antes de guardar cómo se cobra.
 *
 * `Admin_Settings` tenía 1 de 625 statements cubiertos. El método que importa es `sanitize()`:
 * por ahí pasa el código de comercio, el terminal, el entorno, el IBAN y la clave secreta de
 * Redsys. Una clave mal guardada no da error en pantalla: da cobros que fallan en producción.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Settings;
use Convoca\Gateway\Redsys_Client;
use Convoca\Gateway\Tests\License_Manager_Doble;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Admin_Settings.php';

class AdminSettingsSanitizeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_settings_errors'] = [];
        License_Manager_Doble::$pro = false;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_options'], $GLOBALS['__gw_settings_errors']);
        License_Manager_Doble::$pro = false;
    }

    private function ajustes(): Admin_Settings
    {
        return new Admin_Settings();
    }

    private function errores(): array
    {
        return array_column($GLOBALS['__gw_settings_errors'] ?? [], 'code');
    }

    // ── código de comercio y terminal ─────────────────────────────────────────────────────

    public function test_un_codigo_de_comercio_que_no_son_nueve_digitos_se_rechaza(): void
    {
        $this->ajustes()->sanitize(['merchant_code' => '12345']);

        $this->assertContains('convoca_gateway_validation', $this->errores(), 'un FUC de cinco dígitos no vale');
    }

    public function test_un_codigo_de_comercio_de_nueve_digitos_pasa(): void
    {
        $this->ajustes()->sanitize(['merchant_code' => '123456789']);

        $this->assertNotContains('convoca_gateway_validation', $this->errores());
    }

    public function test_el_terminal_admite_de_uno_a_tres_digitos(): void
    {
        $this->ajustes()->sanitize(['terminal' => '001']);
        $this->assertNotContains('convoca_gateway_validation', $this->errores());

        $GLOBALS['__gw_settings_errors'] = [];
        $this->ajustes()->sanitize(['terminal' => '0001']);
        $this->assertContains('convoca_gateway_validation', $this->errores(), 'cuatro dígitos no son un terminal');
    }

    public function test_sin_terminal_se_queda_el_uno_por_defecto(): void
    {
        $salida = $this->ajustes()->sanitize([]);

        $this->assertSame('001', $salida['terminal'], 'sin dato, el terminal de fábrica');
    }

    // ── entorno y método ──────────────────────────────────────────────────────────────────

    public function test_un_entorno_inventado_cae_a_pruebas(): void
    {
        $salida = $this->ajustes()->sanitize(['environment' => 'produccion-real']);

        $this->assertSame('test', $salida['environment'], 'nunca se pone en producción por accidente');
    }

    public function test_el_entorno_de_produccion_se_respeta_si_se_pide(): void
    {
        $this->assertSame('production', $this->ajustes()->sanitize(['environment' => 'production'])['environment']);
    }

    public function test_un_metodo_por_defecto_inventado_cae_a_cualquiera(): void
    {
        $this->assertSame('any', $this->ajustes()->sanitize(['default_method' => 'bitcoin'])['default_method']);
        $this->assertSame('bizum', $this->ajustes()->sanitize(['default_method' => 'bizum'])['default_method']);
    }

    // ── IBAN ──────────────────────────────────────────────────────────────────────────────

    public function test_un_iban_bien_escrito_se_acepta_aunque_venga_con_espacios(): void
    {
        $this->ajustes()->sanitize(['iban' => 'es91 2100 0418 4502 0005 1332']);

        $this->assertNotContains('convoca_gateway_validation', $this->errores(), 'el IBAN español con espacios es válido');
    }

    public function test_un_iban_imposible_se_rechaza(): void
    {
        $this->ajustes()->sanitize(['iban' => 'ES91']);

        $this->assertContains('convoca_gateway_validation', $this->errores());
    }

    public function test_un_iban_vacio_no_es_un_error(): void
    {
        $this->ajustes()->sanitize(['iban' => '']);

        $this->assertNotContains('convoca_gateway_validation', $this->errores(), 'no tener IBAN no es un error');
    }

    // ── la clave secreta: lo que de verdad duele ──────────────────────────────────────────

    public function test_sin_clave_nueva_se_conserva_la_guardada(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['secret_key' => 'cifrada-vieja'];

        $salida = $this->ajustes()->sanitize(['secret_key' => '']);

        $this->assertSame('cifrada-vieja', $salida['secret_key'], 'guardar la pantalla sin tocar la clave no puede borrarla');
    }

    public function test_una_clave_corta_avisa(): void
    {
        $this->ajustes()->sanitize(['secret_key' => 'corta']);

        $this->assertContains('convoca_gateway_secret_short', $this->errores(), 'menos de 16 caracteres no es una clave');
    }

    public function test_una_clave_buena_se_guarda_cifrada_y_no_en_claro(): void
    {
        $clave  = 'clave-secreta-de-pruebas-123456';
        $salida = $this->ajustes()->sanitize(['secret_key' => $clave]);

        $this->assertNotSame($clave, $salida['secret_key'], 'la clave no puede quedar en claro en la base de datos');
        $this->assertNotSame('', $salida['secret_key']);
        $this->assertSame(0, substr_count($salida['secret_key'], $clave), 'ni contenida dentro');
    }

    // ── el ajuste que depende de la licencia ─────────────────────────────────────────────

    public function test_sin_licencia_pro_el_ajuste_de_recibos_conserva_lo_que_hubiera(): void
    {
        License_Manager_Doble::$pro = false;
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['auto_receipt_fees' => '0'];

        $salida = $this->ajustes()->sanitize([]);

        $this->assertSame('0', $salida['auto_receipt_fees'], 'el campo ni se pinta: no se puede apagar por guardar otra cosa');
    }

    public function test_con_licencia_pro_el_ajuste_de_recibos_hace_caso_al_formulario(): void
    {
        License_Manager_Doble::$pro = true;

        $this->assertSame('1', $this->ajustes()->sanitize(['auto_receipt_fees' => '1'])['auto_receipt_fees']);
        $this->assertSame('0', $this->ajustes()->sanitize([])['auto_receipt_fees'], 'desmarcado es 0');
    }

    // ── plazos ───────────────────────────────────────────────────────────────────────────

    public function test_los_plazos_nunca_quedan_en_cero(): void
    {
        $salida = $this->ajustes()->sanitize(['link_expiry_days' => 0, 'link_expiry_notice_hours' => 0]);

        $this->assertSame(1, $salida['link_expiry_days'], 'un enlace que caduca en cero días no existe');
        $this->assertSame(1, $salida['link_expiry_notice_hours']);
    }

    public function test_los_plazos_buenos_se_respetan(): void
    {
        $salida = $this->ajustes()->sanitize(['link_expiry_days' => 15, 'link_expiry_notice_hours' => 48]);

        $this->assertSame(15, $salida['link_expiry_days']);
        $this->assertSame(48, $salida['link_expiry_notice_hours']);
    }

    // ── casillas ─────────────────────────────────────────────────────────────────────────

    public function test_las_casillas_ausentes_son_cero(): void
    {
        $salida = $this->ajustes()->sanitize([]);

        $this->assertSame('0', $salida['email_confirmation']);
        $this->assertSame('0', $salida['recurring_enabled']);
    }

    public function test_las_casillas_marcadas_son_uno(): void
    {
        $salida = $this->ajustes()->sanitize(['email_confirmation' => '1', 'recurring_enabled' => '1']);

        $this->assertSame('1', $salida['email_confirmation']);
        $this->assertSame('1', $salida['recurring_enabled']);
    }

    // ── el aviso de la clave que no se pudo descifrar ────────────────────────────────────

    public function test_el_aviso_de_clave_ilegible_solo_sale_cuando_toca(): void
    {
        ob_start();
        $this->ajustes()->secret_key_warning();
        $sin_aviso = (string) ob_get_clean();
        $this->assertSame('', $sin_aviso, 'sin problema no se avisa de nada');

        $GLOBALS['__gw_options']['convoca_gateway_secret_needs_reentry'] = true;
        ob_start();
        $this->ajustes()->secret_key_warning();
        $con_aviso = (string) ob_get_clean();

        $this->assertStringContainsString('vuelve a introducir la clave secreta', $con_aviso);
        $this->assertStringContainsString('page=conv-gateway-settings', $con_aviso, 'el aviso lleva a donde se arregla');
    }

    // ── instalación con los datos del TPV fijados (la demo) ──────────────────────────────

    public function test_por_defecto_los_datos_del_tpv_se_pueden_cambiar(): void
    {
        $this->assertFalse(Admin_Settings::settings_locked(), 'una instalación normal no bloquea nada');
    }

    public function test_con_el_filtro_puesto_los_datos_del_tpv_quedan_fijados(): void
    {
        add_filter('convoca_gateway_settings_locked', '__return_true');

        $this->assertTrue(Admin_Settings::settings_locked());
        $this->assertSame(
            ['merchant_code', 'terminal', 'secret_key', 'bizum_merchant_code', 'environment'],
            Admin_Settings::locked_fields()
        );
    }

    public function test_bloqueado_no_se_pisa_el_comercio_ni_el_entorno(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = [
            'merchant_code' => '999008881',
            'terminal'      => '100',
            'environment'   => 'test',
            'secret_key'    => 'enc:loquesea',
        ];
        add_filter('convoca_gateway_settings_locked', '__return_true');

        $salida = $this->ajustes()->sanitize([
            'merchant_code' => '161197496',
            'terminal'      => '001',
            'environment'   => 'production',
            'secret_key'    => 'clave-nueva-que-no-debe-entrar',
        ]);

        $this->assertSame('999008881', $salida['merchant_code'], 'el comercio no se toca');
        $this->assertSame('100', $salida['terminal'], 'el terminal no se toca');
        $this->assertSame('test', $salida['environment'], 'la demo no salta a producción');
        $this->assertSame('enc:loquesea', $salida['secret_key'], 'la clave se queda como estaba');
    }
}
