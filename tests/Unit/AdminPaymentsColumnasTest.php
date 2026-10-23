<?php

/**
 * Las columnas del listado de pagos: lo que se ve en cada fila del historial.
 *
 * `Admin_Payments` estaba al 17 % (63/370). Las columnas son lógica de verdad, no adorno: el enlace
 * al origen solo aparece cuando existe, el importe libre de un donativo no puede salir como «0,00 €»
 * y borrar un pago solo se ofrece a quien puede hacerlo.
 *
 * Ojo con las claves: `CPT_Pago::get_meta()` las lee con el prefijo `_convoca_`, así que en el doble
 * hay que escribirlas igual. La primera version de esta prueba daba todo vacío por eso.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\Admin_Payments;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Admin_Payments.php';

class AdminPaymentsColumnasTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__gw_meta'] = [];
        $GLOBALS['__gw_caps'] = ['convoca_view_payments' => true, 'manage_options' => true];
        $GLOBALS['__gw_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__gw_meta'], $GLOBALS['__gw_caps'], $GLOBALS['__gw_options']);
    }

    /**
     * @param array<string, mixed> $meta sin prefijo: se le pone aquí
     */
    private function pago(int $id, array $meta): \WP_Post
    {
        foreach ($meta as $clave => $valor) {
            $GLOBALS['__gw_meta'][ $id ]['_convoca_' . $clave] = $valor;
        }

        $post = new \WP_Post($id); // el doble de este arnés recibe el id, no un objeto
        $post->post_type = 'pago';

        return $post;
    }

    private function admin(): Admin_Payments
    {
        return new Admin_Payments();
    }

    // ── el enlace del pedido ─────────────────────────────────────────────────────────────

    public function test_el_pedido_enlaza_a_su_detalle(): void
    {
        $pago = $this->pago(1, ['order_id' => 'PED-001', 'origin' => 'link_payment', 'origin_id' => 0]);

        $html = $this->admin()->column_order_id($pago);

        $this->assertStringContainsString('PED-001', $html);
        $this->assertStringContainsString('page=conv-gateway-payments-detail&id=1', $html);
        $this->assertStringNotContainsString('Ver inscripción', $html, 'un donativo no tiene inscripción');
        $this->assertStringNotContainsString('Ver miembro', $html);
    }

    public function test_un_pago_de_inscripcion_enlaza_a_su_inscripcion(): void
    {
        $pago = $this->pago(2, ['order_id' => 'PED-002', 'origin' => 'enroll', 'origin_id' => 55]);

        $html = $this->admin()->column_order_id($pago);

        $this->assertStringContainsString('Ver inscripción', $html);
        $this->assertStringContainsString('inscripcion_id=55', $html, 'el enlace lleva al origen concreto');
    }

    public function test_un_pago_de_socio_enlaza_al_socio(): void
    {
        $pago = $this->pago(3, ['order_id' => 'PED-003', 'origin' => 'members', 'origin_id' => 77]);

        $html = $this->admin()->column_order_id($pago);

        $this->assertStringContainsString('Ver miembro', $html);
        $this->assertStringContainsString('member_id=77', $html);
    }

    public function test_un_origen_sin_id_no_genera_enlace_roto(): void
    {
        $pago = $this->pago(4, ['order_id' => 'PED-004', 'origin' => 'enroll', 'origin_id' => 0]);

        $html = $this->admin()->column_order_id($pago);

        $this->assertStringNotContainsString('Ver inscripción', $html, 'sin id no hay a dónde enlazar');
    }

    public function test_borrar_solo_se_ofrece_a_quien_puede(): void
    {
        $pago = $this->pago(5, ['order_id' => 'PED-005', 'origin' => 'link_payment', 'origin_id' => 0]);

        $GLOBALS['__gw_caps'] = [
            'convoca_view_payments' => true,
            'manage_options' => true,
            \Convoca\Gateway\Admin_Record_Actions::CAP => true,
        ];
        $con = $this->admin()->column_order_id($pago);
        $this->assertStringContainsString('Eliminar', $con, 'quien administra puede borrar');

        // El doble de current_user_can devuelve true si la capacidad no está declarada: hay que
        // apagar también la propia de borrado, no solo manage_options.
        $GLOBALS['__gw_caps'] = [
            'convoca_view_payments' => true,
            'manage_options' => false,
            \Convoca\Gateway\Admin_Record_Actions::CAP => false,
        ];
        $sin = $this->admin()->column_order_id($pago);
        $this->assertStringNotContainsString('Eliminar', $sin, 'quien no puede borrar no ve el enlace');
    }

    // ── el importe ───────────────────────────────────────────────────────────────────────

    public function test_un_importe_normal_sale_formateado(): void
    {
        $pago = $this->pago(6, ['amount_cents' => 2500, 'open_amount' => 0]);

        $html = $this->admin()->column_amount($pago);

        $this->assertStringContainsString('25,00', $html);
    }

    public function test_un_donativo_de_importe_libre_no_sale_como_cero(): void
    {
        $pago = $this->pago(7, ['amount_cents' => 0, 'open_amount' => 1]);

        $html = $this->admin()->column_amount($pago);

        $this->assertStringContainsString('Importe libre', $html, 'un donativo no tiene importe: lo pone quien aporta');
        $this->assertStringNotContainsString('0,00', $html);
    }

    // ── método, estado, casilla ─────────────────────────────────────────────────────────

    public function test_el_metodo_sale_con_su_nombre(): void
    {
        $pago = $this->pago(8, ['method' => 'tarjeta']);

        $html = $this->admin()->column_method($pago);

        $this->assertStringContainsString('Tarjeta', $html);
    }

    public function test_el_estado_sale_con_su_etiqueta(): void
    {
        $pago = $this->pago(9, ['status' => 'paid']);

        $html = $this->admin()->column_status($pago);

        $this->assertNotSame('', strip_tags($html), 'un estado vacío no dice nada a quien gestiona');
        $this->assertMatchesRegularExpression('/pagado|cobrado|paid|completado/i', $html);
    }

    public function test_la_casilla_lleva_el_id_del_pago(): void
    {
        $pago = $this->pago(10, []);

        $html = $this->admin()->column_cb($pago);

        $this->assertStringContainsString('name="pago[]"', $html);
        $this->assertStringContainsString('value="10"', $html);
    }

    public function test_una_columna_desconocida_no_revienta(): void
    {
        $pago = $this->pago(11, ['order_id' => 'PED-011']);

        $this->assertSame('PED-011', $this->admin()->column_default($pago, 'order_id'));
        $this->assertSame('—', $this->admin()->column_default($pago, 'inventada'), 'sin dato se pinta un guion, no un error');
    }

    // ── las columnas declaradas ─────────────────────────────────────────────────────────

    public function test_las_columnas_incluyen_lo_que_hace_falta_para_gestionar(): void
    {
        $claves = implode(' ', array_keys($this->admin()->get_columns()));

        foreach (['order_id', 'amount', 'method', 'status', 'origin', 'date'] as $necesaria) {
            $this->assertStringContainsString($necesaria, $claves, "falta la columna {$necesaria}");
        }
    }

    public function test_hay_columnas_ordenables(): void
    {
        $ordenables = $this->admin()->get_sortable_columns();

        $this->assertNotEmpty($ordenables);
        foreach ($ordenables as $columna => $orden) {
            $this->assertIsArray($orden);
        }
    }
}
