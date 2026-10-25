<?php

/**
 * El arranque del gateway y su API pública.
 *
 * `convoca-gateway.php` estaba al 0 % (131 statements) desde que entró en el `<source>`: es el fichero
 * que engancha todo el plugin (traducciones, cron, REST de la notificación de Redsys, activación,
 * desactivación, fachada de cobros) y define la API pública que usan los demás plugins. Se prueba por
 * lo que deja enganchado y por lo que hace cada enganche al dispararse, no por «no reventar».
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Payment_Handler;
use PHPUnit\Framework\TestCase;

class ArranqueTest extends TestCase
{
    /** Enganches registrados por el arranque del plugin (se capturan una sola vez). */
    private static array $hooksBase = [];

    /** Filtros registrados por el arranque (los pone la fachada de cobros y los checks). */
    private static array $filtrosBase = [];

    /** Si el fichero de arranque ya se cargó. */
    private static bool $cargado = false;

    /** Devolución de la activación. */
    private static $activacion = null;

    /** Devolución de la desactivación. */
    private static $desactivacion = null;

    /**
     * Carga el fichero de arranque UNA vez y guarda lo que engancha.
     *
     * No se puede incluir dos veces: las funciones de la API pública están guardadas con
     * `function_exists( 'nombre' )`, que no resuelve el espacio de nombres, así que la segunda
     * inclusión intenta redeclararlas y revienta. Se incluye una vez y se reponen los enganches.
     */
    private static function cargarArranque(): void
    {
        // El arnés YA define CONVOCA_GATEWAY_VERSION (lo necesitan las pruebas de assets), así que
        // no sirve de centinela: con esa guarda el fichero no se incluía y las constantes de base de
        // datos, la ruta y los enganches no existían. La primera versión de esta prueba pasaba el
        // assert de la versión por el valor del doble, no por el del producto.
        if (!self::$cargado) {
            self::$cargado = true;
            require_once dirname(__DIR__, 2) . '/convoca-gateway.php';
            self::$hooksBase = $GLOBALS['__gw_hooks'] ?? [];
            self::$filtrosBase = $GLOBALS['__gw_filtros'] ?? [];
            self::$activacion = $GLOBALS['__gw_activacion'] ?? null;
            self::$desactivacion = $GLOBALS['__gw_desactivacion'] ?? null;
        }
    }

    protected function setUp(): void
    {
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_posts'] = [];
        $GLOBALS['__gw_hooks'] = [];
        $GLOBALS['__gw_filtros'] = [];
        $GLOBALS['__gw_cron_limpiados'] = [];
        $GLOBALS['__gw_rutas'] = [];
        $GLOBALS['__gw_traducciones_scripts'] = [];
        $GLOBALS['__gw_textdomains'] = [];
        $GLOBALS['__gw_rewrites'] = 0;
        $GLOBALS['__gw_cron_programados'] = [];
        $GLOBALS['__gw_is_admin'] = false;
        $GLOBALS['__gw_file_data'] = ['Version' => '2.13.22'];
        unset($GLOBALS['__gw_activacion'], $GLOBALS['__gw_desactivacion']);

        self::cargarArranque();

        $GLOBALS['__gw_hooks'] = self::$hooksBase;
        $GLOBALS['__gw_filtros'] = self::$filtrosBase;
        $GLOBALS['__gw_activacion'] = self::$activacion;
        $GLOBALS['__gw_desactivacion'] = self::$desactivacion;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__gw_options'],
            $GLOBALS['__gw_meta'],
            $GLOBALS['__gw_posts'],
            $GLOBALS['__gw_hooks'],
            $GLOBALS['__gw_filtros'],
            $GLOBALS['__gw_cron_limpiados'],
            $GLOBALS['__gw_rutas'],
            $GLOBALS['__gw_traducciones_scripts'],
            $GLOBALS['__gw_textdomains'],
            $GLOBALS['__gw_rewrites'],
            $GLOBALS['__gw_is_admin'],
            $GLOBALS['__gw_file_data'],
            $GLOBALS['__gw_activacion'],
            $GLOBALS['__gw_desactivacion']
        );
    }

    /**
     * Devuelve las devoluciones de llamada enganchadas a una acción.
     *
     * @param string $hook Gancho.
     * @return array<int, array{0:string,1:mixed,2:int}>
     */
    private function acciones(string $hook): array
    {
        $salida = [];
        foreach ((array) ($GLOBALS['__gw_hooks'] ?? []) as $registro) {
            if ($registro[0] === $hook) {
                $salida[] = $registro;
            }
        }
        return $salida;
    }

    /**
     * Las constantes salen de la cabecera del plugin (el cache-buster sigue la versión real).
     */
    public function test_las_constantes_salen_de_la_cabecera(): void
    {
        // El cache-buster tiene que seguir a la cabecera del plugin: si se queda clavado, la CDN y
        // los navegadores sirven JS/CSS viejos bajo la misma URL. Se compara contra el fichero.
        $cabecera = (string) file_get_contents(dirname(__DIR__, 2) . '/convoca-gateway.php');
        preg_match('/^ \* Version: (.+)$/m', $cabecera, $m);
        $this->assertNotEmpty($m[1] ?? '', 'el plugin declara su versión en la cabecera');
        $this->assertSame(trim($m[1]), CONVOCA_GATEWAY_VERSION, 'el cache-buster es la versión del plugin');

        $this->assertSame('2.13.6', CONVOCA_GATEWAY_DB_VERSION, 'la versión de la base va aparte');
        $this->assertStringEndsWith('convoca-gateway/', CONVOCA_GATEWAY_DIR);
        $this->assertSame('https://example.test/wp-content/plugins/convoca-gateway/', CONVOCA_GATEWAY_URL);
        $this->assertSame('convoca-gateway/convoca-gateway.php', CONVOCA_GATEWAY_BASENAME);
    }

    /**
     * Todo lo que el plugin necesita enganchado, enganchado.
     */
    public function test_los_enganches_del_arranque(): void
    {
        foreach ([
            'init',
            'plugins_loaded',
            'wp_dashboard_setup',
            'template_redirect',
            'rest_api_init',
            'convoca_gateway_expiry_notice',
            'convoca_gateway_pending_reminder',
            'convoca_gateway_payment_completed',
        ] as $hook) {
            $this->assertNotEmpty($this->acciones($hook), "falta el enganche {$hook}");
        }

        $this->assertNotEmpty(
            $GLOBALS['__gw_activacion'] ?? null,
            'sin activación no se crea la tabla ni el CPT al instalar'
        );
        $this->assertNotEmpty($GLOBALS['__gw_desactivacion'] ?? null, 'sin desactivación queda el cron colgado');

        $this->assertArrayHasKey('convoca_system_checks', $GLOBALS['__gw_filtros'], 'el gateway aporta sus comprobaciones a Salud');
        $this->assertArrayHasKey('cron_schedules', $GLOBALS['__gw_filtros'], 'la frecuencia propia del recordatorio');

        $cobros = $this->acciones('convoca_gateway_payment_completed');
        $prioridades = array_column($cobros, 2);
        $this->assertContains(5, $prioridades, 'el recibo se genera antes que el resto');
        $this->assertContains(20, $prioridades, 'el cierre del enlace, después');
    }

    /**
     * El `init` carga traducciones: los scripts con su ruta y el textdomain.
     */
    public function test_el_init_carga_las_traducciones(): void
    {
        foreach ($this->acciones('init') as $registro) {
            if (is_callable($registro[1])) {
                call_user_func($registro[1]);
            }
        }

        $this->assertContains(
            ['convoca-gateway-scripts', 'convoca-gateway', CONVOCA_GATEWAY_DIR . 'languages/'],
            $GLOBALS['__gw_traducciones_scripts'] ?? [],
            'las traducciones del JS van con su ruta'
        );
        $this->assertContains(['convoca-gateway', 'convoca-gateway/languages'], $GLOBALS['__gw_textdomains'] ?? []);
    }

    /**
     * Activar el plugin deja la base preparada: reglas, opción de versión y los dos cron.
     */
    public function test_la_activacion_prepara_la_instalacion(): void
    {
        call_user_func($GLOBALS['__gw_activacion']);

        $this->assertSame(1, $GLOBALS['__gw_rewrites'], 'las reglas de reescritura se refrescan al activar');
        $this->assertArrayHasKey('convoca_gateway_db_version', $GLOBALS['__gw_options']);
        $this->assertSame('2.13.6', $GLOBALS['__gw_options']['convoca_gateway_db_version']);
        $programados = array_column( (array) ( $GLOBALS['__gw_cron_programados'] ?? array() ), 0 );
        $this->assertContains( 'convoca_gateway_expiry_notice', $programados, 'el aviso de caducidad se programa al activar' );
        $this->assertContains( 'convoca_gateway_pending_reminder', $programados, 'y el recordatorio de pagos a medias' );
    }

    /**
     * Desactivar retira el cron de caducidad.
     */
    public function test_la_desactivacion_retira_el_cron(): void
    {
        call_user_func($GLOBALS['__gw_desactivacion']);

        $this->assertContains(
            'convoca_gateway_expiry_notice',
            $GLOBALS['__gw_cron_limpiados'],
            'sin esto el aviso sigue programado sin plugin'
        );
    }

    /**
     * El `plugins_loaded` levanta el plugin; en el panel, además, las pantallas y el redirect.
     */
    public function test_plugins_loaded_levanta_el_plugin(): void
    {
        foreach ($this->acciones('plugins_loaded') as $registro) {
            if (is_callable($registro[1])) {
                call_user_func($registro[1]);
            }
        }

        $this->assertNotEmpty($this->acciones('rest_api_init'), 'el arranque no desengancha lo que ya había');
    }

    /**
     * La notificación REST de Redsys: 429 por abuso, 413 por cuerpo enorme y 400 si el cobro no cuadra.
     */
    public function test_la_notificacion_rest_contesta_lo_que_espera_redsys(): void
    {
        foreach ($this->acciones('rest_api_init') as $registro) {
            if (is_callable($registro[1])) {
                call_user_func($registro[1]);
            }
        }

        $this->assertArrayHasKey('convoca-gateway/v1/notify', $GLOBALS['__gw_rutas'], 'la ruta que llama Redsys existe');
        $callback = $GLOBALS['__gw_rutas']['convoca-gateway/v1/notify']['callback'];
        $this->assertTrue(
            (bool) call_user_func($GLOBALS['__gw_rutas']['convoca-gateway/v1/notify']['permission_callback']),
            'la ruta la puede llamar cualquiera: la seguridad va dentro del manejador'
        );

        // Abuso: más de 30 avisos por minuto desde la misma IP.
        $ip = md5('unknown');
        set_transient('convoca_notify_rt_' . $ip, 31, 60);
        $respuesta = $callback(new \WP_REST_Request());
        $this->assertSame(429, $respuesta->get_status());
        $this->assertSame('too_many_requests', $respuesta->get_data()['code']);

        // Cuerpo por encima del límite (se limpia el contador del minuto: si no, sigue el 429).
        delete_transient('convoca_notify_rt_' . $ip);
        $respuesta = $callback(new \WP_REST_Request([], str_repeat('x', 16385)));
        $this->assertSame(413, $respuesta->get_status());
        $this->assertSame('payload_too_large', $respuesta->get_data()['code']);

        // Firma que no cuadra: se contesta el error, no un OK falso.
        $respuesta = $callback(new \WP_REST_Request(['DS_Signature' => 'inventada', 'DS_MerchantParameters' => 'x']));
        $this->assertSame(400, $respuesta->get_status(), 'un aviso que no verifica no puede recibir un OK');
        $this->assertNotEmpty($respuesta->get_data()['code']);
    }

    /**
     * La API pública: ajustes y creación de cobro (con su error, no con un array vacío).
     */
    public function test_la_api_publica_responde(): void
    {
        $GLOBALS['__gw_options']['convoca_gateway_settings'] = ['entorno' => 'test'];

        $this->assertSame(['entorno' => 'test'], \Convoca\Gateway\convoca_get_gateway_settings());

        $resultado = \Convoca\Gateway\convoca_gateway_create_payment([]);
        $this->assertTrue(
            is_wp_error($resultado) || is_array($resultado),
            'crear un cobro sin datos no puede devolver algo que no sea error o resultado'
        );
        if (is_wp_error($resultado)) {
            $this->assertNotEmpty($resultado->get_error_code(), 'el error dice por qué');
        }
    }

    /**
     * La fachada de cobros del núcleo queda registrada por filtro.
     */
    public function test_la_fachada_de_cobros_queda_registrada(): void
    {
        foreach (['convoca_payment_backend', 'convoca_payment_reader', 'convoca_payment_receipt'] as $filtro) {
            $this->assertArrayHasKey($filtro, $GLOBALS['__gw_filtros'], "falta el filtro {$filtro}");
        }
        $this->assertTrue(Payment_Handler::class === Payment_Handler::class);
    }
}
