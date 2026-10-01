<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
require APP_PATH . '/autopost.php';
admin_boot();

$keys = ['site_name', 'tagline', 'channel_id', 'autopost_mode', 'autopost_shorts', 'default_og_image', 'twitter_handle', 'footer_text'];
$defaults = ['autopost_mode' => 'draft', 'autopost_shorts' => 'include'];
$errors = [];
$form = [];
foreach ($keys as $k) {
    $form[$k] = setting($k, $defaults[$k] ?? '');
}
$oldChannel = $form['channel_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($keys as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if ($form['site_name'] === '' || mb_strlen($form['site_name']) > 100) {
        $errors[] = 'Site adı gerekli (en fazla 100 karakter).';
    }
    if (mb_strlen($form['tagline']) > 200) {
        $errors[] = 'Slogan en fazla 200 karakter olabilir.';
    }
    if ($form['channel_id'] !== '' && !preg_match('/^UC[A-Za-z0-9_-]{22}$/', $form['channel_id'])) {
        $errors[] = 'Kanal kimliği “UC” ile başlayan 24 karakterlik bir kimlik olmalı (kanal adı veya @kullanıcı adı değil).';
    }
    if (!in_array($form['autopost_mode'], ['draft', 'publish'], true)) {
        $form['autopost_mode'] = 'draft';
    }
    if (!in_array($form['autopost_shorts'], ['include', 'skip'], true)) {
        $form['autopost_shorts'] = 'include';
    }
    if ($form['default_og_image'] !== '' && !preg_match('#^https?://#i', $form['default_og_image'])) {
        $errors[] = 'Varsayılan paylaşım görseli http(s):// ile başlayan bir adres olmalı.';
    }
    $form['twitter_handle'] = ltrim($form['twitter_handle'], '@');
    if ($form['twitter_handle'] !== '' && !preg_match('/^[A-Za-z0-9_]{1,15}$/', $form['twitter_handle'])) {
        $errors[] = 'X/Twitter kullanıcı adı geçersiz.';
    }
    if (mb_strlen($form['footer_text']) > 200) {
        $errors[] = 'Alt bilgi metni en fazla 200 karakter olabilir.';
    }

    if (!$errors) {
        foreach ($keys as $k) {
            set_setting($k, $form[$k]);
        }
        if ($form['channel_id'] !== $oldChannel) {
            // A different channel starts from a fresh baseline so its back catalogue isn't imported.
            set_setting('autopost_baseline', '');
        }
        flash('ok', 'Ayarlar kaydedildi.');
        redirect(url('admin/settings.php'));
    }
}

admin_header('Ayarlar', 'settings');
?>
<div class="head">
  <h1>Ayarlar</h1>
  <a class="btn" href="password.php">Şifre değiştir</a>
</div>

<?php foreach ($errors as $er): ?><div class="flash error"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="editor narrow" autocomplete="off">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Site</legend>
    <label>Site adı
      <input type="text" name="site_name" value="<?= e($form['site_name']) ?>" maxlength="100" required>
    </label>
    <label>Slogan <span class="hint">(ana sayfanın meta açıklaması olarak da kullanılır)</span>
      <input type="text" name="tagline" value="<?= e($form['tagline']) ?>" maxlength="200">
    </label>
    <label>Alt bilgi metni
      <input type="text" name="footer_text" value="<?= e($form['footer_text']) ?>" maxlength="200">
    </label>
  </fieldset>

  <fieldset>
    <legend>Sosyal paylaşım</legend>
    <label>Varsayılan paylaşım görseli <span class="hint">(video olmayan sayfalar için; tam adres)</span>
      <input type="text" name="default_og_image" value="<?= e($form['default_og_image']) ?>" placeholder="https://…/gorsel.jpg">
    </label>
    <label>X/Twitter kullanıcı adı
      <input type="text" name="twitter_handle" value="<?= e($form['twitter_handle']) ?>" placeholder="kullaniciadi" maxlength="16">
    </label>
  </fieldset>

  <fieldset>
    <legend>Otomatik yayın</legend>
    <p class="hint">Kanalınıza yeni video yüklendiğinde otomatik eklenir. Çalışması için aşağıdaki “Zamanlanmış görev” kurulmalıdır.</p>
    <label>YouTube kanal kimliği <span class="hint">(UC… ile başlayan 24 karakter; YouTube Studio → Ayarlar → Kanal → Gelişmiş ayarlar)</span>
      <input type="text" name="channel_id" value="<?= e($form['channel_id']) ?>" placeholder="UCxxxxxxxxxxxxxxxxxxxxxx" maxlength="24">
    </label>
    <label>Yeni videolar
      <select name="autopost_mode">
        <option value="draft" <?= $form['autopost_mode'] === 'draft' ? 'selected' : '' ?>>Taslak olarak ekle (önce ben gözden geçireyim)</option>
        <option value="publish" <?= $form['autopost_mode'] === 'publish' ? 'selected' : '' ?>>Doğrudan yayınla</option>
      </select>
    </label>
    <label>YouTube Shorts
      <select name="autopost_shorts">
        <option value="include" <?= $form['autopost_shorts'] === 'include' ? 'selected' : '' ?>>Shorts videolarını da ekle</option>
        <option value="skip" <?= $form['autopost_shorts'] === 'skip' ? 'selected' : '' ?>>Shorts videolarını atla</option>
      </select>
    </label>
  </fieldset>

  <button class="btn primary">Kaydet</button>
