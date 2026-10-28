<?php
/**
 * Candado de licencia del cobro.
 *
 * El cobro en sí (crear un pago) es lo que se vende. Lo que devuelve el banco —la notificación de
 * Redsys y las páginas OK/KO— **no pasa nunca por aquí**: si la licencia caduca a mitad de un cobro,
 * el dinero se cobra igual y hay que registrarlo.
 *
 * @package Convoca\Gateway
 */

namespace Convoca\Gateway;

/**
 * Decide si esta instalación puede crear cobros nuevos.
 */
class License_Gate {

	public const OPTION_TRIAL_START = 'convoca_gateway_trial_start';
	public const OPTION_FINGERPRINT = 'convoca_gateway_site_fingerprint';
	public const TRIAL_DAYS         = 14;

	/**
	 * ¿Hay una licencia que cubra el gateway?
	 *
	 * Sin el plugin Core cargado no se bloquea nada: el gateway puede vivir sin él y no es
	 * razonable romper el cobro de una instalación por un plugin que no está.
	 */
	public static function tiene_licencia(): bool {
		if ( ! class_exists( '\Convoca\Core\License_Manager' ) ) {
			return true;
		}

		return (bool) \Convoca\Core\License_Manager::has_pro( 'gateway' );
	}

	/**
	 * Huella del sitio: sirve para que borrar la fecha de la prueba no la reinicie.
	 */
	public static function huella(): string {
		$base = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
		$sal  = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';

		return substr( hash( 'sha256', $base . '|' . $sal ), 0, 32 );
	}

	/**
	 * ¿Se puede crear un cobro nuevo?
	 */
	public static function puede_cobrar(): bool {
		if ( self::tiene_licencia() ) {
			return true;
		}

		$inicio = (int) get_option( self::OPTION_TRIAL_START, 0 );
		if ( $inicio > 0 ) {
			return self::dias_restantes() > 0;
		}

		// Sin fecha pero con huella: la prueba se gastó y alguien borró la marca.
		$guardada = (string) get_option( self::OPTION_FINGERPRINT, '' );

		return '' === $guardada;
	}

	/**
	 * Marca el primer cobro. No hace nada si ya había fecha o licencia.
	 */
	public static function registrar_uso(): void {
		if ( self::tiene_licencia() ) {
			return;
		}

		if ( (int) get_option( self::OPTION_TRIAL_START, 0 ) > 0 ) {
			return;
		}

		update_option( self::OPTION_TRIAL_START, time() );
		update_option( self::OPTION_FINGERPRINT, self::huella() );
	}

	/**
	 * Días de prueba que quedan.
	 */
	public static function dias_restantes(): int {
		$inicio = (int) get_option( self::OPTION_TRIAL_START, 0 );
		if ( $inicio <= 0 ) {
			return self::TRIAL_DAYS;
		}

		$pasados = (int) floor( ( time() - $inicio ) / DAY_IN_SECONDS );

		return max( 0, self::TRIAL_DAYS - $pasados );
	}

	/**
	 * ¿Está en periodo de prueba (sin licencia y con fecha)?
	 */
	public static function en_prueba(): bool {
		if ( self::tiene_licencia() ) {
			return false;
		}

		return (int) get_option( self::OPTION_TRIAL_START, 0 ) > 0 && self::dias_restantes() > 0;
	}

	/**
	 * Estado para la pantalla de ajustes.
	 *
	 * @return array{estado: string, dias: int, puede: bool}
	 */
	public static function estado(): array {
		if ( self::tiene_licencia() ) {
			return array(
				'estado' => 'licencia',
				'dias'   => 0,
				'puede'  => true,
			);
		}

		return array(
			'estado' => self::en_prueba() ? 'prueba' : ( self::puede_cobrar() ? 'sin_empezar' : 'caducada' ),
			'dias'   => self::dias_restantes(),
			'puede'  => self::puede_cobrar(),
		);
	}

	/**
	 * Mensaje para el visitante cuando el cobro está bloqueado.
	 */
	public static function aviso_publico(): string {
		return __( 'Este formulario de pago no está disponible en este momento.', 'convoca-gateway' );
	}

	/**
	 * Mensaje para el administrador.
	 */
	public static function aviso_admin(): string {
		$url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=convoca-license' ) : '';

		return sprintf(
			/* translators: %s: enlace a la pantalla de licencia */
			__( 'La prueba de 14 días del cobro ha terminado. Los pagos ya creados siguen funcionando; para crear nuevos, activa una licencia en %s.', 'convoca-gateway' ),
			$url
		);
	}
}
