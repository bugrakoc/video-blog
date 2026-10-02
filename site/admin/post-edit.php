<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

$id = (int)($_GET['id'] ?? 0);
$post = $id ? get_post($id) : null;
if ($id && !$post) {
    flash('error', 'Video bulunamadı.');
    redirect(url('admin/posts.php'));
}

$allCats = get_categories();
$errors = [];

$form = [
    'youtube_url'      => $post ? 'https://www.youtube.com/watch?v=' . $post['youtube_id'] : '',
    'title'            => $post['title'] ?? '',
    'slug'             => $post['slug'] ?? '',
    'body'             => $post['body'] ?? '',
    'excerpt'          => $post['excerpt'] ?? '',
    'meta_description' => $post['meta_description'] ?? '',
    'status'           => $post['status'] ?? 'draft',
    'published_at'     => to_local_datetime($post['published_at'] ?? null),
    'category_ids'     => array_map(fn($c) => (int)$c['id'], $post['categories'] ?? []),
    'new_categories'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['youtube_url', 'title', 'slug', 'body', 'excerpt', 'meta_description', 'published_at', 'new_categories'] as $k) {
        $form[$k] = trim((string)($_POST[$k] ?? ''));
    }
    // Body keeps its inner whitespace/newlines; only trim the ends.
    $form['status'] = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
    $form['category_ids'] = array_map('intval', (array)($_POST['category_ids'] ?? []));

    $ytId = youtube_id($form['youtube_url']);
    if (!$ytId) {
        $errors[] = 'Geçerli bir YouTube bağlantısı veya video kimliği girin.';
    } else {
        $dup = db()->prepare('SELECT id, title FROM posts WHERE youtube_id = ? AND id <> ?');
        $dup->execute([$ytId, $id]);
        if ($row = $dup->fetch()) {
            $errors[] = 'Bu video zaten eklenmiş: “' . $row['title'] . '”.';
        }
    }
    if ($form['title'] === '') {
        $errors[] = 'Başlık gerekli.';
    } elseif (mb_strlen($form['title']) > 255) {
        $errors[] = 'Başlık en fazla 255 karakter olabilir.';
    }
    if (mb_strlen($form['meta_description']) > 300) {
        $errors[] = 'Meta açıklama en fazla 300 karakter olabilir.';
    }
    $publishedAt = parse_local_datetime($form['published_at']);
    if ($form['published_at'] !== '' && $publishedAt === null) {
        $errors[] = 'Yayın tarihi geçersiz.';
    }

    if (!$errors) {
        $cats = array_merge($form['category_ids'], resolve_new_categories($form['new_categories']));
        $newId = save_post([
            'title'            => $form['title'],
            'slug'             => $form['slug'],
            'youtube_id'       => $ytId,
            'body'             => $form['body'],
            'excerpt'          => $form['excerpt'],
            'meta_description' => $form['meta_description'],
            'status'           => $form['status'],
            'published_at'     => $publishedAt,
            'source'           => $post['source'] ?? 'manual',
            'category_ids'     => $cats,
        ], $id ?: null);
        if ($post && $post['youtube_id'] !== $ytId) {
            mark_video_seen($post['youtube_id']);   // the old video was removed from the site on purpose
        }
        $saved = get_post($newId);
        $msg = $id ? 'Kaydedildi.' : 'Video eklendi.';
        if ($saved && $form['slug'] !== '' && slugify($form['slug']) !== $saved['slug']) {
            $msg .= ' Bu adres kullanıldığı için “' . $saved['slug'] . '” olarak ayarlandı.';
        }
        flash('ok', $msg);
        redirect(url('admin/post-edit.php?id=' . $newId));
    }
}

$isNew = !$id;
admin_header($isNew ? 'Yeni video' : 'Videoyu düzenle', 'posts');
?>
<div class="head">
  <h1><?= $isNew ? 'Yeni video' : 'Videoyu düzenle' ?></h1>
  <?php if ($post): ?>
    <a class="btn" target="_blank" rel="noopener" href="<?= e(admin_post_view_url($post)) ?>">
      <?= post_is_live($post) ? 'Sayfayı gör' : 'Önizle' ?> ↗
    </a>
  <?php endif; ?>
