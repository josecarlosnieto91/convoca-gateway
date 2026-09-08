<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

// WordPress function stubs for unit tests
// Mock $wpdb global
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public $prefix = 'wp_';
        public function get_var($query) { return null; }
        public function get_results($query) { return []; }
        public function query($query) { return true; }
        public function prepare($query, ...$args) { return $query; }
    };
}

// Mock Convoca\Core\Logger
if (!class_exists('Convoca\\Core\\Logger')) {
    require_once __DIR__ . '/StubLogger.php';
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $default; }
    function get_page_by_title($title) { return null; }
    function is_ssl() { return true; }
    function home_url($path = '') { return "https://example.com$path"; }
    function __($s, $domain) { return $s; }
    function esc_html($s) { return $s; }
    function esc_attr($s) { return $s; }
    function esc_url($s) { return $s; }
    function admin_url($path) { return "/wp-admin/$path"; }
    function update_option($key, $value, $autoload = null) { return true; }
    function delete_option($key) { return true; }
    function current_time($format) { return '2025-01-01 00:00:00'; }
    function wp_next_scheduled($hook, $args = array()) { return false; }
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) { return true; }
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
    function wp_remote_post($url, $args = array()) { return new WP_Error('stub', 'no network in unit tests'); }
    function wp_remote_retrieve_response_code($response) { return is_wp_error($response) ? 0 : 200; }
    function wp_remote_retrieve_body($response) { return is_wp_error($response) ? '' : ''; }
}

// WP_Error stub (also declared global for namespace fallback).
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $errors = array();
        public function __construct($code = '', $message = '', $data = '') {
            if ($code) { $this->errors[$code] = array($message); }
        }
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return empty($codes) ? '' : $codes[0];
        }
        public function get_error_message() {
            if (empty($this->errors)) { return ''; }
            $code = $this->get_error_code();
            return $this->errors[$code][0] ?? '';
        }
        public function add($code, $message, $data = '') { $this->errors[$code][] = $message; }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
