<?php
/**
 * Comprobación de contrato: los catálogos de traducción compilan, y el `.mo` que se publica es el suyo.
 *
 * Por qué existe: medido el 15/10/2026, `languages/convoca-gateway-en_US.po` **no compilaba**. Dos entradas
 * plural tenían el `msgstr` sin el índice `[0]`/`[1]`, que es error de sintaxis: `msgfmt` fallaba con dos
 * errores fatales y el `.mo` no se podía reconstruir con la herramienta estándar. El `.mo` que viajaba en el
 * repo lo había escrito WP-CLI, que lo tolera, así que el fallo no se veía por ningún lado: un catálogo roto
 * podía viajar, publicarse y dejar la traducción a medias sin que nada lo dijera.
 *
 * Qué comprueba:
 *   1. `msgfmt -c` (con comprobación de formato) compila cada `.po` y cada `.pot` de `languages/`.
 *   2. El `.mo` de cada `.po` está en el repo y coincide byte a byte con el recién compilado. `msgfmt` es
 *      determinista —verificado el 15/10/2026 con dos compilaciones del mismo fichero— así que la comparación
 *      es exacta y un `.mo` desfasado se ve.
 *
 * Prueba en negativo: un `.po` con el `msgstr` sin índice de plural (el fallo real que esto vigila) hace
 * fallar la comprobación; y un `.mo` que no corresponda a su `.po`, también.
 *
 * Si `msgfmt` no está instalado la comprobación FALLA, con el motivo escrito: un contrato que se salta a sí
 * mismo cuando falta la herramienta no vigila nada.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class CatalogoDeTraduccionTest extends TestCase {

	/**
	 * Raíz del plugin (donde están `languages/` y `composer.json`).
	 */
	private function raiz(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Ficheros de catálogo de una extensión, dentro de `languages/`.
	 *
	 * @param string $extension po, pot o mo.
	 * @return array<int, string> Rutas absolutas.
	 */
	private function catalogos( string $extension ): array {
		return array_values( array_filter( (array) glob( $this->raiz() . '/languages/*.' . $extension ), 'is_file' ) );
	}

	/**
	 * Ejecuta msgfmt y devuelve el código de salida y la salida completa (sin perder el porqué del fallo).
	 *
	 * @param array<int, string> $argumentos Argumentos, sin el propio `msgfmt`.
	 * @return array{0: int, 1: string}
	 */
	private function msgfmt( array $argumentos ): array {
		$comando = 'msgfmt ' . implode( ' ', array_map( 'escapeshellarg', $argumentos ) ) . ' 2>&1';
		$salida  = array();
		$codigo  = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- herramienta de compilación en un test, sin entrada de usuario.
		exec( $comando, $salida, $codigo );
		return array( $codigo, implode( "\n", $salida ) );
	}

	public function test_msgfmt_esta_disponible(): void {
		list( $codigo, $salida ) = $this->msgfmt( array( '--version' ) );
		$this->assertSame(
			0,
			$codigo,
			'Sin gettext (msgfmt) este contrato no puede vigilar nada, así que se dice en vez de saltarse: ' . $salida
		);
	}

	public function test_los_catalogos_compilan(): void {
		$ficheros = array_merge( $this->catalogos( 'po' ), $this->catalogos( 'pot' ) );
		$this->assertNotEmpty( $ficheros, 'No hay ningún catálogo en languages/: si se han movido, este contrato ya no vigila nada.' );

		foreach ( $ficheros as $fichero ) {
			list( $codigo, $salida ) = $this->msgfmt( array( '-c', '-o', '/dev/null', $fichero ) );
			$this->assertSame(
				0,
				$codigo,
				'msgfmt no compila ' . basename( $fichero ) . ":\n" . $salida
			);
		}
	}

	public function test_el_mo_corresponde_a_su_po(): void {
		foreach ( $this->catalogos( 'po' ) as $po ) {
			$mo = preg_replace( '/\.po$/', '.mo', $po );
			$this->assertFileExists(
				$mo,
				'Hay un .po sin su .mo: el catálogo no llegaría al sitio. Regenéralo con msgfmt -o.'
			);

			$temporal                = tempnam( sys_get_temp_dir(), 'convoca-mo-' );
			list( $codigo, $salida ) = $this->msgfmt( array( '-c', '-o', $temporal, $po ) );
			$this->assertSame( 0, $codigo, 'msgfmt no compila ' . basename( $po ) . ":\n" . $salida );

			$this->assertSame(
				md5_file( $temporal ),
				md5_file( $mo ),
				basename( $mo ) . ' no corresponde a ' . basename( $po ) . ': se quedó viejo. Regenéralo con msgfmt -o.'
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temporal de un test: no hay WP_Filesystem que aplicarle.
			unlink( $temporal );
		}
	}
}