</div>

<?php foreach ($errors as $er): ?><div class="flash error"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="editor" autocomplete="off">
  <?= csrf_field() ?>
  <div class="cols">
    <div class="main">
      <label>YouTube bağlantısı
        <div class="row">
          <input type="text" id="youtube_url" name="youtube_url" value="<?= e($form['youtube_url']) ?>" placeholder="https://www.youtube.com/watch?v=…" required>
          <button type="button" class="btn" id="fetch-title">Başlığı getir</button>
        </div>
        <span class="hint" id="yt-status">Bağlantıyı yapıştırın; başlık boşsa otomatik doldurulur.</span>
      </label>

      <div class="preview" id="yt-preview" <?= youtube_id($form['youtube_url']) ? '' : 'hidden' ?>>
        <img id="yt-thumb" alt="" src="<?= e(($i = youtube_id($form['youtube_url'])) ? yt_thumb($i) : '') ?>">
      </div>

      <label>Başlık
        <input type="text" id="title" name="title" value="<?= e($form['title']) ?>" maxlength="255" required>
      </label>

      <label>Metin <span class="hint">(Markdown; video açıklamasını buraya yapıştırın)</span>
        <textarea name="body" rows="16"><?= e($form['body']) ?></textarea>
      </label>
      <p class="hint md-help">
        **kalın** · *italik* · [bağlantı](https://…) · <code>## Başlık</code> · <code>- liste</code> · satır sonu otomatik
        · HTML kullanılamaz (güvenlik için etkisizleştirilir).
      </p>

      <details <?= ($form['excerpt'] !== '' || $form['meta_description'] !== '' || $form['slug'] !== '') ? 'open' : '' ?>>
        <summary>Gelişmiş: adres, özet, SEO</summary>
        <label>Adres (slug) <span class="hint">boş bırakırsanız başlıktan üretilir; yalnızca a-z, 0-9 ve tire</span>
          <input type="text" id="slug" name="slug" value="<?= e($form['slug']) ?>" maxlength="100" placeholder="otomatik">
        </label>
        <label>Özet <span class="hint">(boşsa metinden otomatik üretilir)</span>
          <textarea name="excerpt" rows="3"><?= e($form['excerpt']) ?></textarea>
        </label>
        <label>Meta açıklama <span class="hint">(Google ve sosyal paylaşım; en çok 160 karakter önerilir)</span>
          <textarea name="meta_description" rows="2" maxlength="300"><?= e($form['meta_description']) ?></textarea>
        </label>
      </details>
    </div>

    <aside class="side">
      <fieldset>
        <legend>Yayın</legend>
        <label class="check"><input type="radio" name="status" value="draft" <?= $form['status'] === 'draft' ? 'checked' : '' ?>> Taslak</label>
        <label class="check"><input type="radio" name="status" value="published" <?= $form['status'] === 'published' ? 'checked' : '' ?>> Yayında</label>
        <label>Yayın tarihi
          <input type="datetime-local" name="published_at" value="<?= e($form['published_at']) ?>">
          <span class="hint">Boşsa yayınlandığı an. Gelecek bir tarih yazarsanız o zamana kadar gizli kalır.</span>
        </label>
        <button class="btn primary wide"><?= $isNew ? 'Ekle' : 'Kaydet' ?></button>
      </fieldset>

      <fieldset>
        <legend>Kategoriler</legend>
        <?php foreach ($allCats as $c): ?>
          <label class="check"><input type="checkbox" name="category_ids[]" value="<?= (int)$c['id'] ?>"
            <?= in_array((int)$c['id'], $form['category_ids'], true) ? 'checked' : '' ?>> <?= e($c['name']) ?></label>
        <?php endforeach; ?>
        <label>Yeni kategori <span class="hint">(virgülle ayırın)</span>
          <input type="text" name="new_categories" value="<?= e($form['new_categories']) ?>" placeholder="ör. Çay Sohbetleri, Eğitim">
        </label>
      </fieldset>
    </aside>
  </div>
</form>
<?php admin_footer();
