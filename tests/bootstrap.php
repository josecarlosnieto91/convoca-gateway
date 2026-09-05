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
}

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
