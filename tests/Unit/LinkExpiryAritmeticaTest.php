<?php

/**
 * La caducidad de los enlaces de pago: valores por defecto y aritmética.
 *
 * Un enlace con una caducidad mal calculada deja de cobrar antes de tiempo (o no caduca nunca).
 * Los tres métodos son puros y estaba media clase sin recorrer.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Link_Expiry;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Link_Expiry.php';

class LinkExpiryAritmeticaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_options']);
    }

    /**
     * Sin configuración, los valores de fábrica: 7 días y 24 horas de aviso.
     */
    public function test_los_valores_de_fabrica(): void
    {
        $this->assertSame(7, Link_Expiry::default_expiry_days());
        $this->assertSame(24, Link_Expiry::notice_hours());
    }

    /**
     * Con configuración, manda la del sitio.
     */
    public function test_la_configuracion_del_sitio_manda(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = [
            'link_expiry_days'          => 30,
            'link_expiry_notice_hours'  => 48,
        ];

        $this->assertSame(30, Link_Expiry::default_expiry_days());
        $this->assertSame(48, Link_Expiry::notice_hours());
    }

    /**
     * Un valor absurdo (0 o negativo) cae al de fábrica en vez de crear un enlace que nace caducado.
     */
    public function test_un_valor_invalido_cae_al_de_fabrica(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = [
            'link_expiry_days'         => 0,
            'link_expiry_notice_hours' => -5,
        ];

        $this->assertSame(7, Link_Expiry::default_expiry_days());
        $this->assertSame(24, Link_Expiry::notice_hours());
    }

    /**
     * La caducidad son días exactos de 24 horas desde el momento dado.
     */
    public function test_la_caducidad_suma_dias_exactos(): void
    {
        $ahora = 1_800_000_000;

        $this->assertSame($ahora + (7 * DAY_IN_SECONDS), Link_Expiry::compute_expiry_timestamp(7, $ahora));
        $this->assertSame($ahora + (1 * DAY_IN_SECONDS), Link_Expiry::compute_expiry_timestamp(1, $ahora));
        $this->assertSame($ahora, Link_Expiry::compute_expiry_timestamp(0, $ahora));
    }
}
