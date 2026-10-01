<?php
declare(strict_types=1);

// Manual autoposter actions from the settings page (POST only).
require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
require APP_PATH . '/autopost.php';
admin_boot();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('admin/settings.php'));
}
csrf_check();

$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['run', 'import_all'], true)) {
    flash('error', 'Geçersiz işlem.');
    redirect(url('admin/settings.php'));
}

try {
    $r = autopost_run($action === 'import_all');
    $msg = $r['message'];
    if ($r['imported']) {
        $msg .= ' ' . implode(' · ', array_slice($r['imported'], 0, 5)) . (count($r['imported']) > 5 ? ' …' : '');
    }
    flash($r['ok'] ? 'ok' : 'error', $msg);
} catch (Throwable $e) {
    error_log('autopost (admin): ' . $e->getMessage());
    flash('error', 'Kontrol sırasında bir hata oluştu: ' . $e->getMessage());
}
redirect(url('admin/settings.php'));
