<?php

/**
 * La notificación de Redsys, el retorno del navegador y las páginas de resultado.
 *
 * Es el corazón del plugin: `process_notification()` decide si un cobro se cierra y con qué
 * comprobaciones (IP, firma, importe, moneda, código de comercio) y `process_return()` hace de red
 * de seguridad cuando el cliente vuelve del TPV. Estaba al 57 % con 447 statements sin cubrir; la
 * mayoría son estas ramas, que hasta ahora no había forma de recorrer.
 *
 * La firma se construye con `Redsys_Client::sign()` sobre los parámetros reales, así que las
 * pruebas usan notificaciones de verdad, no dobles: si la verificación cambiara de forma
 * incompatible, esto se pone en rojo.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Core\Logger;
use Convoca\Gateway\CPT_Pago;
use Convoca\Gateway\Payment_Handler;
use Convoca\Gateway\Redsys_Client;
use PHPUnit\Framework\TestCase;

class PagoNotificacionYRetornoTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_posts'] = [];
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true];
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_get_var'] = null;
        $GLOBALS['__gw_sql'] = [];
        Logger::$escritos = [];
        Logger::$logs = [];
        \Convoca\Core\Utils::$lanzados = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REMOTE_ADDR'] = '195.76.9.10';

        if (!defined('CONVOCA_GATEWAY_SECRET_KEY')) {
            // Clave 3DES de pruebas (24 bytes en base64), la misma que usa RedsysClientTest.
            define('CONVOCA_GATEWAY_SECRET_KEY', base64_encode(str_repeat('A', 24)));
        }
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__gw_meta'],
            $GLOBALS['__gw_posts'],
            $GLOBALS['__gw_caps'],
            $GLOBALS['__gw_options'],
            $GLOBALS['__gw_get_var'],
            $GLOBALS['__gw_sql']
        );
        unset($_SERVER['REMOTE_ADDR']);
        Logger::$escritos = [];
        \Convoca\Core\Utils::$lanzados = [];
        $_GET = [];
        $_POST = [];
    }

    /**
     * Un pago con sus metadatos, sin prefijo en las claves.
     *
     * @param int                  $id   Identificador.
     * @param array<string, mixed> $meta Metadatos.
     */
    private function pago(int $id, array $meta = []): void
    {
        $GLOBALS['__gw_posts'][$id] = 'pago';
        foreach ($meta as $clave => $valor) {
            $GLOBALS['__gw_meta'][$id]['_convoca_' . $clave] = $valor;
        }
    }

    /**
     * Construye una notificación firmada con los parámetros dados.
     *
     * @param array<string, string> $params Parámetros Ds_*.
     * @return array<string, string>
     */
    private function notificacion(array $params): array
    {
        $mp = base64_encode((string) json_encode($params));
        $version = Redsys_Client::signature_version();
        $firma = 'HMAC_SHA256_V2' === $version
            ? Redsys_Client::sign_v2($mp, (string) ($params['Ds_Order'] ?? ''))
            : Redsys_Client::sign($mp, (string) ($params['Ds_Order'] ?? ''));

        return [
            'Ds_SignatureVersion' => $version,
            'Ds_MerchantParameters' => $mp,
            'Ds_Signature' => $firma,
        ];
    }

    /**
     * Parámetros de una notificación coherente con el pago dado.
     *
     * @param int    $amountCents Importe en céntimos.
     * @param string $order       Referencia del pedido.
     * @param string $response    Código de respuesta.
     * @return array<string, string>
     */
    private function parametros(int $amountCents, string $order = 'PED-77', string $response = '0000'): array
    {
        return [
            'Ds_Order' => $order,
            'Ds_Amount' => (string) $amountCents,
            'Ds_Response' => $response,
            'Ds_AuthorisationCode' => 'AUT-1',
            'Ds_Currency' => Redsys_Client::CURRENCY_EUR,
            'Ds_MerchantCode' => Redsys_Client::merchant_code(),
        ];
    }

    // ── qué IPs pueden notificar ──────────────────────────────────────────────────────

    public function test_sin_ip_no_se_admite_la_notificacion(): void
    {
        $this->assertFalse((new Payment_Handler())->is_allowed_origin_ip(''));
        $this->assertFalse((new Payment_Handler())->is_allowed_origin_ip('   '), 'un espacio no es una IP');
    }

    public function test_desde_la_propia_maquina_siempre_se_admite(): void
    {
        $handler = new Payment_Handler();

        $this->assertTrue($handler->is_allowed_origin_ip('127.0.0.1'));
        $this->assertTrue($handler->is_allowed_origin_ip('::1'));
        $this->assertTrue($handler->is_allowed_origin_ip('localhost'));
    }

    public function test_las_ips_del_propio_sitio_se_admiten(): void
    {
        $handler = new Payment_Handler();

        $this->assertTrue($handler->is_allowed_origin_ip('10.0.0.5', ['10.0.0.5']));
        $this->assertFalse($handler->is_allowed_origin_ip('10.0.0.6', ['10.0.0.5']), 'una IP vecina no es el sitio');
    }

    public function test_el_rango_de_redsys_se_admite(): void
    {
        $this->assertTrue((new Payment_Handler())->is_allowed_origin_ip('195.76.9.44'));
    }

    public function test_una_ip_conocida_de_redsys_se_admite(): void
    {
        $this->assertTrue((new Payment_Handler())->is_allowed_origin_ip('193.16.243.33'));
    }

    public function test_una_ip_cualquiera_no_se_admite(): void
    {
        $this->assertFalse((new Payment_Handler())->is_allowed_origin_ip('8.8.8.8'));
    }

    // ── la notificación servidor a servidor ──────────────────────────────────────────

    public function test_una_notificacion_de_una_ip_desconocida_se_rechaza(): void
    {
        $_SERVER['REMOTE_ADDR'] = '8.8.8.8';

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($this->parametros(3000)));

        $this->assertInstanceOf(\WP_Error::class, $resultado);
        $this->assertSame('unauthorized_ip', $resultado->get_error_code());
        $this->assertStringContainsString('IP de origen no autorizada', Logger::$escritos[0][1]);
    }

    public function test_una_firma_manipulada_se_rechaza(): void
    {
        $notificacion = $this->notificacion($this->parametros(3000));
        $notificacion['Ds_Signature'] = base64_encode(str_repeat("\x00", 32));

        $resultado = (new Payment_Handler())->process_notification($notificacion);

        $this->assertInstanceOf(\WP_Error::class, $resultado);
        $this->assertSame('invalid_signature', $resultado->get_error_code());
    }

    public function test_una_firma_invalida_deja_la_referencia_en_el_registro(): void
    {
        $notificacion = $this->notificacion($this->parametros(3000, 'PED-TRAZA'));
        $notificacion['Ds_Signature'] = 'firma-que-no-vale';

        (new Payment_Handler())->process_notification($notificacion);

        $this->assertStringContainsString('PED-TRAZA', Logger::$escritos[0][1], 'sin la referencia el aviso no sirve para buscar nada');
    }

    public function test_una_notificacion_de_un_pedido_que_no_existe_no_toca_nada(): void
    {
        $GLOBALS['__gw_get_var'] = null;

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($this->parametros(3000)));

        $this->assertInstanceOf(\WP_Error::class, $resultado);
        $this->assertSame('order_not_found', $resultado->get_error_code());
        $this->assertContains('ROLLBACK', $GLOBALS['__gw_sql'], 'sin pedido se deshace la transacción abierta');
    }

    public function test_un_pago_ya_cobrado_no_se_vuelve_a_cerrar(): void
    {
        $this->pago(77, ['order_id' => 'PED-77', 'status' => 'paid', 'amount_cents' => 3000]);
        $GLOBALS['__gw_get_var'] = 77;

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($this->parametros(3000)));

        $this->assertTrue($resultado, 'un aviso repetido no es un error');
        $this->assertContains('COMMIT', $GLOBALS['__gw_sql']);
        $this->assertStringContainsString('duplicada', Logger::$escritos[0][1]);
    }

    public function test_un_importe_distinto_al_esperado_se_rechaza(): void
    {
        $this->pago(78, ['order_id' => 'PED-78', 'status' => 'pending', 'amount_cents' => 3000]);
        $GLOBALS['__gw_get_var'] = 78;

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($this->parametros(100)));

        $this->assertInstanceOf(\WP_Error::class, $resultado);
        $this->assertSame('amount_mismatch', $resultado->get_error_code(), 'la firma no prueba que el importe sea el de la cuota');
        $this->assertSame('pending', $GLOBALS['__gw_meta'][78]['_convoca_status'], 'un aviso con importe raro no cambia el estado');
    }

    public function test_una_moneda_distinta_se_rechaza(): void
    {
        $this->pago(79, ['order_id' => 'PED-79', 'status' => 'pending', 'amount_cents' => 3000]);
        $GLOBALS['__gw_get_var'] = 79;
        $params = $this->parametros(3000);
        $params['Ds_Currency'] = '840';

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($params));

        $this->assertSame('currency_mismatch', $resultado->get_error_code());
    }

    public function test_un_codigo_de_comercio_distinto_se_rechaza(): void
    {
        $this->pago(80, ['order_id' => 'PED-80', 'status' => 'pending', 'amount_cents' => 3000]);
        $GLOBALS['__gw_get_var'] = 80;
        $params = $this->parametros(3000);
        $params['Ds_MerchantCode'] = Redsys_Client::merchant_code() . 'OTRO';

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($params));

        $this->assertSame('merchant_mismatch', $resultado->get_error_code());
    }

    public function test_un_cobro_autorizado_cierra_el_pago(): void
    {
        $this->pago(81, [
            'order_id' => 'PED-77',
            'status' => 'pending',
            'amount_cents' => 3000,
            'origin' => 'enroll',
            'origin_id' => 5,
        ]);
        $GLOBALS['__gw_get_var'] = 81;
        $params = $this->parametros(3000);
        $params['Ds_Merchant_Identifier'] = 'token-tarjeta-1';

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($params));

        $this->assertTrue($resultado);
        $this->assertSame('paid', $GLOBALS['__gw_meta'][81]['_convoca_status']);
        $this->assertSame('AUT-1', $GLOBALS['__gw_meta'][81]['_convoca_redsys_auth_code']);
        $this->assertSame('token-tarjeta-1', $GLOBALS['__gw_meta'][81]['_convoca_redsys_merchant_id'], 'sin la referencia de tarjeta no hay cobro recurrente');
        $this->assertContains('COMMIT', $GLOBALS['__gw_sql'], 'sin COMMIT el cobro se perdía al cerrar la conexión');
        $this->assertSame('convoca_gateway_payment_completed', \Convoca\Core\Utils::$lanzados[0][0]);
    }

    public function test_un_cobro_denegado_deja_el_pago_fallido_y_avisa(): void
    {
        $this->pago(82, ['order_id' => 'PED-77', 'status' => 'pending', 'amount_cents' => 3000, 'origin' => 'members', 'origin_id' => 2]);
        $GLOBALS['__gw_get_var'] = 82;

        $resultado = (new Payment_Handler())->process_notification($this->notificacion($this->parametros(3000, 'PED-77', '0190')));

        $this->assertTrue($resultado);
        $this->assertSame('failed', $GLOBALS['__gw_meta'][82]['_convoca_status'], '0190 es denegada por el emisor');
        $this->assertSame('convoca_gateway_payment_failed', \Convoca\Core\Utils::$lanzados[0][0]);
    }

    public function test_la_referencia_de_tarjeta_tambien_se_lee_en_camel_case(): void
    {
        $this->pago(83, ['order_id' => 'PED-77', 'status' => 'pending', 'amount_cents' => 3000, 'origin' => 'enroll', 'origin_id' => 5]);
        $GLOBALS['__gw_get_var'] = 83;
        $params = $this->parametros(3000);
        $params['Ds_MerchantIdentifier'] = 'token-camel';

        (new Payment_Handler())->process_notification($this->notificacion($params));

        $this->assertSame('token-camel', $GLOBALS['__gw_meta'][83]['_convoca_redsys_merchant_id']);
    }

    // ── el retorno del navegador ─────────────────────────────────────────────────────

    public function test_un_retorno_con_firma_mala_no_se_procesa(): void
    {
        $retorno = $this->notificacion($this->parametros(3000));
        $retorno['Ds_Signature'] = 'firma-mala';

        $resultado = (new Payment_Handler())->process_return($retorno);

        $this->assertSame('invalid_signature', $resultado->get_error_code());
    }

    public function test_un_retorno_de_un_pedido_desconocido_no_inventa_el_pago(): void
    {
        $GLOBALS['__gw_hijos'] = [];

        $resultado = (new Payment_Handler())->process_return($this->notificacion($this->parametros(3000)));

        $this->assertSame('order_not_found', $resultado->get_error_code());
    }

    public function test_un_retorno_sobre_un_pago_ya_cobrado_no_duplica_ganchos(): void
    {
        $post = new \WP_Post(90);
        $GLOBALS['__gw_hijos'] = [$post];
        $this->pago(90, ['order_id' => 'PED-77', 'status' => 'paid', 'amount_cents' => 3000]);

        $resultado = (new Payment_Handler())->process_return($this->notificacion($this->parametros(3000)));

        $this->assertTrue($resultado);
        $this->assertSame([], \Convoca\Core\Utils::$lanzados, 'la confirmación se aplica una sola vez');
    }

    public function test_un_retorno_con_importe_distinto_no_confirma(): void
    {
        $GLOBALS['__gw_hijos'] = [new \WP_Post(91)];
        $this->pago(91, ['order_id' => 'PED-77', 'status' => 'pending', 'amount_cents' => 3000]);

        $resultado = (new Payment_Handler())->process_return($this->notificacion($this->parametros(999)));

        $this->assertSame('amount_mismatch', $resultado->get_error_code());
        $this->assertSame('pending', $GLOBALS['__gw_meta'][91]['_convoca_status']);
    }

    public function test_un_retorno_con_comercio_ajeno_no_confirma(): void
    {
        $GLOBALS['__gw_hijos'] = [new \WP_Post(92)];
        $this->pago(92, ['order_id' => 'PED-77', 'status' => 'pending', 'amount_cents' => 3000]);
        $params = $this->parametros(3000);
        $params['Ds_MerchantCode'] = '000000000';

        $resultado = (new Payment_Handler())->process_return($this->notificacion($params));

        $this->assertSame('merchant_mismatch', $resultado->get_error_code());
    }

    public function test_un_retorno_autorizado_confirma_el_pago(): void
    {
        $GLOBALS['__gw_hijos'] = [new \WP_Post(93)];
        $this->pago(93, ['order_id' => 'PED-77', 'status' => 'pending', 'amount_cents' => 3000, 'origin' => 'enroll', 'origin_id' => 8]);

        $resultado = (new Payment_Handler())->process_return($this->notificacion($this->parametros(3000)));

        $this->assertTrue($resultado);
        $this->assertSame('paid', $GLOBALS['__gw_meta'][93]['_convoca_status']);
        $this->assertSame('2025-01-01 00:00:00', $GLOBALS['__gw_meta'][93]['_convoca_paid_at']);
        $this->assertSame('convoca_gateway_payment_completed', \Convoca\Core\Utils::$lanzados[0][0]);
        $this->assertStringContainsString('retorno síncrono', Logger::$escritos[0][1]);
    }

    public function test_un_retorno_denegado_marca_fallido(): void
    {
        $GLOBALS['__gw_hijos'] = [new \WP_Post(94)];
        $this->pago(94, ['order_id' => 'PED-77', 'status' => 'pending', 'amount_cents' => 3000, 'origin' => 'members', 'origin_id' => 2]);

        (new Payment_Handler())->process_return($this->notificacion($this->parametros(3000, 'PED-77', '0180')));

        $this->assertSame('failed', $GLOBALS['__gw_meta'][94]['_convoca_status']);
        $this->assertSame('convoca_gateway_payment_failed', \Convoca\Core\Utils::$lanzados[0][0]);
    }

    // ── las páginas de resultado ─────────────────────────────────────────────────────

    public function test_la_pagina_ok_sin_id_lo_dice(): void
    {
        $html = (new Payment_Handler())->render_ok_page([]);

        $this->assertStringContainsString('ID de pago no especificado', $html);
    }

    public function test_la_pagina_ok_con_un_id_que_no_es_un_pago(): void
    {
        $_GET = ['convoca_gateway_pago' => 500];

        $html = (new Payment_Handler())->render_ok_page([]);

        $this->assertStringContainsString('Pago no encontrado', $html);
    }

    public function test_la_pagina_ok_de_un_pago_pendiente_ofrece_reintentar(): void
    {
        $this->pago(501, ['order_id' => 'PED-501', 'status' => 'pending', 'amount_cents' => 1000, 'link_key' => 'clave-501']);
        $_GET = ['convoca_gateway_pago' => 501];

        $html = (new Payment_Handler())->render_ok_page([]);

        $this->assertStringContainsString('Pago aún no confirmado', $html);
        $this->assertStringContainsString('Intentar completar el pago', $html);
        $this->assertStringNotContainsString('Pago completado', $html, 'no se felicita a nadie por un cobro que no consta');
    }

    public function test_la_pagina_ok_de_un_pago_cobrado_confirma(): void
    {
        $this->pago(502, ['order_id' => 'PED-502', 'status' => 'paid', 'amount_cents' => 2500, 'product_desc' => 'Cuota anual']);
        $_GET = ['convoca_gateway_pago' => 502];

        $html = (new Payment_Handler())->render_ok_page([]);

        $this->assertStringContainsString('Pago completado', $html);
        $this->assertStringContainsString('Cuota anual', $html);
        $this->assertContains('convoca-gateway', $GLOBALS['__gw_enqueued_styles']);
    }

    public function test_la_pagina_ko_explica_el_motivo_y_ofrece_reintentar(): void
    {
        $this->pago(510, ['order_id' => 'PED-510', 'status' => 'failed', 'amount_cents' => 2500, 'redsys_response' => '0190']);
        $_GET = ['convoca_gateway_pago' => 510];

        $html = (new Payment_Handler())->render_ko_page([]);

        $this->assertStringContainsString('Pago no completado', $html);
        $this->assertStringContainsString('Reintentar pago', $html);
        $this->assertStringContainsString('conv-desc', $html, 'con un código de respuesta hay que explicar el motivo');
    }

    public function test_la_pagina_ko_sin_id_no_ofrece_reintentar(): void
    {
        $html = (new Payment_Handler())->render_ko_page([]);

        $this->assertStringContainsString('Pago no completado', $html);
        $this->assertStringNotContainsString('Reintentar pago', $html);
    }
}
