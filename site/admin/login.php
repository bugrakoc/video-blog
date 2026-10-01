<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot(false);

if (is_admin()) {
    redirect(url('admin/index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ip = client_ip();
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if (login_recent_failures($ip) >= LOGIN_MAX_ATTEMPTS) {
        $error = 'Çok fazla başarısız deneme. Lütfen ' . LOGIN_WINDOW_MIN . ' dakika sonra tekrar deneyin.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
        $stmt->execute([$user]);
        $row = $stmt->fetch();

        // Always run password_verify so response time doesn't reveal whether the user exists.
        static $dummy = '$2y$12$MkGdGlLvonAJ8dXD2Egc2OmO.A3qewtUVdImk1YjC5Y.klXya71SG';
        $ok = password_verify($pass, $row['password_hash'] ?? $dummy) && $row;

        if ($ok) {
            login_clear_failures($ip);
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$row['id'];
            $_SESSION['last_seen'] = time();
            redirect(url('admin/index.php'));
        }
        login_record_failure($ip);
        $error = 'Kullanıcı adı veya şifre hatalı.';
    }
}

admin_header('Giriş');
?>
<div class="login">
  <h1>Yönetim girişi</h1>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <label>Kullanıcı adı
      <input type="text" name="username" required autofocus autocomplete="username" value="<?= e((string)($_POST['username'] ?? '')) ?>">
    </label>
    <label>Şifre
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn primary">Giriş yap</button>
  </form>
</div>
<?php admin_footer();
