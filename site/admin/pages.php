<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $stmt->execute([$id]);
    $pg = $stmt->fetch();
    if (!$pg) {
        flash('error', 'Sayfa bulunamadı.');
    } elseif (($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
        flash('ok', 'Silindi: ' . $pg['title']);
    }
    redirect(url('admin/pages.php'));
}

$pages = db()->query('SELECT * FROM pages ORDER BY sort_order, title')->fetchAll();

admin_header('Sayfalar', 'pages');
?>
<div class="head">
  <h1>Sayfalar</h1>
  <a class="btn primary" href="page-edit.php">+ Yeni sayfa</a>
</div>
<p class="muted">Hakkında, İletişim gibi sabit sayfalar. “Menüde göster” işaretli olanlar üst menüye eklenir.</p>

<?php if (!$pages): ?>
  <p class="empty">Henüz sayfa yok.</p>
<?php else: ?>
  <table class="list">
    <?php foreach ($pages as $pg): ?>
      <tr>
        <td>
          <a href="page-edit.php?id=<?= (int)$pg['id'] ?>"><strong><?= e($pg['title']) ?></strong></a>
          <div class="muted small">/<?= e($pg['slug']) ?><?= $pg['show_in_nav'] ? ' · menüde (sıra ' . (int)$pg['sort_order'] . ')' : '' ?></div>
        </td>
        <td><span class="badge <?= $pg['status'] === 'draft' ? 'draft' : 'live' ?>"><?= $pg['status'] === 'draft' ? 'Taslak' : 'Yayında' ?></span></td>
        <td class="actions">
          <a href="page-edit.php?id=<?= (int)$pg['id'] ?>">Düzenle</a>
          <a href="<?= e(url($pg['slug'] . ($pg['status'] === 'draft' ? '?preview=1' : ''))) ?>" target="_blank" rel="noopener"><?= $pg['status'] === 'draft' ? 'Önizle' : 'Gör' ?> ↗</a>
          <form method="post" class="inline" data-confirm="“<?= e($pg['title']) ?>” kalıcı olarak silinsin mi?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$pg['id'] ?>">
            <button class="link danger" name="action" value="delete">Sil</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php admin_footer();
