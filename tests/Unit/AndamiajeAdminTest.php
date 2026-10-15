<?php
/**
 * Comprobación de contrato: las pantallas de administración usan el andamiaje compartido y no vuelven a
 * copiarlo (issue convoca-core#16).
 *
 * Por qué existe: `Admin_Settings` tenía su constructor con cinco `add_action` y sus dos `add_submenu_page` a mano, igual que las otras pantallas del ecosistema: al medir el 15/10/2026 eran siete constructores con la misma forma repartidos en cuatro plugins.
 *
 * El contrato tiene dos partes, y las dos se pueden romper a mano para ver el rojo:
 *
 * 1. Cada pantalla declarada aquí engancha sus ganchos por el andamiaje del núcleo
 *    (`Convoca\Core\Admin\Pantalla_Admin`, o su trait `Andamiaje_Admin` cuando la clase ya hereda de
 *    `\WP_List_Table`) y **no declara constructor propio**.
 * 2. No hay dos constructores iguales en el repositorio: si alguien vuelve a copiar el andamiaje, la
 *    copia y su original coinciden y esta comprobación lo dice, con los dos ficheros nombrados.
 *
 * Prueba en negativo (ejecutada el 15/10/2026): volviendo a poner en `Admin_Settings` su `__construct` con los cinco `add_action`, `test_ninguna_pantalla_declara_su_propio_constructor` cae y nombra la clase.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class AndamiajeAdminTest extends TestCase {

	/**
	 * Pantallas de este repositorio: fichero => clase. Es la lista que el contrato vigila; si aparece
	 * una pantalla nueva con el mismo andamiaje, entra aquí.
	 *
	 * @var array<string, string>
	 */
	private const PANTALLAS = array(
		'includes/Admin_Settings.php' => 'Admin_Settings',
	);

	private function raiz(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Ficheros PHP del repositorio, sin dependencias, pruebas ni paquetes generados.
	 *
	 * @return array<int, string>
	 */
	private function ficherosPhp(): array {
		$raiz   = $this->raiz();
		$omitir = array( 'vendor', 'node_modules', 'tests', 'build', 'build-dir', '.git', 'languages', 'dist' );
		$salida = array();

		$iterador = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $raiz, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $actual ) use ( $omitir ): bool {
					return ! in_array( $actual->getFilename(), $omitir, true );
				}
			)
		);

		foreach ( $iterador as $f ) {
			if ( $f instanceof \SplFileInfo && $f->isFile() && 'php' === strtolower( $f->getExtension() ) ) {
				$salida[] = ltrim( str_replace( $raiz, '', $f->getPathname() ), '/' );
			}
		}
		sort( $salida );

		return $salida;
	}

	/**
	 * Cuerpo de una clase, por sus llaves. Un `strpos` sobre el fichero entero no vale: hay ficheros con
	 * dos clases (la pantalla y su tabla) y el contrato habla de una.
	 *
	 * @param string $codigo Fuente PHP.
	 * @param string $clase  Nombre de la clase.
	 * @return string Cuerpo de la clase, o '' si no está.
	 */
	private function cuerpoDeClase( string $codigo, string $clase ): string {
		if ( ! preg_match( '/\b(?:abstract\s+|final\s+)?class\s+' . preg_quote( $clase, '/' ) . '\b/', $codigo, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$desde = (int) $m[0][1];
		$llave = strpos( $codigo, '{', $desde );
		if ( false === $llave ) {
			return '';
		}

		$nivel = 0;
		$largo = strlen( $codigo );
		for ( $i = $llave; $i < $largo; $i++ ) {
			if ( '{' === $codigo[ $i ] ) {
				++$nivel;
			} elseif ( '}' === $codigo[ $i ] ) {
				--$nivel;
				if ( 0 === $nivel ) {
					// Desde la cabecera (el `extends` vive ahí), no desde la llave: comprobar solo el cuerpo
		// dejaría fuera la herencia, que es justo lo que este contrato vigila.
		return substr( $codigo, $desde, $i - $desde + 1 );
				}
			}
		}

		return '';
	}

	/** Cada pantalla declarada engancha sus ganchos por el andamiaje compartido. */
	public function test_las_pantallas_usan_el_andamiaje_compartido(): void {
		foreach ( self::PANTALLAS as $fichero => $clase ) {
			$ruta = $this->raiz() . '/' . $fichero;
			$this->assertFileExists( $ruta, 'La pantalla declarada por el contrato tiene que existir: ' . $fichero );

			$cuerpo = $this->cuerpoDeClase( (string) file_get_contents( $ruta ), $clase );
			$this->assertNotSame( '', $cuerpo, $fichero . ' tiene que declarar la clase ' . $clase );

			$this->assertMatchesRegularExpression(
				'/(extends\s+[\\\\A-Za-z]*Pantalla_Admin|use\s+[\\\\A-Za-z]*Andamiaje_Admin)/',
				$cuerpo,
				$clase . ' tiene que usar el andamiaje de Convoca\\Core\\Admin (herencia o trait)'
			);

			$this->assertStringContainsString(
				'function ganchos()',
				$cuerpo,
				$clase . ' tiene que declarar sus ganchos en ganchos(): si no, no registra nada'
			);
		}
	}

	/** Ninguna pantalla declarada vuelve a escribir el constructor del andamiaje. */
	public function test_ninguna_pantalla_declara_su_propio_constructor(): void {
		foreach ( self::PANTALLAS as $fichero => $clase ) {
			$cuerpo = $this->cuerpoDeClase( (string) file_get_contents( $this->raiz() . '/' . $fichero ), $clase );

			$this->assertStringNotContainsString(
				'function __construct',
				$cuerpo,
				$clase . ' no puede declarar constructor propio: el constructor (con sus add_action) vive en el andamiaje compartido'
			);
		}
	}

	/**
	 * Y en todo el repositorio no hay dos constructores iguales. Copiar el andamiaje deja dos bloques
	 * idénticos; aquí salen nombrados los dos ficheros.
	 */
	public function test_no_hay_dos_constructores_iguales(): void {
		$vistos = array();

		foreach ( $this->ficherosPhp() as $relativo ) {
			$codigo = (string) file_get_contents( $this->raiz() . '/' . $relativo );
			if ( ! preg_match_all( '/function __construct\s*\([^)]*\)\s*\{/', $codigo, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $m[0] as $encontrado ) {
				$desde = (int) $encontrado[1];
				$llave = strpos( $codigo, '{', $desde );
				$nivel = 0;
				$largo = strlen( $codigo );
				for ( $i = $llave; $i < $largo; $i++ ) {
					if ( '{' === $codigo[ $i ] ) {
						++$nivel;
					} elseif ( '}' === $codigo[ $i ] ) {
						--$nivel;
						if ( 0 === $nivel ) {
							break;
						}
					}
				}
				$cuerpo = substr( $codigo, $llave, $i - $llave + 1 );

				// El andamiaje de una pantalla se reconoce porque registra `admin_menu`. Un
				// constructor de una línea (`add_action( 'rest_api_init', … )`) no es esto: el
				// contrato mide el andamiaje de las pantallas, no cualquier coincidencia.
				if ( false === strpos( $cuerpo, 'add_action(' ) || false === strpos( $cuerpo, "'admin_menu'" ) ) {
					continue;
				}

				// Normalizado: el mismo andamiaje con otro espaciado sigue siendo una copia.
				$firma = md5( preg_replace( '/\s+/', '', $cuerpo ) );
				$this->assertArrayNotHasKey(
					$firma,
					$vistos,
					'Hay dos constructores idénticos (andamiaje copiado): ' . ( $vistos[ $firma ] ?? '' ) . ' y ' . $relativo
				);
				$vistos[ $firma ] = $relativo;
			}
		}
	}
}
