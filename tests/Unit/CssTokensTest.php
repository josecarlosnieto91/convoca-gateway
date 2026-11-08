<?php
/**
 * El CSS del producto no depende de la paleta del tema.
 *
 * Los presets `--wp--preset--*` y los colores de un cliente concreto se sustituyeron por los tokens
 * `--conv-*`, con valor neutro por defecto. Esta prueba falla si vuelven a colarse: en un tema ajeno, un
 * preset ausente pintaba la interfaz con la paleta de otro sitio.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

final class CssTokensTest extends TestCase {

	/**
	 * Ni presets ni colores de la paleta de un cliente en el CSS.
	 */
	public function test_el_css_no_depende_del_tema_ni_de_una_paleta(): void {
		$raiz  = dirname( __DIR__, 2 );
		$fallos = array();

		foreach ( $this->hojas( $raiz ) as $hoja ) {
			$css = (string) file_get_contents( $hoja );

			if ( preg_match( '/--wp--preset--/', $css ) ) {
				$fallos[] = $hoja . ': usa un preset del tema';
			}

			if ( preg_match( '/#(?:320028|7d0032|ff8700|ffab00|ff500a)\b/i', $css ) ) {
				$fallos[] = $hoja . ': lleva un color de la paleta de un cliente';
			}
		}

		$this->assertSame( array(), $fallos, implode( "\n", $fallos ) );
	}

	/**
	 * El PHP tampoco lleva la paleta de un cliente (#16).
	 */
	public function test_el_php_no_lleva_colores_de_la_paleta(): void {
		$raiz   = dirname( __DIR__, 2 );
		$fallos = array();

		$iterador = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterador as $fichero ) {
			$ruta = $fichero->getPathname();
			if ( ! str_ends_with( $ruta, '.php' ) || preg_match( '#/(vendor|tests|node_modules|build)/#', $ruta ) ) {
				continue;
			}
			if ( preg_match( '/#(?:320028|7d0032|ff8700|ffab00|ff500a|2d5a27)\b/i', (string) file_get_contents( $ruta ) ) ) {
				$fallos[] = $ruta;
			}
		}

		$this->assertSame( array(), $fallos, 'Colores de cliente en PHP: ' . implode( ', ', $fallos ) );
	}

	/**
	 * Los tokens están declarados con un valor neutro.
	 */
	public function test_los_tokens_tienen_valor_por_defecto(): void {
		$css = '';
		foreach ( $this->hojas( dirname( __DIR__, 2 ) ) as $hoja ) {
			$css .= (string) file_get_contents( $hoja );
		}

		foreach ( array( 'bg', 'fg', 'muted', 'border', 'accent', 'accent-strong', 'highlight', 'ok', 'accent-alt' ) as $token ) {
			$this->assertMatchesRegularExpression(
				'/--conv-' . preg_quote( $token, '/' ) . ':\s*#/',
				$css,
				'Falta el token --conv-' . $token . ' con valor neutro.'
			);
		}
	}

	/**
	 * Hojas de estilo del plugin (sin vendor ni build).
	 *
	 * @param string $raiz Raíz del plugin.
	 * @return array<int, string>
	 */
	private function hojas( string $raiz ): array {
		$out = array();

		foreach ( array( 'assets/css', 'public/assets', 'assets' ) as $sub ) {
			$dir = $raiz . '/' . $sub;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( glob( $dir . '/*.css' ) ?: array() as $css ) {
				$out[] = $css;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
