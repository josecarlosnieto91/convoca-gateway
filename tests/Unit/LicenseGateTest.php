<?php

/**
 * Candado del cobro: licencia, prueba de 14 días y las dos excepciones que no se tocan.
 *
 * Lo que se prueba aquí no es «que bloquee», es **cuándo** bloquea: un candado que corta un pago
 * a medias es peor que no tener candado.
 */

namespace Convoca\Gateway\Tests;

use Convoca\Gateway\License_Gate;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/License_Gate.php';

class LicenseGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__gw_options']         = array();
		License_Manager_Doble::$pro = false;
	}

	private function con_licencia( bool $si ): void {
		License_Manager_Doble::$pro = $si;
	}

	private function prueba_iniciada_hace( int $dias ): void {
		$GLOBALS['__gw_options'][ License_Gate::OPTION_TRIAL_START ] = time() - ( $dias * DAY_IN_SECONDS );
		$GLOBALS['__gw_options'][ License_Gate::OPTION_FINGERPRINT ] = License_Gate::huella();
	}

	public function test_sin_marcas_se_puede_cobrar(): void {
		$this->assertTrue( License_Gate::puede_cobrar(), 'Quien no ha empezado todavía tiene que poder probar.' );
		$this->assertSame( License_Gate::TRIAL_DAYS, License_Gate::dias_restantes() );
	}

	public function test_en_prueba_dentro_del_plazo_puede(): void {
		$this->prueba_iniciada_hace( 3 );
		$this->assertTrue( License_Gate::en_prueba() );
		$this->assertTrue( License_Gate::puede_cobrar() );
		$this->assertSame( 11, License_Gate::dias_restantes() );
	}

	public function test_el_ultimo_dia_puede(): void {
		$this->prueba_iniciada_hace( 13 );
		$this->assertTrue( License_Gate::puede_cobrar() );
		$this->assertSame( 1, License_Gate::dias_restantes() );
	}

	public function test_caducada_no_puede(): void {
		$this->prueba_iniciada_hace( 14 );
		$this->assertFalse( License_Gate::puede_cobrar() );
		$this->assertSame( 0, License_Gate::dias_restantes() );
	}

	public function test_dias_restantes_nunca_es_negativo(): void {
		$this->prueba_iniciada_hace( 90 );
		$this->assertSame( 0, License_Gate::dias_restantes() );
	}

	public function test_borrar_la_fecha_no_reinicia_la_prueba(): void {
		// Huella guardada, fecha borrada: la prueba se gastó antes.
		$GLOBALS['__gw_options'][ License_Gate::OPTION_FINGERPRINT ] = License_Gate::huella();
		$this->assertFalse( License_Gate::puede_cobrar() );
	}

	public function test_registrar_uso_guarda_fecha_y_huella(): void {
		License_Gate::registrar_uso();
		$this->assertGreaterThan( 0, (int) ( $GLOBALS['__gw_options'][ License_Gate::OPTION_TRIAL_START ] ?? 0 ) );
		$this->assertSame( License_Gate::huella(), $GLOBALS['__gw_options'][ License_Gate::OPTION_FINGERPRINT ] ?? '' );
	}

	public function test_registrar_uso_no_reinicia_si_ya_empezo(): void {
		$this->prueba_iniciada_hace( 10 );
		$antes = $GLOBALS['__gw_options'][ License_Gate::OPTION_TRIAL_START ];
		License_Gate::registrar_uso();
		$this->assertSame( $antes, $GLOBALS['__gw_options'][ License_Gate::OPTION_TRIAL_START ] );
	}

	public function test_con_licencia_se_puede_aunque_la_prueba_este_caducada(): void {
		$this->prueba_iniciada_hace( 60 );
		$this->con_licencia( true );
		$this->assertTrue( License_Gate::puede_cobrar() );
		$this->assertSame( 'licencia', License_Gate::estado()['estado'] );
	}

	public function test_con_licencia_no_se_registra_prueba(): void {
		$this->con_licencia( true );
		License_Gate::registrar_uso();
		$this->assertArrayNotHasKey( License_Gate::OPTION_TRIAL_START, $GLOBALS['__gw_options'] );
	}

	public function test_estado_refleja_los_tres_casos(): void {
		$this->assertSame( 'sin_empezar', License_Gate::estado()['estado'] );

		$this->prueba_iniciada_hace( 2 );
		$this->assertSame( 'prueba', License_Gate::estado()['estado'] );

		$this->prueba_iniciada_hace( 30 );
		$this->assertSame( 'caducada', License_Gate::estado()['estado'] );
	}
}
