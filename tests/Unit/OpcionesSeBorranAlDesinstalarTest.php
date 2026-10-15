<?php
/**
 * Contrato de desinstalación: toda opción que este plugin crea tiene que borrarse al desinstalarlo.
 *
 * Por qué existe: la auditoría de la pasada 8 midió que varios plugins borraban lo principal y se dejaban
 * opciones que ellos mismos crean. La más delicada era una sal de firma que se quedaba en la base para siempre.
 * Es el mismo defecto que la prueba de ganchos programados, con las opciones en vez de las tareas.
 *
 * Las claves se **leen del código**, no se escriben aquí: una lista escrita a mano se queda vieja en cuanto
 * alguien añade una opción, que es exactamente cómo apareció este defecto.
 *
 * Excepciones: solo las que se documentan aquí con su motivo, y ninguna debería hacer falta.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class OpcionesSeBorranAlDesinstalarTest extends TestCase {

	/** Opciones que, a propósito, no se borran. Clave => motivo. */
	private const EXCEPCIONES = array(
		// Ajenas: son ajustes de OTROS plugins de la familia. Que las borre su propio
		// uninstall.php; borrarlas desde aquí significaría perder los ajustes de un plugin que
		// sigue instalado.
		'convoca_members_plans'         => 'ajuste de convoca-members',
		'convoca_gateway_settings'      => 'ajuste de convoca-gateway',
		'convoca_shifts_hora_apertura'  => 'ajuste de convoca-shifts',
		'convoca_shifts_hora_cierre'    => 'ajuste de convoca-shifts',
	);

	private function raiz(): string {
		return dirname( __DIR__, 2 );
	}

	/** Las opciones que el plugin crea, leídas de su código. */
	private function opcionesCreadas(): array {
		$claves = array();
		$iter   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->raiz() ) );
		foreach ( $iter as $f ) {
			$ruta = $f->getPathname();
			if ( 'php' !== strtolower( $f->getExtension() ) ) {
				continue;
			}
			if ( false !== strpos( $ruta, '/vendor/' ) || false !== strpos( $ruta, '/node_modules/' ) || false !== strpos( $ruta, '/tests/' ) ) {
				continue;
			}
			$txt = (string) file_get_contents( $ruta );

			// update_option() y add_option(): el primer literal es la clave.
			// register_setting( 'grupo', 'opcion' ): el primero es el GRUPO, no una opción. Contarlos
			// como opciones —que es lo que hacía la primera versión de este escáner— dejaba la prueba
			// vigilando lo que no debía y no viendo lo que sí (issue #10).
			$patrones = array(
				'/(?:update_option|add_option)\s*\(\s*\'([a-z0-9_]+)\'/i',
				'/register_setting\s*\(\s*\'[a-z0-9_]+\'\s*,\s*\'([a-z0-9_]+)\'/i',
			);
			foreach ( $patrones as $patron ) {
				if ( preg_match_all( $patron, $txt, $m ) ) {
					foreach ( $m[1] as $clave ) {
						if ( 0 === strpos( $clave, 'convoca_' ) ) {
							$claves[ $clave ] = true;
						}
					}
				}
			}
		}

		return array_keys( $claves );
	}


	public function test_todas_las_opciones_del_plugin_se_borran(): void {
		$desinstalador = (string) file_get_contents( $this->raiz() . '/uninstall.php' );
		$faltan        = array();

		foreach ( $this->opcionesCreadas() as $clave ) {
			if ( isset( self::EXCEPCIONES[ $clave ] ) ) {
				continue;
			}
			if ( false === strpos( $desinstalador, $clave ) ) {
				$faltan[] = $clave;
			}
		}

		$this->assertSame(
			array(),
			$faltan,
			"El uninstall.php no borra estas opciones que el plugin crea:\n  - " . implode( "\n  - ", $faltan )
		);
	}

	public function test_se_borran_de_verdad_y_no_solo_en_la_lista(): void {
		$desinstalador = (string) file_get_contents( $this->raiz() . '/uninstall.php' );
		$lineas        = preg_split( '/\R/', $desinstalador );

		foreach ( $this->opcionesCreadas() as $clave ) {
			if ( isset( self::EXCEPCIONES[ $clave ] ) ) {
				continue;
			}

			$suelta = false;
			foreach ( $lineas as $linea ) {
				if ( 0 === strpos( $linea, "delete_option( '" . $clave . "'" ) ) {
					$suelta = true;
				}
			}

			// También vale dentro del array `$options = array( ... )`, que es una lista.
			$en_lista = false;
			if ( preg_match( '/\$options = array\((.*?)\);/s', $desinstalador, $bloque ) ) {
				$en_lista = false !== strpos( $bloque[1], "'" . $clave . "'" );
			}

			$this->assertTrue(
				$suelta || $en_lista,
				"«{$clave}» no se borra a nivel de fichero: si está dentro de un bucle o de un `if`, solo se borra a veces (issue #10)."
			);
		}
	}
}
