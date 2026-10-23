<?php

/**
 * El listado de enlaces de pago: si el enlace está vivo, cuándo caduca y cuántos cobros ha emitido.
 *
 * `Admin_Links` estaba al 7,5 % (23/308). Aquí la lógica no es cosmética: un enlace caducado que se
 * pinte como «Activo» manda a alguien a pagar a una página que ya no cobra, y un enlace con cobros
 * emitidos no se puede borrar sin saber qué se rompe.
 *
 * `CPT_Pago::get_meta()` lee las claves con el prefijo `_convoca_`: el doble las escribe igual.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Links;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Admin_Links.php';

class AdminLinksColumnasTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true, 'manage_options' => true];
        $GLOBALS['__gw_options'] = [];
        $GLOBALS['__gw_posts'] = [];
        $GLOBALS['__gw_hijos'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_meta'], $GLOBALS['__gw_caps'], $GLOBALS['__gw_options'], $GLOBALS['__gw_posts'], $GLOBALS['__gw_hijos']);
    }

    /**
     * @param array<string, mixed> $meta sin prefijo
     */
    private function enlace(int $id, array $meta): \WP_Post
    {
        foreach ($meta as $clave => $valor) {
            $GLOBALS['__gw_meta'][ $id ]['_convoca_' . $clave] = $valor;
        }

        $post = new \WP_Post($id);
        $post->post_type = 'pago';

        return $post;
    }

    private function admin(): Admin_Links
    {
        return new Admin_Links();
    }

    // ── caducidad ────────────────────────────────────────────────────────────────────────

    public function test_un_enlace_sin_caducidad_se_ve_como_nunca(): void
    {
        $html = $this->admin()->column_expires($this->enlace(1, ['expires_at' => 0]));

        $this->assertStringContainsString('Nunca', $html);
        $this->assertStringContainsString('∞', $html);
    }

    public function test_un_enlace_caducado_se_ve_en_rojo(): void
    {
        $html = $this->admin()->column_expires($this->enlace(2, ['expires_at' => time() - 3600]));

        $this->assertStringContainsString('#d63638', $html, 'lo caducado tiene que saltar a la vista');
        $this->assertStringNotContainsString('Nunca', $html);
    }

    public function test_un_enlace_vivo_se_ve_con_su_fecha(): void
    {
        $futuro = time() + 86400;
        $html   = $this->admin()->column_expires($this->enlace(3, ['expires_at' => $futuro]));

        $this->assertStringContainsString(wp_date('d/m/Y', $futuro), $html, 'la fecha de caducidad se lee');
        $this->assertStringNotContainsString('#d63638', $html, 'y no se pinta como caducado');
    }

    // ── estado ──────────────────────────────────────────────────────────────────────────

    public function test_un_enlace_sin_cobros_y_vivo_esta_activo(): void
    {
        $html = $this->admin()->column_active($this->enlace(4, ['expires_at' => time() + 86400]));

        $this->assertStringContainsString('Activo', $html);
        $this->assertStringContainsString('convoca-badge--success', $html);
    }

    public function test_un_enlace_caducado_no_se_pinta_como_activo(): void
    {
        $html = $this->admin()->column_active($this->enlace(5, ['expires_at' => time() - 60]));

        $this->assertStringContainsString('Caducado', $html);
        $this->assertStringNotContainsString('>Activo<', $html, 'mandar a pagar a un enlace muerto es peor que no enseñarlo');
        $this->assertStringContainsString('convoca-badge--danger', $html);
    }

    public function test_un_enlace_con_cobros_emitidos_los_cuenta(): void
    {
        // `cobros_emitidos` va por get_posts(): en este arnés eso es `__gw_hijos`.
        $GLOBALS['__gw_hijos'] = [new \WP_Post(61), new \WP_Post(62)];

        $html = $this->admin()->column_active($this->enlace(6, ['expires_at' => time() + 86400]));

        $this->assertMatchesRegularExpression('/\d+ cobros?/', $html, 'el número de cobros va en la etiqueta');
    }

    // ── el resto de columnas ────────────────────────────────────────────────────────────

    public function test_la_casilla_lleva_el_id(): void
    {
        $html = $this->admin()->column_cb($this->enlace(7, []));

        $this->assertStringContainsString('value="7"', $html);
    }

    public function test_el_correo_del_destinatario_se_ve(): void
    {
        $html = $this->admin()->column_email($this->enlace(8, ['recipient_email' => 'socia@example.com']));

        $this->assertStringContainsString('socia@example.com', $html);
    }

    public function test_sin_correo_no_se_inventa_nada(): void
    {
        $html = $this->admin()->column_email($this->enlace(9, ['recipient_email' => '']));

        $this->assertStringNotContainsString('@', $html, 'sin correo no puede aparecer una dirección inventada');
    }

    public function test_la_fecha_de_generacion_se_ve(): void
    {
        $html = $this->admin()->column_created($this->enlace(10, ['created_at' => '2026-10-01 09:30:00']));

        $this->assertStringContainsString('2026-10-01', $html);
    }

    public function test_el_importe_del_enlace_sale_formateado(): void
    {
        $html = $this->admin()->column_amount($this->enlace(22, ['amount_cents' => 1500, 'open_amount' => 0]));

        $this->assertStringContainsString('15,00', $html);
    }

    public function test_un_enlace_de_importe_libre_lo_dice(): void
    {
        $html = $this->admin()->column_amount($this->enlace(23, ['amount_cents' => 0, 'open_amount' => 1]));

        $this->assertStringContainsString('libre', strtolower($html), 'un donativo no tiene importe fijo');
    }

    public function test_el_metodo_y_el_origen_se_pintan(): void
    {
        $metodo = $this->admin()->column_method($this->enlace(24, ['method' => 'bizum']));
        $origen = $this->admin()->column_origin($this->enlace(25, ['origin' => 'link_payment']));

        $this->assertStringContainsString('Bizum', $metodo);
        $this->assertNotSame('', strip_tags($origen));
    }

    public function test_el_pedido_del_enlace_se_ve(): void
    {
        $html = $this->admin()->column_order_id($this->enlace(26, ['order_id' => 'LINK-026']));

        $this->assertStringContainsString('LINK-026', $html);
    }

    public function test_las_columnas_declaran_lo_necesario_para_gestionar_enlaces(): void
    {
        $claves = implode(' ', array_keys($this->admin()->get_columns()));

        foreach (['order_id', 'concepto', 'amount', 'email', 'active', 'expires', 'created'] as $necesaria) {
            $this->assertStringContainsString($necesaria, $claves, "falta la columna {$necesaria}");
        }
        $this->assertStringNotContainsString('status', $claves, 'un enlace no es un cobro pendiente: no lleva estado de pago');
    }

    public function test_hay_columnas_ordenables(): void
    {
        $ordenables = $this->admin()->get_sortable_columns();

        $this->assertNotEmpty($ordenables);
        $this->assertIsArray($ordenables);
    }
}
