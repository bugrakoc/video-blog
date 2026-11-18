<?php
declare(strict_types=1);

// Router for PHP's local development server. Production uses site/.htaccess.
$site = dirname(__DIR__) . '/site';
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
$file = realpath($site . $path);
if ($file !== false && ($file === $site || str_starts_with($file, $site . '/'))) {
    $relative = substr($file, strlen($site));
    if (preg_match('~^/(?:app|cron)(?:/|$)|(?:^|/)\.|\.(?:sql|md|log|ini|sh)$~', $relative)) {
        http_response_code(403);
        return true;
    }
    if ($file !== $site) {
        return false;
    }
}
require $site . '/index.php';
