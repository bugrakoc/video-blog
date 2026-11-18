<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

// Actions: delete, publish, unpublish (POST only, CSRF protected)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    admin_content_lock('admin/posts.php');
    $id = form_id($_POST, 'id');
    $post = $id ? get_post($id) : null;
    $action = form_text($_POST, 'action');
    if (!$post) {
        flash('error', 'Video bulunamadı.');
    } elseif ($action === 'delete') {
        autopost_ensure_schema();
        ensure_video_id_collations();
        db_transaction(function () use ($id, $post): void {
            mark_video_seen($post['youtube_id']);
            db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        });
        flash('ok', 'Silindi: ' . $post['title']);
    } elseif ($action === 'publish') {
        db()->prepare(
            "UPDATE posts SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ?"
        )->execute([$id]);
        $saved = get_post($id);
        flash('ok', (post_is_live($saved) ? 'Yayınlandı: ' : 'Yayın zamanlandı: ') . $post['title']);
    } elseif ($action === 'unpublish') {
        db()->prepare("UPDATE posts SET status = 'draft' WHERE id = ?")->execute([$id]);
        flash('ok', 'Taslağa alındı: ' . $post['title']);
    }
    site_unlock(CONTENT_LOCK);
    $filters = array_intersect_key($_GET, array_flip(['status', 'q', 'page']));
    redirect(url('admin/posts.php?' . http_build_query($filters)));
}

$status = in_array($_GET['status'] ?? '', ['published', 'draft', 'scheduled'], true) ? $_GET['status'] : '';
$q = mb_substr(trim(form_text($_GET, 'q')), 0, 100);
$page = min(max(1, (int)($_GET['page'] ?? 1)), 1000000);
[$posts, $total] = admin_list_posts($page, $status, $q);
$lastPage = max(1, (int)ceil($total / ADMIN_PER_PAGE));
if ($page > $lastPage) {
    redirect(url('admin/posts.php?' . http_build_query(['status' => $status, 'q' => $q, 'page' => $lastPage])));
}

$link = fn(int $n) => 'posts.php?' . http_build_query(array_filter(['status' => $status, 'q' => $q, 'page' => $n > 1 ? $n : null], fn($v) => $v !== '' && $v !== null));

admin_header('Videolar', 'posts');
?>
<div class="head">
  <h1>Videolar <small class="muted">(<?= $total ?>)</small></h1>
  <a class="btn primary" href="post-edit.php">+ Yeni video</a>
</div>

<form class="filters" method="get">
  <div class="tabs">
    <?php foreach (['' => 'Tümü', 'published' => 'Yayında', 'scheduled' => 'Zamanlanmış', 'draft' => 'Taslak'] as $k => $label): ?>
      <a href="posts.php?<?= e(http_build_query(array_filter(['status' => $k, 'q' => $q], fn($v) => $v !== ''))) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Başlık veya metinde ara…">
  <button class="btn">Ara</button>
</form>

<?php if (!$posts): ?>
  <p class="empty">Kayıt bulunamadı.</p>
<?php else: ?>
  <table class="list">
    <?php foreach ($posts as $p): [$label, $cls] = post_state($p); ?>
      <tr>
        <td class="thumb"><img src="<?= e(yt_thumb($p['youtube_id'])) ?>" alt="" loading="lazy"></td>
        <td>
          <a href="post-edit.php?id=<?= (int)$p['id'] ?>"><strong><?= e($p['title']) ?></strong></a>
          <div class="muted small">
            <?= e(tr_date($p['published_at'] ?: $p['created_at'], true)) ?>
            <?php foreach ($p['categories'] as $c): ?> · <?= e($c['name']) ?><?php endforeach; ?>
            <?php if ($p['source'] === 'auto'): ?> · otomatik<?php endif; ?>
          </div>
        </td>
        <td><span class="badge <?= $cls ?>"><?= e($label) ?></span></td>
        <td class="actions">
          <a href="post-edit.php?id=<?= (int)$p['id'] ?>">Düzenle</a>
          <a href="<?= e(admin_post_view_url($p)) ?>" target="_blank" rel="noopener"><?= post_is_live($p) ? 'Gör' : 'Önizle' ?> ↗</a>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <?php if ($p['status'] === 'draft'): ?>
              <button class="link" name="action" value="publish"><?= $p['published_at'] && strtotime($p['published_at']) > time() ? 'Zamanla' : 'Yayınla' ?></button>
            <?php else: ?>
              <button class="link" name="action" value="unpublish">Taslağa al</button>
            <?php endif; ?>
          </form>
          <form method="post" class="inline" data-confirm="“<?= e($p['title']) ?>” kalıcı olarak silinsin mi?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="link danger" name="action" value="delete">Sil</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php admin_pager($page, $total, $link); ?>
<?php endif; ?>
<?php admin_footer();
