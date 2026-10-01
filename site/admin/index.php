<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

$pdo = db();
$counts = [
    'live'      => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='published' AND (published_at IS NULL OR published_at <= NOW())")->fetchColumn(),
    'draft'     => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='draft'")->fetchColumn(),
    'scheduled' => (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='published' AND published_at > NOW()")->fetchColumn(),
    'pages'     => (int)$pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
    'cats'      => (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
];
[$recent] = admin_list_posts(1, '', '');
$recent = array_slice($recent, 0, 8);

admin_header('Panel', 'dashboard');
?>
<div class="head">
  <h1>Panel</h1>
  <a class="btn primary" href="post-edit.php">+ Yeni video</a>
</div>

<div class="stats">
  <a href="posts.php?status=published"><strong><?= $counts['live'] ?></strong><span>Yayında</span></a>
  <a href="posts.php?status=draft"><strong><?= $counts['draft'] ?></strong><span>Taslak</span></a>
  <a href="posts.php?status=scheduled"><strong><?= $counts['scheduled'] ?></strong><span>Zamanlanmış</span></a>
  <a href="pages.php"><strong><?= $counts['pages'] ?></strong><span>Sayfa</span></a>
  <a href="categories.php"><strong><?= $counts['cats'] ?></strong><span>Kategori</span></a>
</div>

<h2>Son videolar</h2>
<?php if (!$recent): ?>
  <p class="muted">Henüz video yok. <a href="post-edit.php">İlk videoyu ekleyin →</a></p>
<?php else: ?>
  <table class="list">
    <?php foreach ($recent as $p): [$label, $cls] = post_state($p); ?>
      <tr>
        <td class="thumb"><img src="<?= e(yt_thumb($p['youtube_id'])) ?>" alt="" loading="lazy"></td>
        <td><a href="post-edit.php?id=<?= (int)$p['id'] ?>"><strong><?= e($p['title']) ?></strong></a>
            <div class="muted small"><?= e(tr_date($p['published_at'] ?: $p['created_at'])) ?></div></td>
        <td><span class="badge <?= $cls ?>"><?= e($label) ?></span></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
<?php admin_footer();
