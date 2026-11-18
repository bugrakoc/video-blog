<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    reset_admin_session();
    flash('ok', 'Çıkış yapıldı.');
    redirect(url('admin/login.php'));
}
redirect(url('admin/index.php'));
