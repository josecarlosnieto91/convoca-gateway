<?php
/**
 * El nombre de la función que construye la URL de pago (convoca-core#16).
 *
 * Se llamaba `get_payment_link()`, que no decía lo que hace y encima se confundía con
 * `Core\Utils::get_payment_link()`, que NO construye ninguna URL: CREA el pago y devuelve un array o un
 * error. Quien llamaba a una creyendo llamar a la otra no lo descubría hasta que fallaba.
 *
 * Lo que este contrato fija, y por qué:
 *
 *  - el nombre que usa el código es `build_payment_url()`;
 *  - `get_payment_link()` sigue existiendo como alias que delega, porque es API que otros plugins del
 *    ecosistema llaman desde antes: se cambia el nombre, no el comportamiento;
 *  - DENTRO de la clase no queda ninguna llamada al nombre viejo: si vuelve una, el test cae.
 *
 * Probado en negativo sobre el fichero de HEAD (donde sí hay llamadas internas al nombre viejo): las
 * aserciones de llamada fallan contra esa versión.
 *
 * @package Convoca\Gateway\Tests
 */

namespace Convoca\Gateway\Tests\Unit;

/**
 * Contrato del nombre de `build_payment_url()` y de su alias.
 */
class NombreDeLaUrlDePagoTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Fuente de la clase, tal cual está en el repositorio.
	 *
	 * @return string
	 */
	private function fuente(): string {
		$ruta = dirname( __DIR__, 2 ) . '/includes/Payment_Handler.php';
		$this->assertFileExists( $ruta, 'No encuentro includes/Payment_Handler.php' );

		return (string) file_get_contents( $ruta );
	}

	/**
	 * El nombre nuevo es el que está declarado en la clase.
	 */
	public function test_build_payment_url_es_el_nombre_declarado(): void {
		$this->assertStringContainsString(
			'function build_payment_url(',
			$this->fuente(),
			'La clase no declara build_payment_url(): el nombre que construye la URL de pago.'
		);
	}

	/**
	 * El alias sigue ahí y delega en el nombre nuevo (no al revés).
	 */
	public function test_el_alias_existe_y_delega(): void {
		$fuente = $this->fuente();

		$this->assertSame(
			1,
			substr_count( $fuente, 'function get_payment_link(' ),
			'El alias de compatibilidad tiene que estar declarado una vez (ni cero ni dos).'
		);

		$this->assertMatchesRegularExpression(
			'/function get_payment_link\([^)]*\)[^{]*\{\s*return self::build_payment_url\(/s',
			$fuente,
			'El alias tiene que delegar en build_payment_url(): el cuerpo tiene que ser solo esa llamada.'
		);
	}

	/**
	 * Ninguna llamada interna usa el nombre viejo: se llama al nombre nuevo.
	 */
	public function test_ninguna_llamada_interna_usa_el_nombre_viejo(): void {
		$this->assertSame(
			0,
			preg_match_all( '/\b(?:self|static|parent)::get_payment_link\s*\(/', $this->fuente() ),
			'Queda alguna llamada interna a get_payment_link(): dentro de la clase se llama a build_payment_url().'
		);
	}

	/**
	 * Y en el resto de la pasarela tampoco: el nombre viejo solo vive en el alias.
	 */
	public function test_el_resto_del_plugin_ya_no_llama_al_nombre_viejo(): void {
		$raiz      = dirname( __DIR__, 2 );
		$directorios = array( $raiz . '/includes', $raiz . '/admin', $raiz . '/public' );
		$llamadas  = array();

		foreach ( $directorios as $directorio ) {
			if ( ! is_dir( $directorio ) ) {
				continue;
			}
			$iterador = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directorio ) );
			foreach ( $iterador as $fichero ) {
				if ( ! $fichero->isFile() || 'php' !== $fichero->getExtension() ) {
					continue;
				}
				$texto = (string) file_get_contents( $fichero->getPathname() );
				if ( preg_match_all( '/Payment_Handler::get_payment_link\s*\(/', $texto ) ) {
					$llamadas[] = str_replace( $raiz . '/', '', $fichero->getPathname() );
				}
			}
		}

		$this->assertSame(
			array(),
			$llamadas,
			'Hay ficheros que siguen llamando a Payment_Handler::get_payment_link(): usa build_payment_url().'
		);
	}
}
