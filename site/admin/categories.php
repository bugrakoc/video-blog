<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));

    if ($action === 'add' || $action === 'rename') {
        if ($name === '' || mb_strlen($name) > 100 || slugify($name) === '') {
            flash('error', 'Geçerli bir kategori adı girin (en az bir harf veya rakam, en fazla 100 karakter).');
        } elseif ($action === 'add') {
            if (get_category_by_slug(slugify($name))) {
                flash('error', 'Bu kategori zaten var.');
            } else {
                save_category($name);
                flash('ok', 'Kategori eklendi: ' . $name);
            }
        } else {
            $existing = get_category_by_slug(slugify($name));
            if ($existing && (int)$existing['id'] !== $id) {
                flash('error', 'Bu adda başka bir kategori var.');
            } else {
                save_category($name, $id);
                flash('ok', 'Kategori güncellendi.');
            }
        }
    } elseif ($action === 'delete') {
        // post_category rows are removed by ON DELETE CASCADE; the posts themselves stay.
        db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        flash('ok', 'Kategori silindi (videolar silinmedi).');
    }
    redirect(url('admin/categories.php'));
}

$rows = db()->query(
    'SELECT c.id, c.name, c.slug, COUNT(pc.post_id) AS n
     FROM categories c LEFT JOIN post_category pc ON pc.category_id = c.id
     GROUP BY c.id, c.name, c.slug'
)->fetchAll();
usort($rows, fn($a, $b) => tr_compare($a['name'], $b['name']));

admin_header('Kategoriler', 'categories');
?>
<div class="head"><h1>Kategoriler</h1></div>

<form method="post" class="row add-cat">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <input type="text" name="name" placeholder="Yeni kategori adı" maxlength="100" required>
  <button class="btn primary">Ekle</button>
</form>

<?php if (!$rows): ?>
  <p class="empty">Henüz kategori yok.</p>
<?php else: ?>
  <table class="list">
    <?php foreach ($rows as $c): ?>
      <tr>
        <td>
          <form method="post" class="row">
            <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="text" name="name" value="<?= e($c['name']) ?>" maxlength="100" required>
            <button class="btn">Kaydet</button>
          </form>
        </td>
        <td class="muted small">/category/<?= e($c['slug']) ?></td>
        <td class="muted small"><?= (int)$c['n'] ?> video</td>
        <td class="actions">
          <form method="post" class="inline" data-confirm="“<?= e($c['name']) ?>” kategorisi silinsin mi? Videolar silinmez.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="link danger" name="action" value="delete">Sil</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php admin_footer();
