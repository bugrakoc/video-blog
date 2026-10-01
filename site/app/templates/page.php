<?php /** @var array $pg */ ?>
<article class="post page">
  <h1><?= e($pg['title']) ?></h1>
  <div class="prose"><?= markdown_html($pg['body']) ?></div>
</article>
