<?php
/** @var array $post @var array $related @var bool $preview */
$when = $post['published_at'] ?: $post['created_at'];
?>
<article class="post">
  <?php if ($preview): ?>
    <?php if ($post['status'] === 'draft'): ?>
      <p class="notice">Önizleme: bu yazı taslak, henüz yayında değil.</p>
    <?php else: ?>
      <p class="notice">Önizleme: bu yazı <?= e(tr_date($post['published_at'], true)) ?> tarihinde yayına girecek.</p>
    <?php endif; ?>
  <?php endif; ?>
  <h1><?= e($post['title']) ?></h1>
  <p class="meta">
    <time datetime="<?= e(date('c', strtotime($when))) ?>"><?= e(tr_date($when)) ?></time>
    <?php foreach ($post['categories'] as $c): ?>
      · <a href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a>
    <?php endforeach; ?>
  </p>

  <div class="video">
    <iframe src="<?= e(yt_embed_url($post['youtube_id'])) ?>"
            title="<?= e($post['title']) ?>" loading="lazy"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
            referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
  </div>

  <div class="prose"><?= markdown_html($post['body']) ?></div>
  <p class="yt-link"><a href="https://www.youtube.com/watch?v=<?= e($post['youtube_id']) ?>" rel="noopener" target="_blank">YouTube'da izle ↗</a></p>
</article>

<?php if ($related): ?>
  <section class="related">
    <h2>Diğer videolar</h2>
    <div class="grid">
      <?php foreach ($related as $p) { include APP_PATH . '/templates/card.php'; } ?>
    </div>
  </section>
<?php endif; ?>
