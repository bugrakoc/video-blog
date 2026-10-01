<?php
/** Feed used for the homepage, category pages and search results. */
$query = $query ?? '';
$isSearch = $isSearch ?? false;
?>
<?php if ($heading): ?>
  <h1 class="page-title"><?= e($heading) ?></h1>
  <?php if ($isSearch && $query !== ''): ?>
    <p class="muted"><?= (int)$total ?> sonuç bulundu.</p>
  <?php endif; ?>
<?php endif; ?>

<?php $cats = get_categories(true); if ($cats && !$isSearch): ?>
  <nav class="cat-nav" aria-label="Kategoriler">
    <a href="<?= e(url()) ?>" class="<?= $basePath === '/' ? 'active' : '' ?>">Tümü</a>
    <?php foreach ($cats as $c): ?>
      <a href="<?= e(url('category/' . $c['slug'])) ?>" class="<?= $basePath === '/category/' . $c['slug'] ? 'active' : '' ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if ($posts): ?>
  <div class="grid">
    <?php foreach ($posts as $p) { include APP_PATH . '/templates/card.php'; } ?>
  </div>
  <?php include APP_PATH . '/templates/pagination.php'; ?>
<?php else: ?>
  <p class="empty"><?= $isSearch ? ($query === '' ? 'Aramak için bir kelime yazın.' : 'Aramanızla eşleşen video bulunamadı.') : 'Henüz yayınlanmış video yok.' ?></p>
<?php endif; ?>
