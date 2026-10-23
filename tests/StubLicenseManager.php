<?php
/**
 * Doble de Convoca\Core\License_Manager para las pruebas del gateway.
 *
 * El real consulta la licencia del núcleo; aquí solo hace falta poder decir si hay PRO, que es lo
 * que decide si un ajuste se pinta y se guarda.
 */
namespace Convoca\Gateway\Tests;

class License_Manager_Doble
{
    public static bool $pro = false;

    public static function has_pro( string $plugin = '' ): bool
    {
        return self::$pro;
    }
}
