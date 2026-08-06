<?php
/**
 * PHPStan bootstrap: constantes WP de runtime (las define wp-load en producción).
 */
define( 'ABSPATH', '/tmp/wp/' );
define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'WP_DEBUG', true );
