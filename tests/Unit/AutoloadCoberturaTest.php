<?php
/**
 * Toda clase, trait o interfaz del plugin tiene que estar en el autoload.
 *
 * El 01/11/2026 un trait nuevo quedó fuera del classmap y tumbó un sitio en producción
 * con «Trait not found». Sin un test, el fallo solo aparece cuando ya hay usuarios.
 *
 * Se pregunta al propio cargador de Composer (`findFile`), no a un regex sobre el mapa:
 * así el resultado es el mismo que tendrá el sitio.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Cobertura del autoload de Composer.
 */
final class AutoloadCoberturaTest extends TestCase {

	/**
	 * Clases que se cargan a mano con require_once y por eso no están en el mapa.
	 *
	 * Cada una tiene que seguir apareciendo en un require del arranque; si alguien quita ese
	 * require, el test falla. El valor es el fichero donde se carga.
	 *
	 * @var array<string, string>
	 */
	private const CARGA_PROPIA = array();

	/**
	 * Ninguna declaración del plugin se queda sin fichero resoluble.
	 */
	public function test_todas_las_clases_se_autocargan(): void {
		$raiz = dirname( __DIR__, 2 );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- es el cargador, no un global de WordPress.
		$cargador = require $raiz . '/vendor/autoload.php';

		$arranque    = '';
		$principales = glob( $raiz . '/*.php' );

		foreach ( is_array( $principales ) ? $principales : array() as $principal ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lectura local de los ficheros del propio plugin en una prueba.
			$arranque .= (string) file_get_contents( $principal );
		}

		$faltan = array();

		$iterador = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $raiz, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterador as $fichero ) {
			$ruta = (string) $fichero->getPathname();

			// Los stubs de PHPStan declaran clases que no existen en el plugin: no cuentan.
			$es_stub = '.stub.php' === substr( $ruta, -9 ) || 'phpstan-bootstrap.php' === basename( $ruta );

			if ( $es_stub || '.php' !== substr( $ruta, -4 ) ) {
				continue;
			}

			$partes = explode( '/', str_replace( '\\', '/', $ruta ) );
			if ( in_array( 'vendor', $partes, true ) || in_array( 'tests', $partes, true ) || in_array( 'node_modules', $partes, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- idem.
			$codigo = (string) file_get_contents( $ruta );

			preg_match( '/^\s*namespace\s+([^;{\s]+)\s*;/m', $codigo, $ns );
			$espacio = $ns[1] ?? '';

			if ( ! preg_match_all( '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $codigo, $decl ) ) {
				continue;
			}

			foreach ( $decl[1] as $nombre ) {
				$fq = ltrim( '' === $espacio ? $nombre : $espacio . '\\' . $nombre, '\\' );

				if ( isset( self::CARGA_PROPIA[ $fq ] ) ) {
					// La excepción vale solo si el require sigue ahí.
					$this->assertStringContainsString(
						basename( self::CARGA_PROPIA[ $fq ] ),
						$arranque,
						$fq . ' no está en el autoload y tampoco se carga a mano'
					);
					continue;
				}

				if ( false === $cargador->findFile( $fq ) ) {
					$faltan[] = $fq . ' (' . str_replace( $raiz . '/', '', $ruta ) . ')';
				}
			}
		}

		$this->assertSame(
			array(),
			$faltan,
			'estas clases no las conoce el autoload: ' . implode( ', ', $faltan )
		);
	}
}
