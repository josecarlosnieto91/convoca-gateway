<?php
/**
 * Doble de Convoca\Core\Mailer para las pruebas unitarias.
 *
 * El Mailer real NO pasa el destinatario tal cual: lo normaliza a un array de
 * correos validos y unicos (normalize_recipients) y eso es lo que entrega a
 * wp_mail. El doble hace lo mismo para que las pruebas midan el mismo
 * comportamiento y no uno inventado.
 */
namespace Convoca\Core;

final class Mailer {
    public static function send( $to, string $subject, string $body, array $args = array() ): bool {
        $recipients = array();
        foreach ( (array) $to as $one ) {
            $one = sanitize_email( (string) $one );
            if ( is_email( $one ) ) {
                $recipients[] = $one;
            }
        }
        $recipients = array_values( array_unique( $recipients ) );
        if ( empty( $recipients ) ) {
            return false;
        }
        return (bool) wp_mail( $recipients, $subject, $body, $args['headers'] ?? array() );
    }
}
