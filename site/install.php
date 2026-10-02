<?php
declare(strict_types=1);

// One-time installer. Delete this file after running it.
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
start_session();

$lock = APP_PATH . '/installed.lock';
$log = [];
$errors = [];
$done = false;

function install_stop(string $html): never
{
    exit('<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif">' . $html . '</p>');
}

if (is_file($lock)) {
    install_stop('Zaten kurulmuş. Güvenlik için <code>install.php</code> dosyasını silin.');
}

// The database is the real proof of installation: if an admin account exists, this page must never create
// another one, even when installed.lock is gone (e.g. after re-uploading app/). Re-create the lock and stop.
try {
    $alreadyInstalled = admin_account_exists();
} catch (Throwable $ex) {
    $alreadyInstalled = false;      // no database connection yet; the form reports the error on submit
}
if ($alreadyInstalled) {
    @file_put_contents($lock, date('c'));
    install_stop('Bu site zaten kurulmuş (veritabanında yönetici hesabı var). Güvenlik için <code>install.php</code> dosyasını silin.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $site = trim((string)($_POST['site_name'] ?? '')) ?: 'Video Blog';
    $sample = !empty($_POST['sample']);

    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $user)) {
        $errors[] = 'Kullanıcı adı 3-60 karakter olmalı (harf, rakam, . _ -).';
    }
    if (mb_strlen($pass) < 10) {
        $errors[] = 'Şifre en az 10 karakter olmalı.';
    }

    if (!$errors) {
        try {
            $pdo = db();
            if (admin_account_exists()) {
                throw new RuntimeException('Bu site zaten kurulmuş. install.php dosyasını silin.');
            }
            // 1. Schema
            $sql = file_get_contents(APP_PATH . '/schema.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);   // drop comment lines
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
            $log[] = 'Tablolar oluşturuldu.';

            // 2. Encoding check
            $row = $pdo->query('SELECT @@character_set_client c, @@character_set_connection n, @@character_set_results r')->fetch();
            foreach ($row as $v) {
                if ($v !== 'utf8mb4') {
                    throw new RuntimeException('Bağlantı utf8mb4 değil: ' . json_encode($row));
                }
            }
            $log[] = 'Bağlantı kodlaması: utf8mb4 ✓';

            $probe = 'ÇĞİÖŞÜ çğıöşü İstanbul ığdır';
            $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('_probe', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$probe]);
            $back = $pdo->query("SELECT `value` FROM settings WHERE `key` = '_probe'")->fetchColumn();
            $pdo->exec("DELETE FROM settings WHERE `key` = '_probe'");
            if ($back !== $probe) {
                throw new RuntimeException('Türkçe karakter gidiş-dönüş testi başarısız: ' . $back);
            }
            $log[] = 'Türkçe karakter testi (ÇĞİÖŞÜ çğıöşü) ✓';

            // 3. Admin + settings
            $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
            set_setting('site_name', $site);
            $log[] = 'Yönetici hesabı oluşturuldu.';

            // 4. Sample content
            if ($sample) {
                $c1 = save_category('Eğitim');
                $c2 = save_category('Çay Sohbetleri');
                save_post([
                    'title' => 'Türkçe karakter testi: ÇĞİÖŞÜ çğıöşü İstanbul ığdır',
                    'body' => "Bu bir **deneme** yazısıdır. Şöyle bir açıklama: çiğ köfte, ığdır'ın gölü, İstanbul'un ışıkları.\n\nDaha fazla bilgi için [YouTube](https://www.youtube.com) sayfasına bakın.",
                    'youtube_id' => 'dQw4w9WgXcQ',
                    'status' => 'published',
                    'category_ids' => [$c1, $c2],
                ]);
                save_post([
                    'title' => 'Örnek video: Şu ünlü ilk video',
                    'body' => "İkinci örnek yazı. Arama testi için: **araba**, arabalar, çay, Çay, ÇAY.",
                    'youtube_id' => 'jNQXAC9IVRw',
                    'status' => 'published',
                    'category_ids' => [$c1],
                ]);
                save_page([
                    'title' => 'Hakkında',
                    'body' => "## Merhaba!\n\nBu sayfa örnek bir statik sayfadır. Yönetim panelinden düzenleyebilirsiniz.",
                    'show_in_nav' => 1,
                    'sort_order' => 1,
                ]);
                $log[] = 'Örnek içerik eklendi.';
            }

            if (@file_put_contents($lock, date('c')) === false) {
                $log[] = 'Uyarı: app/installed.lock yazılamadı. Kurulum yine de tekrar çalıştırılamaz (yönetici hesabı var), ama install.php dosyasını mutlaka silin.';
            }
            $done = true;
        } catch (Throwable $ex) {
            error_log('install: ' . $ex->getMessage());
            if ($ex instanceof PDOException && !config('debug')) {
                // Raw database errors can reveal account details to whoever opens this page.
                $errors[] = 'Veritabanı hatası. app/config.php içindeki veritabanı bilgilerini kontrol edin. '
                          . '(Ayrıntı sunucunun hata günlüğünde; sayfada görmek için config.php içinde debug değerini true yapın.)';
            } else {
                $errors[] = 'Hata: ' . $ex->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kurulum</title>
<style>
body{font:16px/1.6 system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 16px;color:#1c1917}
label{display:block;margin:14px 0 4px;font-weight:600}
input[type=text],input[type=password]{width:100%;padding:8px 10px;font:inherit;border:1px solid #ccc;border-radius:6px}
button{margin-top:20px;padding:10px 20px;font:inherit;background:#c2410c;color:#fff;border:0;border-radius:6px;cursor:pointer}
.err{background:#fee2e2;padding:8px 12px;border-radius:6px;margin:8px 0}.ok{background:#dcfce7;padding:8px 12px;border-radius:6px}
</style></head><body>
<h1>Site kurulumu</h1>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
<?php if ($done): ?>
  <div class="ok">
    <strong>Kurulum tamamlandı.</strong>
    <ul><?php foreach ($log as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
  </div>
  <p><strong>Şimdi <code>install.php</code> dosyasını silin.</strong></p>
  <p><a href="<?= e(url()) ?>">Siteyi aç →</a></p>
<?php else: ?>
  <p>Önce <code>app/config.php</code> içine veritabanı bilgilerini yazın.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label>Site adı</label><input type="text" name="site_name" value="Video Blog">
    <label>Yönetici kullanıcı adı</label><input type="text" name="username" required>
    <label>Yönetici şifresi (en az 10 karakter)</label><input type="password" name="password" required minlength="10">
    <label><input type="checkbox" name="sample" value="1" checked> Örnek içerik ekle (Türkçe karakter testi dahil)</label>
    <button>Kur</button>
  </form>
<?php endif; ?>
</body></html>
