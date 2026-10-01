<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

$id = (int)($_GET['id'] ?? 0);
$pg = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $stmt->execute([$id]);
    $pg = $stmt->fetch() ?: null;
    if (!$pg) {
        flash('error', 'Sayfa bulunamadı.');
        redirect(url('admin/pages.php'));
    }
}

$errors = [];
$form = [
    'title'            => $pg['title'] ?? '',
    'slug'             => $pg['slug'] ?? '',
    'body'             => $pg['body'] ?? '',
    'meta_description' => $pg['meta_description'] ?? '',
    'status'           => $pg['status'] ?? 'published',
    'show_in_nav'      => (bool)($pg['show_in_nav'] ?? false),
    'sort_order'       => (string)($pg['sort_order'] ?? 0),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['title', 'slug', 'body', 'meta_description', 'sort_order'] as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $form['status'] = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';
    $form['show_in_nav'] = !empty($_POST['show_in_nav']);

    if ($form['title'] === '') {
        $errors[] = 'Başlık gerekli.';
    } elseif (mb_strlen($form['title']) > 255) {
        $errors[] = 'Başlık en fazla 255 karakter olabilir.';
    }
    if (mb_strlen($form['meta_description']) > 300) {
        $errors[] = 'Meta açıklama en fazla 300 karakter olabilir.';
    }
    if ($form['sort_order'] !== '' && !preg_match('/^-?\d{1,6}$/', $form['sort_order'])) {
        $errors[] = 'Sıra bir sayı olmalı.';
    }

    if (!$errors) {
        $newId = save_page([
            'title'            => $form['title'],
            'slug'             => $form['slug'],
            'body'             => $form['body'],
            'meta_description' => $form['meta_description'],
            'status'           => $form['status'],
            'show_in_nav'      => $form['show_in_nav'],
            'sort_order'       => (int)$form['sort_order'],
        ], $id ?: null);

        $stmt = db()->prepare('SELECT slug FROM pages WHERE id = ?');
        $stmt->execute([$newId]);
        $finalSlug = (string)$stmt->fetchColumn();
        $wanted = slugify($form['slug'] !== '' ? $form['slug'] : $form['title']);
        $msg = $id ? 'Kaydedildi.' : 'Sayfa eklendi.';
        if ($wanted !== '' && $wanted !== $finalSlug) {
            $msg .= ' “' . $wanted . '” adresi ayrılmış veya kullanımda olduğu için “' . $finalSlug . '” olarak ayarlandı.';
        }
        flash('ok', $msg);
        redirect(url('admin/page-edit.php?id=' . $newId));
    }
}

$isNew = !$id;
admin_header($isNew ? 'Yeni sayfa' : 'Sayfayı düzenle', 'pages');
?>
<div class="head">
  <h1><?= $isNew ? 'Yeni sayfa' : 'Sayfayı düzenle' ?></h1>
  <?php if ($pg): ?>
    <a class="btn" target="_blank" rel="noopener" href="<?= e(url($pg['slug'] . ($pg['status'] === 'draft' ? '?preview=1' : ''))) ?>">
      <?= $pg['status'] === 'draft' ? 'Önizle' : 'Sayfayı gör' ?> ↗
    </a>
  <?php endif; ?>
</div>

<?php foreach ($errors as $er): ?><div class="flash error"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="editor" autocomplete="off">
  <?= csrf_field() ?>
  <div class="cols">
    <div class="main">
      <label>Başlık
        <input type="text" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="255" required>
      </label>
      <label>İçerik <span class="hint">(Markdown)</span>
        <textarea name="body" rows="18"><?= e($form['body']) ?></textarea>
      </label>
      <details <?= ($form['slug'] !== '' || $form['meta_description'] !== '') ? 'open' : '' ?>>
        <summary>Gelişmiş: adres, SEO</summary>
        <label>Adres (slug) <span class="hint">boşsa başlıktan üretilir</span>
          <input type="text" id="slug" name="slug" value="<?= e($form['slug']) ?>" maxlength="100" placeholder="otomatik">
        </label>
        <label>Meta açıklama
          <textarea name="meta_description" rows="2" maxlength="300"><?= e($form['meta_description']) ?></textarea>
        </label>
      </details>
    </div>
    <aside class="side">
      <fieldset>
        <legend>Yayın</legend>
        <label class="check"><input type="radio" name="status" value="published" <?= $form['status'] === 'published' ? 'checked' : '' ?>> Yayında</label>
        <label class="check"><input type="radio" name="status" value="draft" <?= $form['status'] === 'draft' ? 'checked' : '' ?>> Taslak</label>
        <label class="check"><input type="checkbox" name="show_in_nav" value="1" <?= $form['show_in_nav'] ? 'checked' : '' ?>> Menüde göster</label>
        <label>Menü sırası
          <input type="text" inputmode="numeric" name="sort_order" value="<?= e($form['sort_order']) ?>">
        </label>
        <button class="btn primary wide"><?= $isNew ? 'Ekle' : 'Kaydet' ?></button>
      </fieldset>
    </aside>
  </div>
</form>
<?php admin_footer();
