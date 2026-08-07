<?php
/**
 * Unit test bootstrap — standalone, no WordPress needed.
 *
 * Gateway depende de Convoca Core (Logger, Utils). Reutilizamos el
 * bootstrap-unit de Core (que mockea las funciones WP) y luego cargamos
 * los autoloaders de Core y Gateway.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// 1. Mocks de WordPress (stubs) del core.
$core_bootstrap = dirname( __DIR__, 2 ) . '/convoca-core/tests/bootstrap-unit.php';
if ( file_exists( $core_bootstrap ) ) {
	require_once $core_bootstrap;
}

// 2. Autoloader propio (resuelve clases del gateway; las del core ya
//    quedaron registradas por el require del bootstrap de core).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
