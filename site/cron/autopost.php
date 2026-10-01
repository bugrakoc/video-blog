<?php
declare(strict_types=1);

/*
 * Run from DirectAdmin → Cron Jobs, e.g. every 30 minutes:
 *   php /home/USER/domains/EXAMPLE.COM/public_html/cron/autopost.php
 *
 * Options:  -v            always print a status line (default: print only on new videos or errors,
 *                         so cron doesn't email you every run)
 *           --import-all  also import videos that exist on the channel but not on the site
 *
 * Command line only (also blocked for web access by cron/.htaccess).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require APP_PATH . '/autopost.php';

$opts = getopt('v', ['import-all']);
$verbose = isset($opts['v']);

try {
    $r = autopost_run(isset($opts['import-all']));
} catch (Throwable $e) {
    error_log('autopost: ' . $e->getMessage());
    fwrite(STDERR, 'Hata: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($verbose || !$r['ok'] || $r['imported']) {
    echo $r['message'] . "\n";
    foreach ($r['imported'] as $t) {
        echo '  + ' . $t . "\n";
    }
}
exit($r['ok'] ? 0 : 1);
