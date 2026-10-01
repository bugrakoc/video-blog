<?php
declare(strict_types=1);

define('APP_PATH', __DIR__);
define('ROOT_PATH', dirname(__DIR__));

ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require APP_PATH . '/config.php';
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

date_default_timezone_set(config('timezone') ?: 'Europe/Istanbul');

if (config('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

require APP_PATH . '/db.php';
require APP_PATH . '/functions.php';
require APP_PATH . '/queries.php';
require APP_PATH . '/Parsedown.php';
