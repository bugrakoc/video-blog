<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $again = (string)($_POST['again'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $hash = (string)$stmt->fetchColumn();

    // Share the login throttle so this form can't be used to guess the current password.
    // login_attempt_allowed() records the attempt up front; it is cleared once the current password checks out.
    if (!login_attempt_allowed(client_ip())) {
        $errors[] = 'Çok fazla başarısız deneme. Lütfen biraz sonra tekrar deneyin.';
    } elseif (!password_verify($current, $hash)) {
        $errors[] = 'Mevcut şifre hatalı.';
    } else {
        login_clear_failures(client_ip());          // right password: a typo in the new one isn't a failed guess
        if (mb_strlen($new) < 10) {
            $errors[] = 'Yeni şifre en az 10 karakter olmalı.';
        } elseif ($new !== $again) {
            $errors[] = 'Yeni şifreler eşleşmiyor.';
        } elseif ($new === $current) {
            $errors[] = 'Yeni şifre mevcut şifreyle aynı olamaz.';
        }
    }

    if (!$errors) {
        db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['admin_id']]);
        session_regenerate_id(true);
        flash('ok', 'Şifre değiştirildi.');
        redirect(url('admin/settings.php'));
    }
}

admin_header('Şifre değiştir', 'settings');
?>
<div class="head"><h1>Şifre değiştir</h1></div>
<?php foreach ($errors as $er): ?><div class="flash error"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" class="editor narrow">
  <?= csrf_field() ?>
  <fieldset>
    <label>Mevcut şifre <input type="password" name="current" required autocomplete="current-password"></label>
    <label>Yeni şifre <span class="hint">(en az 10 karakter)</span> <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
    <label>Yeni şifre (tekrar) <input type="password" name="again" required minlength="10" autocomplete="new-password"></label>
    <button class="btn primary">Şifreyi değiştir</button>
  </fieldset>
</form>
<?php admin_footer();
