<?php
/**
 * El exportador de CSV de la pasarela usa el escapado del ecosistema, no una copia (issue convoca-core#16).
 *
 * El exportador de pagos tenía la protección contra **inyección de fórmulas** escrita a mano:
 *
 * ```php
 * if ( in_array( $field[0], array( '=', '+', '-', '@' ), true ) ) { $field = "'" . $field; }
 * ```
 *
 * Es la misma idea que `Core\Utils::escape_csv_field()`, pero **más corta**: le faltaban el tabulador, el
 * retorno de carro y el punto y coma. Y como el CSV del ecosistema se escribe con `;` de separador, esa
 * diferencia no es cosmética. `enroll` y `members` ya usaban el ayudante; la pasarela era el único que
 * decidía por su cuenta qué es peligroso.
 *
 * El contrato mira DOS cosas: que el exportador llame al ayudante, y que **no vuelva a haber aquí una
 * segunda implementación** de la regla (la huella de la copia es `$field[0]`). Probado en negativo: contra el
 * fichero anterior, las dos aserciones caen.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests\Unit;

use PHPUnit\Framework\TestCase;

class EscapadoDelCsvTest extends TestCase {

	/**
	 * El exportador, tal cual está en el repositorio.
	 *
	 * @return string
	 */
	private function fuente(): string {
		$ruta = dirname( __DIR__, 2 ) . '/includes/CSV_Exporter.php';
		$this->assertFileExists( $ruta );

		return (string) file_get_contents( $ruta );
	}

	/**
	 * Usa el ayudante del ecosistema.
	 */
	public function test_usa_el_escapado_del_ecosistema(): void {
		$this->assertStringContainsString(
			'escape_csv_field',
			$this->fuente(),
			'El exportador tiene que escapar con Core\Utils::escape_csv_field(), que es el de enroll y members.'
		);
	}

	/**
	 * Y no decide por su cuenta qué caracteres son peligrosos.
	 */
	public function test_no_reimplementa_la_regla(): void {
		$this->assertStringNotContainsString(
			'$field[0]',
			$this->fuente(),
			'La regla de qué es peligroso no se escribe en este fichero: si aparece `$field[0]`, es la copia que ' .
			'cubría menos caracteres que el ayudante común.'
		);
	}
}
