#!/usr/bin/env bash
#
# Monta un WordPress REAL para la suite de integración de convoca-core.
#
# Por qué existe: los casos de tests/Integration se saltaban con «WordPress not loaded» y el CI lo
# leía como verde (convoca-health-check#19 y #20). Lo que necesitan no es la biblioteca de pruebas
# de WordPress —no extienden WP_UnitTestCase— sino un WordPress en marcha con su base de datos.
#
# Uso:  DB_HOST=127.0.0.1:3306 DB_USER=root DB_PASSWORD=convoca_test bash bin/instalar-entorno-integracion.sh [directorio]
# Al terminar deja la variable que necesita la suite: CONVOCA_WP_ROOT=<directorio>/wp
set -euo pipefail

DATOS="${1:-/tmp/entorno-integracion}"
WP_VERSION="${WP_VERSION:-latest}"
DB_NAME="${DB_NAME:-convoca_test}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-convoca_test}"
DB_HOST="${DB_HOST:-127.0.0.1:3306}"
AQUI="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="$(basename "$AQUI")"

mkdir -p "$DATOS"

echo "==> 1/3 WordPress ($WP_VERSION) en $DATOS/wp"
if [ ! -f "$DATOS/wp/wp-load.php" ]; then
	curl -sSL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" -o "$DATOS/wp.tgz"
	mkdir -p "$DATOS/wp"
	tar xzf "$DATOS/wp.tgz" -C "$DATOS/wp" --strip-components=1
	rm -f "$DATOS/wp.tgz"
fi

echo "==> 2/3 wp-config.php y base de datos"
mysql -h "${DB_HOST%%:*}" -P "${DB_HOST##*:}" -u"$DB_USER" -p"$DB_PASSWORD" \
	-e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;" 2>/dev/null || true
cat > "$DATOS/wp/wp-config.php" <<PHP
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASSWORD' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'pruebas-convoca' );
define( 'SECURE_AUTH_KEY', 'pruebas-convoca' );
define( 'LOGGED_IN_KEY', 'pruebas-convoca' );
define( 'NONCE_KEY', 'pruebas-convoca' );
define( 'AUTH_SALT', 'pruebas-convoca' );
define( 'SECURE_AUTH_SALT', 'pruebas-convoca' );
define( 'LOGGED_IN_SALT', 'pruebas-convoca' );
define( 'NONCE_SALT', 'pruebas-convoca' );
\$table_prefix = 'wptests_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

/* Eso es todo. Deja de editar. ¡Feliz publicación! */

require_once ABSPATH . 'wp-settings.php';
PHP

echo "==> 3/3 el plugin (y el núcleo, si es otro) a la vista de WordPress"
ln -sfn "$AQUI" "$DATOS/wp/wp-content/plugins/$SLUG"
if [ "$SLUG" != "convoca-core" ] && [ -d "$AQUI/../convoca-core" ]; then
	ln -sfn "$(cd "$AQUI/../convoca-core" && pwd)" "$DATOS/wp/wp-content/plugins/convoca-core"
fi

wp --path="$DATOS/wp" core install --url=http://example.org --title="Convoca pruebas" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email >/dev/null
# El núcleo PRIMERO: los demás plugins dependen de él y su activación falla sin Convoca Core.
if [ -d "$DATOS/wp/wp-content/plugins/convoca-core" ] && [ "$SLUG" != "convoca-core" ]; then
	wp --path="$DATOS/wp" plugin activate convoca-core >/dev/null
fi
wp --path="$DATOS/wp" plugin activate "$SLUG" >/dev/null

echo
echo "Entorno listo. Para la suite:"
echo "  CONVOCA_WP_ROOT=$DATOS/wp vendor/bin/phpunit -c phpunit-integration.xml"
