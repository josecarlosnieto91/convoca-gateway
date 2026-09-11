<?php

/**
 * Doble de Convoca\Core\Utils para las pruebas unitarias del Gateway.
 *
 * El núcleo no está cargado en las pruebas, y el manejador de notificaciones lo usa
 * para lanzar sus ganchos. Se anota lo que se lanza, para poder comprobarlo.
 */

namespace Convoca\Core;

if ( ! class_exists( 'Convoca\\Core\\Utils' ) ) {

	class Utils {

		/** Ganchos lanzados durante la prueba. */
		public static array $lanzados = array();

		/**
		 * Doble de Utils::do_action.
		 *
		 * @param mixed ...$args Argumentos del gancho.
		 * @return null
		 */
		public static function do_action( ...$args ) {
			self::$lanzados[] = $args;
			return null;
		}
	}
}
