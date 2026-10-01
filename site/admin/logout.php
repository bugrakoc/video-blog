<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], true);
    }
    session_destroy();
    start_session();
    flash('ok', 'Çıkış yapıldı.');
    redirect(url('admin/login.php'));
}
redirect(url('admin/index.php'));
