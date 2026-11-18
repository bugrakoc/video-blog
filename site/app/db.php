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
    $port = isset($c['port']) ? ';port=' . (int)$c['port'] : '';
    $offset = (new DateTimeImmutable('now'))->format('P');   // e.g. +03:00
    $pdo = new PDO(
        "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4$port",
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

const CONTENT_LOCK = 'content';

/** MariaDB advisory locks are server-wide, so scope them to this site's database. */
function site_lock(string $purpose, int $timeout = 0): bool
{
    $stmt = db()->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([site_lock_name($purpose), $timeout]);
    return (int)$stmt->fetchColumn() === 1;
}

function site_unlock(string $purpose): void
{
    db()->prepare('DO RELEASE_LOCK(?)')->execute([site_lock_name($purpose)]);
}

function site_lock_name(string $purpose): string
{
    return 'video-blog:' . sha1((string)config('db')['name'] . ':' . $purpose);
}

function db_transaction(callable $work)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
