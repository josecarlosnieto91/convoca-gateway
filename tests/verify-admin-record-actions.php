<?php
/**
 * Comprobación de Admin_Record_Actions sin WordPress (identificadores, confirmación,
 * borrado, avisos, permisos) y del cableado de los dos listados.
 *
 * Es el complemento de `composer test`: la suite unitaria prueba el núcleo con dobles
 * de PHPUnit y esto lo prueba con datos controlados y de punta a punta del fichero,
 * incluyendo la comprobación de que las acciones están enganchadas en los listados.
 *
 * Uso: composer verify   (o GW=<ruta del plugin> php tests/verify-admin-record-actions.php)
 * Sale 0 si todo pasa.
 */
define( 'ABSPATH', '/tmp/' );
$f = 0;
function check( $n, $ok ) {
	global $f;
	if ( ! $ok ) {
		$f++;
	}
	printf( "   %-4s %s\n", $ok ? 'PASS' : 'FAIL', $n );
}
$G = getenv( 'GW' ) ?: dirname( __DIR__ );

$GLOBALS['__meta']    = array();
$GLOBALS['__posts']   = array();
$GLOBALS['__deleted'] = array();
$GLOBALS['__hijos']   = array();
$GLOBALS['__caps']    = array( 'convoca_manage_payments' => true, 'manage_options' => true, 'delete_post' => true );
$GLOBALS['__opt']     = array();

