<?php /** @var array $pg */ ?>
<article class="post page">
  <?php if ($preview): ?><p class="notice">Önizleme: bu sayfa taslak, henüz yayında değil.</p><?php endif; ?>
  <h1><?= e($pg['title']) ?></h1>
  <div class="prose"><?= markdown_html($pg['body']) ?></div>
</article>
