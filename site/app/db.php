<?php
// PDO connection, forced to utf8mb4 and to the same timezone as PHP (config 'timezone', Istanbul by default),
// so NOW() in SQL and time() in PHP agree on when a scheduled post goes live.
function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = config('db');
    $offset = (new DateTimeImmutable('now'))->format('P');   // e.g. +03:00
    $pdo = new PDO(
        "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
        $c['user'],
        $c['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '$offset'",
        ]
    );
    return $pdo;
}
