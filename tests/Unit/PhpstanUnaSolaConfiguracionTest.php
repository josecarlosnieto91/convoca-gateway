<?php
/**
 * Comprobación de contrato: el repo tiene UNA sola configuración de PHPStan, y es la que usa el CI.
 *
 * Por qué existe (convoca-health-check#15): convoca-gateway tenía dos. La del CI (`phpstan.neon`) es la
 * que analiza el código de verdad, y además es la que resuelve el `.dist`; la copia vieja de
 * `dev-tools/`, que **nadie usaba**, da varios «The path … does not exist» (apunta a ficheros del monorepo que aquí no están). Medido el 15/10/2026. Dos varas para el mismo
 * código: el verde deja de significar algo.
 *
 * Dos varas para el mismo código: el verde deja de significar algo.
 *
 * Prueba en negativo: si vuelve a aparecer un segundo fichero de configuración (en la raíz **o en
 * `dev-tools/`**), o la del CI deja de ser la que existe, esta comprobación falla y la nombra.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class PhpstanUnaSolaConfiguracionTest extends TestCase {

	private function raiz(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Configuraciones de PHPStan presentes en el repo: la raíz y `dev-tools/`.
	 *
	 * `dev-tools/` entra a propósito: la copia que este contrato existe para evitar vivía justo ahí, y una
	 * comprobación que solo mira la raíz no la ve.
	 *
	 * @return array<int, string> Rutas relativas a la raíz del repositorio.
	 */
	private function configuraciones(): array {
		$encontradas = array();
		$candidatas  = array_merge(
			(array) glob( $this->raiz() . '/phpstan.neon*' ),
			(array) glob( $this->raiz() . '/dev-tools/phpstan.neon*' )
		);
		foreach ( $candidatas as $f ) {
			if ( is_file( $f ) ) {
				$encontradas[] = str_replace( $this->raiz() . '/', '', $f );
			}
		}
		sort( $encontradas );

		return $encontradas;
	}

	/** La configuración que usa el CI, leída del propio workflow. */
	private function configuracionDelCi(): string {
		$yml = (string) file_get_contents( $this->raiz() . '/.github/workflows/ci.yml' );
		if ( preg_match( '/phpstan analyse[^\n]*--configuration=([^\s]+)/', $yml, $m ) ) {
			return $m[1];
		}

		return 'phpstan.neon';
	}

	public function test_hay_una_sola_configuracion_de_phpstan(): void {
		$cfgs = $this->configuraciones();
		$this->assertCount(
			1,
			$cfgs,
			'Debe haber una única configuración de PHPStan. Encontradas: ' . implode( ', ', $cfgs )
		);
	}

	public function test_la_configuracion_del_ci_es_la_que_hay(): void {
		$cfgs = $this->configuraciones();
		if ( empty( $cfgs ) ) {
			$this->fail( 'No hay ninguna configuración de PHPStan: el job del CI no analizaría nada' );
		}
		$this->assertContains(
			$this->configuracionDelCi(),
			$cfgs,
			'El CI debe usar la configuración que existe en el repo'
		);
	}
}
