<?php
namespace Convoca\Core;

/**
 * Doble de Convoca\Core\Mailer para las pruebas.
 *
 * Hace lo mismo que el original en lo unico que importa aqui: ENTREGAR el correo por la via
 * normal (`wp_mail`), que es donde las pruebas lo capturan. Un doble que solo devuelve true se
 * traga los envios y deja pasar pruebas que deberian fallar.
 */
class Mailer {
    public static function send( $to, string $subject, string $body, array $args = array() ): bool {
        $headers = $args['headers'] ?? array();
        return (bool) wp_mail( $to, $subject, $body, $headers );
    }
}
