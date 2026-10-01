<?php
/** @var int $page @var int $perPage @var int $total @var string $basePath */
$pages = (int)ceil($total / max(1, $perPage));
if ($pages <= 1) {
    return;
}
$link = function (int $n) use ($basePath, $query) {
    $qs = [];
    if (!empty($query)) {
        $qs['q'] = $query;
    }
    if ($n > 1) {
        $qs['page'] = $n;
    }
    return url(ltrim($basePath, '/')) . ($qs ? '?' . http_build_query($qs) : '');
};
?>
<nav class="pagination" aria-label="Sayfalama">
  <?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>" rel="prev">← Yeni</a><?php endif; ?>
  <span>Sayfa <?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>" rel="next">Eski →</a><?php endif; ?>
</nav>
