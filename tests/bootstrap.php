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
}

// Doble de Convoca\Core\Utils (el manejador de notificaciones lo usa para sus ganchos).
if ( ! class_exists( 'Convoca\\Core\\Utils' ) ) {
	require_once __DIR__ . '/StubCoreUtils.php';
}

if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}
