<?php
define('WP_DEBUG', true);
define('ABSPATH', dirname(__DIR__) . '/');
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
