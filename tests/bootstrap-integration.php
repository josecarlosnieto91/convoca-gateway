<?php
/**
 * Bootstrap de la suite de INTEGRACION de convoca-core (tests/Integration).
 *
 * Los casos de esta suite son `PHPUnit\Framework\TestCase` normales, no `WP_UnitTestCase`: lo que
 * necesitan es **WordPress cargado de verdad** para que sus comprobaciones dejen de saltarse. Hasta
 * hoy corrían con el bootstrap unitario, que define dobles de WordPress, así que los casos o se
 * saltaban con «WordPress not loaded» o corrían contra los dobles y reventaban dentro (una guarda
 * pasaba con `wp_next_scheduled`, que existe en los dobles, y el caso moría en un $wpdb de mentira).
 *
 * Dos caminos, y ninguno miente:
 *   1. CONVOCA_WP_ROOT apunta a un WordPress: se carga y los casos CORREN.
 *   2. No hay entorno: se deja cargar la suite sin dobles, así que las guardas de cada caso lo dicen
 *      —con su motivo y el issue vivo— y el CI lo enseña con `--display-skipped`. No se cargan los
 *      dobles a propósito: fue justo lo que hizo que un caso pasara la guarda y muriera después.
 *
 * Variables:
 *   CONVOCA_WP_ROOT  raíz del WordPress de pruebas (por defecto /var/www/html, el del contenedor).
 */

$convoca_raiz = getenv( 'CONVOCA_WP_ROOT' ) ?: '/var/www/html';

if ( ! defined( 'ABSPATH' ) ) {
	// Varios ficheros del plugin salen con `if ( ! defined( 'ABSPATH' ) ) { exit; }`: sin esta
	// constante el proceso muere en silencio con código 0 y la suite parece pasar sin correr nada.
	define( 'ABSPATH', rtrim( $convoca_raiz, '/' ) . '/' );
}

if ( file_exists( $convoca_raiz . '/wp-load.php' ) ) {
	define( 'WP_USE_THEMES', false );
	require_once $convoca_raiz . '/wp-load.php';
	// `dbDelta` (y las tablas del núcleo) viven en el área de administración: el instalador las usa.
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	echo "[integracion] WordPress cargado desde $convoca_raiz · el plugin corre por active_plugins\n";
	return;
}

/*
 * Sin entorno: se cargan los dobles de WordPress (los mismos de la suite unitaria), como se hacía
 * antes. Hay casos que no necesitan WordPress y con los dobles pasan —y esos 196 asertos no se
 * tiran—, mientras que los que sí lo necesitan se declaran SALTADOS con su motivo y el issue vivo.
 * Las guardas están corregidas para que ninguna pase en falso con un doble: la que pedía
 * `wp_next_scheduled` (que existe en los dobles) ahora pide `dbDelta`, que solo existe con
 * WordPress cargado, y por eso dejó de morir por dentro.
 */
require_once __DIR__ . '/bootstrap-unit.php';
echo "[integracion] SIN WordPress en $convoca_raiz: casos dependientes de WordPress declarados saltados, con motivo\n";
