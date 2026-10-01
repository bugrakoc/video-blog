<?php
/** @var array $p post row with categories */
$href = url('post/' . $p['slug']);
?>
<article class="card">
  <a class="card-thumb" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
    <img src="<?= e(yt_thumb($p['youtube_id'])) ?>" alt="" loading="lazy" width="480" height="270">
    <span class="play" aria-hidden="true"></span>
  </a>
  <div class="card-body">
    <?php if (!empty($p['categories'])): ?>
      <div class="cats">
        <?php foreach ($p['categories'] as $c): ?>
          <a href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <h2><a href="<?= e($href) ?>"><?= e($p['title']) ?></a></h2>
    <p class="excerpt"><?= e(post_excerpt($p)) ?></p>
    <time class="date" datetime="<?= e(date('c', strtotime($p['published_at'] ?: $p['created_at']))) ?>">
      <?= e(tr_date($p['published_at'] ?: $p['created_at'])) ?>
    </time>
  </div>
</article>
