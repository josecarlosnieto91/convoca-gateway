<?php

/**
 * Los ayudantes del exportador de pagos: de dónde salió el pago y a quién hay que escribirle.
 *
 * `CSV_Exporter::export()` no se puede ejercitar aquí: termina en `exit` y vuelca por el flujo del
 * núcleo. Lo que sí se prueba es la parte que decide el contenido —qué origen se lee y qué correo
 * se busca—, que es donde un fallo sale en el CSV como una columna vacía.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\CSV_Exporter;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/CSV_Exporter.php';

class CsvExporterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_meta']);
    }

    public function test_el_origen_de_una_inscripcion_se_traduce(): void
    {
        $this->assertSame('Inscripción', CSV_Exporter::format_origin('enroll'));
    }

    public function test_el_origen_de_un_socio_se_traduce(): void
    {
        $this->assertSame('Socio/a', CSV_Exporter::format_origin('members'));
    }

    public function test_un_origen_desconocido_se_deja_como_esta(): void
    {
        $this->assertSame('link_payment', CSV_Exporter::format_origin('link_payment'), 'mejor el código que una celda vacía');
    }

    public function test_sin_id_de_origen_no_hay_correo(): void
    {
        $this->assertSame('—', CSV_Exporter::get_user_email('enroll', 0));
    }

    public function test_de_una_inscripcion_se_saca_el_correo_guardado(): void
    {
        $GLOBALS['__gw_meta'][55] = ['_convoca_email' => 'socia@example.com'];

        $this->assertSame('socia@example.com', CSV_Exporter::get_user_email('enroll', 55));
    }

    public function test_de_un_socio_se_saca_el_mismo_correo(): void
    {
        $GLOBALS['__gw_meta'][77] = ['_convoca_email' => 'socio@example.com'];

        $this->assertSame('socio@example.com', CSV_Exporter::get_user_email('members', 77));
    }

    public function test_un_origen_sin_correo_conocido_devuelve_guion(): void
    {
        $GLOBALS['__gw_meta'][99] = ['_convoca_email' => 'alguien@example.com'];

        $this->assertSame('—', CSV_Exporter::get_user_email('link_payment', 99), 'no se inventa un correo de un origen que no lo guarda');
    }
}
