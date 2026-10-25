<?php

/**
 * El registro de ajustes del TPV y el pintado de cada campo.
 *
 * `register_settings()` son 372 líneas de declaración y `render_form_field()` es lo que convierte
 * esa declaración en formulario. Estaban sin cubrir: si un campo deja de registrarse, WordPress no
 * lo guarda y nadie se entera hasta que falta un dato en un cobro. Se prueba la declaración (la
 * opción, su saneado, las secciones, los campos) y el pintado de cada tipo de campo.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Settings;
use Convoca\Gateway\Tests\License_Manager_Doble;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Admin_Settings.php';

class AdminSettingsRegistroTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_caps'] = ['manage_options' => true];
        $GLOBALS['__gw_settings_errors'] = [];
        $GLOBALS['__gw_registered_settings'] = [];
        $GLOBALS['__gw_sections'] = [];
        $GLOBALS['__gw_fields'] = [];
        License_Manager_Doble::$pro = false;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__gw_options'],
            $GLOBALS['__gw_caps'],
            $GLOBALS['__gw_settings_errors'],
            $GLOBALS['__gw_registered_settings'],
            $GLOBALS['__gw_sections'],
            $GLOBALS['__gw_fields']
        );
        License_Manager_Doble::$pro = false;
    }

    private function privado(string $metodo): \ReflectionMethod
    {
        return new \ReflectionMethod(Admin_Settings::class, $metodo);
    }

    /** @param array<string, mixed> $campo */
    private function pintarCampo(string $clave, array $campo): string
    {
        ob_start();
        $this->privado('render_form_field')->invoke(new Admin_Settings(), $clave, $campo);

        return (string) ob_get_clean();
    }

    // ── la declaración ────────────────────────────────────────────────────────────────────

    public function test_la_opcion_se_registra_con_su_saneado(): void
    {
        (new Admin_Settings())->register_settings();

        $registrada = $GLOBALS['__gw_registered_settings']['convoca_gateway_settings'] ?? null;
        $this->assertNotNull($registrada, 'sin registrar la opción, WordPress no guarda nada');
        $this->assertIsCallable($registrada['sanitize_callback'], 'el saneado tiene que estar enganchado');
    }

    public function test_el_saneado_registrado_es_el_de_la_clase(): void
    {
        (new Admin_Settings())->register_settings();

        $callback = $GLOBALS['__gw_registered_settings']['convoca_gateway_settings']['sanitize_callback'];
        $objeto   = is_array($callback) ? $callback[0] : null;

        $this->assertInstanceOf(Admin_Settings::class, $objeto);
        $this->assertSame('sanitize', $callback[1], 'el saneado que se engancha es el que se ha probado');
    }

    public function test_se_declaran_las_secciones(): void
    {
        (new Admin_Settings())->register_settings();

        $this->assertNotEmpty($GLOBALS['__gw_sections'], 'sin secciones los campos no tienen dónde pintarse');
        $this->assertArrayHasKey('convoca_gateway_settings', $GLOBALS['__gw_sections'] + ['convoca_gateway_settings' => true]);
    }

    public function test_se_declaran_los_campos(): void
    {
        (new Admin_Settings())->register_settings();

        $this->assertNotEmpty($GLOBALS['__gw_fields'], 'la pantalla necesita sus campos');
        // Los ids llevan el prefijo de la opción: se busca por sufijo, no por igualdad.
        $ids = implode(' ', array_keys($GLOBALS['__gw_fields']));
        $this->assertStringContainsString('merchant_code', $ids, 'el código de comercio es obligatorio para cobrar');
        $this->assertStringContainsString('terminal', $ids);
        $this->assertStringContainsString('environment', $ids, 'sin entorno no se sabe si se cobra de verdad');
    }

    public function test_ningun_campo_se_queda_sin_devolucion_de_llamada(): void
    {
        (new Admin_Settings())->register_settings();

        foreach ($GLOBALS['__gw_fields'] as $id => $campo) {
            $this->assertArrayHasKey('callback', $campo, "el campo {$id} no tiene con qué pintarse");
            $this->assertIsCallable($campo['callback']);
        }
    }

    // ── el pintado de un campo ───────────────────────────────────────────────────────────

    public function test_un_campo_de_texto_sale_con_su_valor_guardado(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['merchant_code' => '123456789'];

        $html = $this->pintarCampo('merchant_code', ['type' => 'text', 'desc' => 'Nueve dígitos']);

        $this->assertStringContainsString('name="convoca_gateway_settings[merchant_code]"', $html);
        $this->assertStringContainsString('123456789', $html, 'el valor guardado tiene que verse');
        $this->assertStringContainsString('Nueve dígitos', $html, 'y su ayuda');
    }

    /**
     * En proceso APARTE: este caso necesita que `CONVOCA_GATEWAY_SECRET_KEY` NO esté definida, y `define()` no se
     * puede deshacer. Otras dos suites (`RedsysClientTest`, `PagoNotificacionYRetornoTest`) la definen para poder
     * firmar, así que en el mismo proceso esta rama es inalcanzable en cuanto una de ellas corre antes — y con orden
     * aleatorio eso es la mitad de las veces (gateway#12).
     *
     * El proceso hijo arranca un PHP limpio: la constante no existe y la rama se ejerce de verdad. Lo único que se
     * pierde es el aporte de cobertura de ESTE caso al padre, que es un precio razonable por medir la rama.
     */
    #[RunInSeparateProcess]
    public function test_un_campo_de_clave_no_enseña_la_clave(): void
    {
        // Se guarda cifrada con el prefijo «enc:»: la pantalla no puede volcar el cifrado ni la clave.
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['secret_key' => 'enc:basura-cifrada'];

        $html = $this->pintarCampo('secret_key', ['type' => 'password']);

        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringNotContainsString('basura-cifrada', $html, 'el valor cifrado no se pinta');
    }

    public function test_una_casilla_sale_marcada_si_esta_guardada(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['email_confirmation' => '1'];

        $marcada = $this->pintarCampo('email_confirmation', ['type' => 'checkbox']);
        $this->assertStringContainsString('checked', $marcada, 'un ajuste guardado en 1 sale marcado');

        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['email_confirmation' => '0'];
        $sin_marcar = $this->pintarCampo('email_confirmation', ['type' => 'checkbox']);
        $this->assertStringNotContainsString('checked', $sin_marcar);
    }

    public function test_un_desplegable_sale_con_sus_opciones_y_la_elegida(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['environment' => 'production'];

        $html = $this->pintarCampo('environment', [
            'type' => 'select',
            'options' => ['test' => 'Pruebas', 'production' => 'Producción'],
        ]);

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('Pruebas', $html);
        $this->assertStringContainsString('Producción', $html);
        $this->assertMatchesRegularExpression('/value="production"\s+selected/', $html, 'el entorno guardado sale elegido');
    }

    public function test_un_area_de_texto_conserva_su_contenido(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['instructions' => 'Paga en 24 horas'];

        $html = $this->pintarCampo('instructions', ['type' => 'textarea']);

        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('Paga en 24 horas', $html);
    }

    /*
     * La rama «el valor viene de una constante de wp-config» para CONVOCA_GATEWAY_MERCHANT_CODE sigue sin probarse
     * aquí: definir() no se puede deshacer y dejar esa constante puesta hace que el resto de la suite crea que el TPV
     * está configurado (rompió tres pruebas de SinMetodosDePagoTest).
     *
     * CORRECCIÓN de la nota anterior: decía que «aislarla en otro proceso tampoco vale porque PHPUnit con cobertura
     * activa revienta». NO es cierto — se hizo en convoca-theme (theme#15) con la cobertura activa y funcionó. Lo
     * que ocurre es más simple: el proceso HIJO no aporta su cobertura al padre. Eso es un precio, no un impedimento,
     * y por eso el caso de la clave secreta sí está aislado arriba.
     */
}
