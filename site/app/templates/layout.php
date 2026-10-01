<?php
/** @var string $content */
/** @var array $meta */
$m = seo($meta);
$site = setting('site_name', 'Video Blog');
$q = $query ?? '';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($m['full_title']) ?></title>
<?php if ($m['description'] !== ''): ?>
<meta name="description" content="<?= e($m['description']) ?>">
<?php endif; ?>
<meta name="robots" content="<?= e($m['robots']) ?>">
<?php if ($m['canonical']): ?>
<link rel="canonical" href="<?= e($m['canonical']) ?>">
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= e($site) ?>" href="<?= e(url('feed.xml')) ?>">
<meta name="color-scheme" content="light dark">

<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:locale" content="tr_TR">
<meta property="og:type" content="<?= e($m['type']) ?>">
<meta property="og:title" content="<?= e($m['title']) ?>">
<?php if ($m['description'] !== ''): ?>
<meta property="og:description" content="<?= e($m['description']) ?>">
<?php endif; ?>
<?php if ($m['canonical']): ?>
<meta property="og:url" content="<?= e($m['canonical']) ?>">
<?php endif; ?>
<?php if ($m['image']): ?>
<meta property="og:image" content="<?= e($m['image']) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= e($m['image']) ?>">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<meta name="twitter:title" content="<?= e($m['title']) ?>">
<?php if ($m['description'] !== ''): ?>
<meta name="twitter:description" content="<?= e($m['description']) ?>">
<?php endif; ?>
<?php if (setting('twitter_handle') !== ''): ?>
<meta name="twitter:site" content="@<?= e(ltrim(setting('twitter_handle'), '@')) ?>">
<?php endif; ?>
<?php if ($m['published']): ?>
<meta property="article:published_time" content="<?= e($m['published']) ?>">
<?php endif; ?>
<?php if ($m['jsonld']): ?>
<script type="application/ld+json"><?= json_out($m['jsonld']) ?></script>
<?php endif; ?>

<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="<?= e(url()) ?>"><?= e($site) ?></a>
    <nav class="nav" aria-label="Ana menü">
      <a href="<?= e(url()) ?>">Videolar</a>
      <?php foreach (get_nav_pages() as $np): ?>
        <a href="<?= e(url($np['slug'])) ?>"><?= e($np['title']) ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="search" action="<?= e(url('search')) ?>" method="get" role="search">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Ara…" aria-label="Ara" maxlength="100">
    </form>
  </div>
</header>

<main class="wrap">
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap">
    <span>© <?= date('Y') ?> <?= e($site) ?></span>
    <?php if (setting('footer_text') !== ''): ?><span><?= e(setting('footer_text')) ?></span><?php endif; ?>
    <a href="<?= e(url('feed.xml')) ?>">RSS</a>
  </div>
</footer>
</body>
</html>
