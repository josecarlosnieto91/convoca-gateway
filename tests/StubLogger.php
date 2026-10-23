<?php
namespace Convoca\Core;

class Logger {
    /** Eventos escritos durante la prueba: [nivel, mensaje, contexto, object_id]. */
    public static $escritos = array();

    /** Lo que devolverá get_logs() en la siguiente llamada. */
    public static $logs = array();

    public static function error($msg, $ctx = '', $object_id = 0) {
        self::$escritos[] = array('error', $msg, $ctx, (int) $object_id);
    }
    public static function info($msg, $ctx = '', $object_id = 0) {
        self::$escritos[] = array('info', $msg, $ctx, (int) $object_id);
    }
    public static function warning($msg, $ctx = '', $object_id = 0) {
        self::$escritos[] = array('warning', $msg, $ctx, (int) $object_id);
    }
    public static function debug($msg, $ctx = '', $object_id = 0) {
        self::$escritos[] = array('debug', $msg, $ctx, (int) $object_id);
    }

    /**
     * Doble del registro de eventos: se apunta lo escrito para poder comprobarlo.
     *
     * @param string $mensaje   Texto del evento.
     * @param string $nivel     info|warning|error|debug.
     * @param string $contexto  Módulo que lo escribe.
     * @param int    $object_id Objeto afectado.
     * @return void
     */
    public static function log($mensaje, $nivel = 'info', $contexto = '', $object_id = 0) {
        self::$escritos[] = array($nivel, $mensaje, $contexto, (int) $object_id);
    }

    /**
     * Doble de la lectura del registro (la pantalla de detalle pide los eventos del pago).
     *
     * @param array $args Filtros (object_id, limit…).
     * @return array
     */
    public static function get_logs($args = array()) {
        return self::$logs;
    }
}

/** Eventos escritos durante la prueba. */
Logger::$escritos = array();

/** Lo que devolverá get_logs() en la siguiente llamada. */
Logger::$logs = array();
