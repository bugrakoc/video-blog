<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

$keys = ['site_name', 'tagline', 'channel_id', 'autopost_mode', 'default_og_image', 'twitter_handle', 'footer_text'];
$errors = [];
$form = [];
foreach ($keys as $k) {
    $form[$k] = setting($k, $k === 'autopost_mode' ? 'draft' : '');
}

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
    <legend>Otomatik yayın <span class="badge draft">yakında</span></legend>
    <p class="hint">Bu ayarlar, yeni videoları kanalınızdan otomatik ekleyecek betik hazır olduğunda kullanılacak.</p>
    <label>YouTube kanal kimliği <span class="hint">(UC… ile başlayan 24 karakter; YouTube Studio → Ayarlar → Kanal → Gelişmiş ayarlar)</span>
      <input type="text" name="channel_id" value="<?= e($form['channel_id']) ?>" placeholder="UCxxxxxxxxxxxxxxxxxxxxxx" maxlength="24">
    </label>
    <label>Yeni videolar
      <select name="autopost_mode">
        <option value="draft" <?= $form['autopost_mode'] === 'draft' ? 'selected' : '' ?>>Taslak olarak ekle (önce ben gözden geçireyim)</option>
        <option value="publish" <?= $form['autopost_mode'] === 'publish' ? 'selected' : '' ?>>Doğrudan yayınla</option>
      </select>
    </label>
  </fieldset>

  <button class="btn primary">Kaydet</button>
</form>
<?php admin_footer();