class WP_Error {
	private $c; private $m;
	public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function get_post_meta( $i, $k = '', $s = false ) {
	$v = $GLOBALS['__meta'][ (int) $i ][ $k ] ?? null;
	if ( $s ) { return $v ?? ''; }
	return null === $v ? array() : ( is_array( $v ) ? $v : array( $v ) );
}
function update_post_meta( $i, $k, $v ) { $GLOBALS['__meta'][ (int) $i ][ $k ] = $v; return true; }
function get_post_type( $p = null ) { $id = is_object( $p ) ? (int) $p->ID : (int) $p; return $GLOBALS['__posts'][ $id ] ?? false; }
function wp_delete_post( $id, $force = false ) {
	$id = (int) $id;
	$GLOBALS['__deleted'][] = array( 'id' => $id, 'force' => $force );
	unset( $GLOBALS['__posts'][ $id ], $GLOBALS['__meta'][ $id ] );
	return (object) array( 'ID' => $id );
}
function get_posts( $a = array() ) { return $GLOBALS['__hijos']; }
function post_type_exists( $t ) { return 'pago' === $t; }
function absint( $v ) { return abs( (int) $v ); }
function current_user_can( $c, ...$a ) { return ! empty( $GLOBALS['__caps'][ $c ] ); }
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
function wp_kses_post( $s ) { return $s; }
function wp_nonce_field( $a, $b = '_wpnonce' ) { echo '<input type="hidden" name="' . $b . '">'; }
function admin_url( $p = '' ) { return 'https://t.test/wp-admin/' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return (string) $u; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function __( $s, $d = null ) { return $s; }
function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_die( $m ) { throw new RuntimeException( 'wp_die' ); }

require_once $G . '/tests/StubLogger.php';
foreach ( array( 'CPT_Pago', 'Redsys_Client', 'Link_Expiry', 'Admin_Record_Actions' ) as $c ) {
	require_once $G . '/includes/' . $c . '.php';
}
$A = '\Convoca\Gateway\Admin_Record_Actions';

function registro( int $id, array $meta = array() ): int {
	$GLOBALS['__posts'][ $id ] = 'pago';
	foreach ( $meta as $k => $v ) {
		$GLOBALS['__meta'][ $id ][ '_convoca_' . $k ] = $v;
	}

	return $id;
}
function capturar( callable $fn ): string { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "-- identificadores y acción pendiente\n";
registro( 41 );
registro( 42 );
$_REQUEST = array( 'id' => '41' );
check( 'enlace de una fila', array( 41 ) === $A::requested_ids( 'pago' ) );
$_REQUEST = array( 'pago' => array( '41', '42' ) );
check( 'selección en bloque de pagos', array( 41, 42 ) === $A::requested_ids( 'pago' ) );
$_REQUEST = array( 'enlace' => array( '42', '42' ) );
check( 'selección en bloque de enlaces, sin duplicados', array( 42 ) === $A::requested_ids( 'enlace' ) );
$GLOBALS['__posts'][43] = 'page';
$_REQUEST = array( 'pago' => array( '41', '43', '0', 'abc', '-7', array( 'x' ) ) );
check( 'basura y otros tipos fuera', array( 41 ) === $A::requested_ids( 'pago' ) );
$_REQUEST = array( 'action' => 'convoca_delete' );
check( 'acción en bloque (botón de arriba)', 'convoca_delete' === $A::pending_action() );
$_REQUEST = array( 'action' => '-1', 'action2' => 'convoca_delete' );
check( 'acción en bloque (botón de abajo)', 'convoca_delete' === $A::pending_action() );
$_REQUEST = array( 'action' => 'delete' );
check( 'el borrado de fila no se confunde con el bloque', '' === $A::pending_action() );

echo "-- permisos\n";
$GLOBALS['__caps'] = array( 'convoca_manage_payments' => true, 'manage_options' => false, 'delete_post' => true );
check( 'con la capacidad del plugin', $A::user_can() );
$GLOBALS['__caps'] = array( 'convoca_manage_payments' => false, 'manage_options' => true, 'delete_post' => true );
check( 'o siendo administrador del sitio', $A::user_can() );
$GLOBALS['__caps'] = array();
check( 'sin ninguna de las dos, no', ! $A::user_can() );
$GLOBALS['__caps'] = array( 'convoca_manage_payments' => true, 'manage_options' => true, 'delete_post' => true );

echo "-- pantalla de confirmación\n";
registro( 77, array( 'order_id' => '260910ABCDEF', 'amount_cents' => 5000, 'status' => 'pending', 'origin' => 'members' ) );
$html = capturar( static fn() => $A::render_confirm( 'pago', array( 77 ) ) );
check( 'avisa de que no se puede deshacer', str_contains( $html, 'no se puede deshacer' ) );
check( 'lista pedido, importe e id', str_contains( $html, '260910ABCDEF' ) && str_contains( $html, '50,00 €' ) && str_contains( $html, 'name="ids[]" value="77"' ) );
check( 'formulario firmado y tipo correcto', str_contains( $html, 'convoca_gateway_delete_records' ) && str_contains( $html, 'name="tipo" value="pago"' ) && str_contains( $html, '_wpnonce' ) );
check( 'con opción de cancelar', str_contains( $html, 'Cancelar' ) );

registro( 78, array( 'order_id' => '260910ZZZZZZ', 'amount_cents' => 1550, 'status' => 'paid', 'created_at' => '2026-09-09 11:00:00' ) );
update_post_meta( 78, '_convoca_receipt_number', 'D-2026-003' );
$html = capturar( static fn() => $A::render_confirm( 'pago', array( 78 ) ) );
check( 'un pago pagado avisa de que cuenta en ingresos', str_contains( $html, 'PAGADO' ) );
check( 'y de que tiene recibo, con su número', str_contains( $html, 'D-2026-003' ) );

registro( 90, array( 'order_id' => '260910LINK01', 'open_amount' => '1', 'amount_cents' => 0, 'product_desc' => 'Donativo', 'status' => 'pending', 'expires_at' => 0 ) );
$GLOBALS['__hijos'] = array( 101, 102 );
$html = capturar( static fn() => $A::render_confirm( 'enlace', array( 90 ) ) );
check( 'un enlace de donativo se identifica como importe libre', str_contains( $html, 'Importe libre' ) );
check( 'y avisa de que las aportaciones se conservan', str_contains( $html, 'se conservan' ) );

$GLOBALS['__hijos'] = array();
$html = capturar( static fn() => $A::render_confirm( 'pago', array() ) );
check( 'sin selección no ofrece borrar nada', str_contains( $html, 'no se ha seleccionado ningún registro' ) && ! str_contains( $html, 'name="ids[]"' ) );

echo "-- borrado\n";
registro( 1, array( 'order_id' => 'A', 'amount_cents' => 100, 'status' => 'pending', 'origin' => 'link_payment' ) );
registro( 2, array( 'order_id' => 'B', 'amount_cents' => 200, 'status' => 'paid', 'origin' => 'members' ) );
$GLOBALS['__posts'][3] = 'page';
check( 'borra los pagos y solo los pagos', 2 === $A::delete_records( array( 1, 2, 3, 0 ) ) );
check( 'y de forma definitiva (force)', array( true, true ) === array_column( $GLOBALS['__deleted'], 'force' ) );
check( 'los metadatos se van con el registro', ! isset( $GLOBALS['__meta'][2] ) );
check( 'sin ids no hace nada', 0 === $A::delete_records( array() ) && 0 === $A::delete_records( array( 999 ) ) );

echo "-- aviso de resultado\n";
$_GET = array( $A::FLAG => '1' );
check( 'en singular', str_contains( capturar( static fn() => $A::maybe_notice( 'pago' ) ), 'Se ha eliminado 1 registro' ) );
$_GET = array( $A::FLAG => '3' );
check( 'en plural', str_contains( capturar( static fn() => $A::maybe_notice( 'enlace' ) ), 'Se han eliminado 3 registros' ) );
$_GET = array( $A::FLAG_ERROR => '1' );
$html = capturar( static fn() => $A::maybe_notice( 'pago' ) );
check( 'y el error se explica', str_contains( $html, 'notice-error' ) && str_contains( $html, 'permisos' ) );
$_GET = array();
check( 'sin marca no pinta nada', '' === capturar( static fn() => $A::maybe_notice( 'pago' ) ) );

echo "-- cada listado vuelve al suyo\n";
check( 'pagos', str_contains( $A::list_url( 'pago' ), 'page=conv-gateway-payments' ) && 'pago' === $A::field( 'pago' ) );
check( 'enlaces', str_contains( $A::list_url( 'enlace' ), 'page=conv-gateway-links' ) && 'enlace' === $A::field( 'enlace' ) );

echo "-- los listados están cableados\n";
// Se compara con los espacios colapsados: en el código las columnas van alineadas
// y una comprobación a pelo por cadena falla por un espacio de más.
$plano   = static fn( string $f ): string => (string) preg_replace( '/\s+/', ' ', (string) file_get_contents( $G . '/includes/' . $f ) );
$pagos   = $plano( 'Admin_Payments.php' );
$enlaces = $plano( 'Admin_Links.php' );
check( 'Pagos: acción en bloque y de fila', str_contains( $pagos, "'convoca_delete' => __( 'Eliminar'" ) && str_contains( $pagos, 'action=delete&id=' ) );
check( 'Pagos: despacha a la confirmación y avisa', str_contains( $pagos, 'Admin_Record_Actions::render_confirm' ) && str_contains( $pagos, "maybe_notice( 'pago' )" ) );
check( 'Enlaces: bloque, editar y eliminar', str_contains( $enlaces, "'convoca_delete' => __( 'Eliminar'" ) && str_contains( $enlaces, 'action=edit&id=' ) && str_contains( $enlaces, 'action=delete&id=' ) );
check( 'Enlaces: edición y guardado enganchados', str_contains( $enlaces, 'function render_edit' ) && str_contains( $enlaces, 'function handle_save' ) && str_contains( $enlaces, 'admin_post_convoca_gateway_save_link' ) );
check( 'Enlaces: al guardar no se toca el token', str_contains( $enlaces, 'la URL publicada sigue valiendo' ) );
check( 'Pagos: los enlaces quedan fuera del listado', str_contains( $pagos, "'convoca_sin_enlaces'" ) && str_contains( $pagos, "'value' => 'link_payment'" ) && str_contains( $pagos, "'compare' => 'NOT EXISTS'" ) );
check( 'Pagos: orígenes en castellano y filtro de donaciones', str_contains( $pagos, "'donativo' => __( 'Donación'" ) && str_contains( $pagos, "'donativo' => 'Donaciones'" ) && str_contains( $pagos, "'manual' => __( 'Formulario web'" ) );
check( 'Pagos: el importe libre no se pinta como 0,00 €', str_contains( $pagos, "__( 'Importe libre'" ) );
check( 'un cobro del formulario no se marca como enlace', str_contains( $plano( 'Payment_Handler.php' ), "'origin' => 'manual'" ) );
check( 'la clase se carga al arrancar', str_contains( (string) file_get_contents( $G . '/convoca-gateway.php' ), 'new Admin_Record_Actions()' ) );

printf( "\n   RESULTADO: %s\n", 0 === $f ? 'TODO OK' : $f . ' FALLO(S)' );
exit( 0 === $f ? 0 : 1 );