</form>

<?php
$lastRun = setting('autopost_last_run');
$lastOk = setting('autopost_last_ok') === '1';
$lastMsg = setting('autopost_last_result');
$hasChannel = setting('channel_id') !== '';
$cronPath = realpath(ROOT_PATH . '/cron/autopost.php') ?: (ROOT_PATH . '/cron/autopost.php');
?>
<section class="editor narrow autopost">
  <h2>Otomatik yayın durumu</h2>
  <?php if ($lastRun): ?>
    <div class="flash <?= $lastOk ? 'ok' : 'error' ?>">
      <strong><?= e(tr_date($lastRun, true)) ?></strong> — <?= e($lastMsg) ?>
    </div>
  <?php else: ?>
    <p class="muted">Henüz hiç kontrol yapılmadı.</p>
  <?php endif; ?>

  <?php if ($hasChannel): ?>
    <div class="row-actions">
      <form method="post" action="autopost.php" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="run">
        <button class="btn">Şimdi kontrol et</button>
      </form>
      <form method="post" action="autopost.php" class="inline"
            data-confirm="Kanaldaki son videolardan sitede olmayanların hepsi eklensin mi? (Daha önce sildikleriniz de geri gelebilir.)">
        <?= csrf_field() ?><input type="hidden" name="action" value="import_all">
        <button class="btn">Son videoları içe aktar</button>
      </form>
      <a class="btn" href="import.php">Tüm eski videoları içe aktar…</a>
    </div>
    <p class="hint">İlk kontrol hiçbir video eklemez; kanaldaki mevcut videoları “görüldü” olarak işaretler, böylece eski videolar sitenize dökülmez. Sadece sonradan yüklenenler eklenir. Eski videoları da istiyorsanız “Son videoları içe aktar”a basın (YouTube beslemesi en fazla son 15 videoyu verir).</p>
  <?php else: ?>
    <p class="muted">Önce yukarıya kanal kimliğini yazıp kaydedin.</p>
  <?php endif; ?>

  <h2>Zamanlanmış görev (cron)</h2>
  <p class="hint">DirectAdmin → Gelişmiş Özellikler → Cron İşleri bölümünde yeni görev ekleyin: her 30 dakikada bir (dakika: <code>*/30</code>, diğer alanlar <code>*</code>) ve komut olarak:</p>
  <pre class="cmd">php <?= e($cronPath) ?></pre>
  <p class="hint">“php” yerine sunucunuzun PHP yolu gerekebilir (ör. <code>/usr/local/php82/bin/php</code>; DirectAdmin PHP Sürüm Seçici sayfasında görünür). Betik yalnızca yeni video eklediğinde veya hata olduğunda çıktı verir, bu yüzden her çalışmada e-posta gelmez.</p>
</section>
<?php admin_footer();
