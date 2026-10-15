<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

// Dobles compartidos de WordPress (funciones, clases y $wpdb) para las
// pruebas unitarias. Se cargan siempre y antes que los ficheros de pruebas,
// de forma que cada fichero de tests/Unit puede ejecutarse en solitario.
require_once __DIR__ . '/stubs.php';

// Mock Convoca\Core\Logger (clase del core, no de WordPress).
if ( ! class_exists( 'Convoca\\Core\\Logger' ) ) {
	require_once __DIR__ . '/StubLogger.php';
require_once __DIR__ . '/StubMailer.php';
}

// Doble de Convoca\Core\Utils (el manejador de notificaciones lo usa para sus ganchos).
if ( ! class_exists( 'Convoca\\Core\\Utils' ) ) {
	require_once __DIR__ . '/StubCoreUtils.php';
}

// Andamiaje de las pantallas del núcleo (convoca-core#16): las pantallas de este plugin heredan de
// Convoca\Core\Admin\Pantalla_Admin, así que el símbolo tiene que existir cuando se declara la clase.
// Se carga el fichero REAL si el núcleo está clonado al lado; solo si no está, un doble mínimo.
$convoca_core_admin = dirname( __DIR__, 2 ) . '/convoca-core/includes/Admin/Andamiaje_Admin.php';
if ( file_exists( $convoca_core_admin ) && ! trait_exists( 'Convoca\\Core\\Admin\\Andamiaje_Admin' ) ) {
	require_once $convoca_core_admin;
	require_once dirname( __DIR__, 2 ) . '/convoca-core/includes/Admin/Pantalla_Admin.php';
}
if ( ! trait_exists( 'Convoca\\Core\\Admin\\Andamiaje_Admin' ) ) {
	require_once __DIR__ . '/StubAndamiajeAdmin.php';
}

if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}
