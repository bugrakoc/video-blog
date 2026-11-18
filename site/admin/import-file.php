<?php
declare(strict_types=1);

// Import of older videos from an uploaded Takeout export or yt-dlp output (see app/import_file.php).
// Screens: upload (handled on import.php) -> preview -> progress -> back to the video list.
require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
require APP_PATH . '/autopost.php';
require APP_PATH . '/import.php';
require APP_PATH . '/import_file.php';
admin_boot();

/** Turn $_FILES['files'] (single or multiple) into [['name', 'path'], ...] or throw with a Turkish message. */
function collect_uploads(): array
{
    $f = $_FILES['files'] ?? null;
    if (!is_array($f)) {
        throw new RuntimeException('Dosya seçilmedi.');
    }
    $names = (array)$f['name'];
    $tmps = (array)$f['tmp_name'];
    $errs = (array)$f['error'];
    $out = [];
    foreach ($names as $i => $name) {
        if (!is_string($name) || !is_string($tmps[$i] ?? null) || !is_int($errs[$i] ?? null)) {
            throw new RuntimeException('Yükleme bilgileri geçersiz.');
        }
        $err = $errs[$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $lim = upload_limit_bytes();
            throw new RuntimeException('“' . $name . '” sunucunun yükleme sınırını aşıyor' . ($lim ? ' (en fazla ' . round($lim / 1048576, 1) . ' MB)' : '')
                . '. Takeout’ta yalnızca “video meta verileri”ni seçtiyseniz dosya çok küçük olur.');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$tmps[$i])) {
            throw new RuntimeException('“' . $name . '” yüklenemedi (hata kodu ' . $err . '). Tekrar deneyin.');
        }
        $out[] = ['name' => (string)$name, 'path' => (string)$tmps[$i]];
    }
    if (!$out) {
        throw new RuntimeException('Dosya seçilmedi.');
    }
    if (count($out) > 20) {
        throw new RuntimeException('Bir seferde en fazla 20 dosya yüklenebilir.');
    }
    return $out;
}

$channel = setting('channel_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (!$_POST && !$_FILES && $length > 0 && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
        flash('error', 'Yükleme okunamadı. Dosyanın sunucunun boyut sınırını aşmadığını kontrol edin.');
        redirect(url('admin/import.php'));
    }
    csrf_check();
    $action = form_text($_POST, 'action');
    $back = $action === 'upload' ? 'admin/import.php' : 'admin/import-file.php';

    try {
        if ($action === 'upload') {
            $result = import_file_analyze(collect_uploads(), $channel);
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect(url($back));
    }

    admin_content_lock($back);
    try {
        settings_all(true);
        $currentChannel = setting('channel_id');
        import_ensure_schema();
        ensure_video_id_collations();
        $job = import_file_job($currentChannel);
        $back = handle_import_file_action($action, $job, $currentChannel, $channel, $result ?? null);
    } catch (Throwable $e) {
        error_log('import-file: ' . $e->getMessage());
        flash('error', 'İçe aktarma durdu. Tekrar deneyin veya sunucunun hata günlüğünü kontrol edin.');
    } finally {
        site_unlock(CONTENT_LOCK);
    }
    redirect(url($back));
}

function handle_import_file_action(string $action, ?array $job, string $channel, string $uploadChannel, ?array $result): string
{
    if ($action === 'upload') {
        if ($channel !== $uploadChannel) {
            flash('error', 'Kanal ayarlarda değişti. Dosyayı yeniden yükleyin.');
            return 'admin/import.php';
        }
        if ($job !== null) {
            flash('error', 'Önce mevcut içe aktarmayı tamamlayın veya iptal edin.');
            return 'admin/import-file.php';
        }
        import_queue_replace($result['videos'], [
            'id' => bin2hex(random_bytes(16)), 'channel' => $channel, 'report' => $result['report'], 'run' => null,
        ]);
        return 'admin/import-file.php';
    }

    if ($job === null || form_text($_POST, 'job') !== $job['id']) {
        flash('error', 'İçe aktarma değişti. Güncel durumu kontrol edin.');
        return 'admin/import-file.php';
    }
    if ($action === 'cancel') {
        import_queue_clear();
        flash('ok', 'İçe aktarma iptal edildi. Şimdiye kadar eklenen videolar sitede duruyor.');
        return 'admin/import.php';
    }
    if ($job['channel'] !== $channel) {
        flash('error', 'Kanal ayarlarda değişti. Bu içe aktarmayı iptal edip dosyayı yeniden yükleyin.');
        return 'admin/import.php';
    }

    if ($action === 'start' && $job['run'] === null) {
        $job = db_transaction(function () use ($job): array {
            $skipped = ($_POST['include_unlisted'] ?? null) !== '1'
                ? db()->exec("DELETE FROM import_queue WHERE privacy = 'unlisted'") : 0;
            $job['run'] = import_file_new_state(form_text($_POST, 'mode'), import_queue_stats()['total'], $skipped);
            import_file_store_job($job);
            return $job;
        });
        $action = 'continue';
    }

    if ($action !== 'continue' || $job['run'] === null) {
        return 'admin/import-file.php';
    }

    $budget = (float)(config('import_time_budget') ?? 20);
    $maxExec = (int)ini_get('max_execution_time');
    if ($maxExec > 0) {
        $budget = min($budget, $maxExec * 0.5);
    }
    try {
        if (!import_file_run($job, $budget)) {
            return 'admin/import-file.php';
        }
    } catch (Throwable $e) {
        $job['run']['error'] = 'İçe aktarma durdu. Tekrar deneyin veya sunucunun hata günlüğünü kontrol edin.';
        import_file_store_job($job);
        throw $e;
    }

    $state = $job['run'];
    $draft = $state['mode'] !== 'publish';
    $msg = 'Tamamlandı: ' . $state['imported'] . ' video eklendi (' . ($draft ? 'taslak olarak' : 'yayında') . ')';
    if ($state['existing'] > 0) {
        $msg .= ', ' . $state['existing'] . ' video zaten sitedeydi';
    }
    if ($state['skipped_unlisted'] > 0) {
        $msg .= ', ' . $state['skipped_unlisted'] . ' liste dışı video eklenmedi';
    }
    $msg .= '.';
    if ($draft && $state['imported'] > 0) {
        $msg .= ' İnceleyip yayınlayabilirsiniz.';
    }
    if (setting('autopost_baseline') !== '1' && $channel !== '') {
        $msg .= ' Dosya oluşturulduktan sonra yüklediğiniz videolar varsa, Ayarlar’daki “Son videoları içe aktar” düğmesini bir kez kullanın.';
    }
    import_queue_clear();
    flash('ok', $msg);
    return $draft ? 'admin/posts.php?status=draft' : 'admin/posts.php';
}

import_ensure_schema();
$job = import_file_job($channel);
if ($job === null) {
    redirect(url('admin/import.php'));
}
$stats = import_queue_stats();
$run = $job['run'];
$report = $job['report'];

admin_header('Dosyadan içe aktar', 'settings');
?>
<div class="head">
  <h1>Dosyadan içe aktar</h1>
  <a class="btn" href="import.php">← İçe aktarma yöntemleri</a>
</div>

<?php if ($job['channel'] !== $channel): ?>
  <div class="flash error">Kanal ayarlarda değişti. Bu içe aktarmayı iptal edip dosyayı yeniden yükleyin.</div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="job" value="<?= e($job['id']) ?>">
    <button class="btn" name="action" value="cancel">İptal</button>
  </form>
<?php elseif ($run): ?>
  <?php $left = $stats['total']; $doneN = max(0, $run['total'] - $left); ?>
  <section class="editor narrow">
    <h2>İçe aktarma sürüyor</h2>
    <p>
      <strong><?= (int)$run['imported'] ?></strong> video eklendi ·
      <?= (int)$run['existing'] ?> zaten vardı ·
      <?= (int)$left ?> video sırada
    </p>
    <progress max="<?= (int)max(1, $run['total']) ?>" value="<?= (int)$doneN ?>" style="width:100%"></progress>
    <div class="row-actions">
      <form method="post"<?= $run['error'] ? '' : ' data-autosubmit="800"' ?> class="inline">
        <?= csrf_field() ?><input type="hidden" name="job" value="<?= e($job['id']) ?>"><input type="hidden" name="action" value="continue">
        <button class="btn primary"><?= $run['error'] ? 'Tekrar dene / Devam et' : 'Devam ediliyor… (bekleyin)' ?></button>
      </form>
      <form method="post" class="inline" data-confirm="İçe aktarma iptal edilsin mi? Eklenen videolar silinmez.">
        <?= csrf_field() ?><input type="hidden" name="job" value="<?= e($job['id']) ?>"><input type="hidden" name="action" value="cancel">
        <button class="btn">İptal</button>
      </form>
    </div>
    <p class="hint">Sayfayı kapatmayın; işlem bittiğinde otomatik olarak videolar listesine geçilir.</p>
  </section>

<?php else: ?>
  <section class="editor narrow">
    <h2>Dosya okundu</h2>
    <?php if ($report): ?>
      <p class="hint">
        Kaynak: <strong><?= e($report['kind']) ?></strong>
        (<?= e(implode(', ', array_slice($report['files'], 0, 6))) ?><?= count($report['files']) > 6 ? ' …' : '' ?>)
        <?= $report['channel'] ? ' · Kanal: <code>' . e($report['channel']) . '</code>' : '' ?>
      </p>
    <?php endif; ?>

    <div class="stats">
      <div class="stat"><strong><?= (int)$stats['new_public'] ?></strong><span>eklenecek yeni video</span></div>
      <div class="stat"><strong><?= (int)$stats['existing'] ?></strong><span>sitede zaten var (atlanır)</span></div>
      <?php if ($stats['unlisted_n'] > 0): ?>
        <div class="stat"><strong><?= (int)$stats['unlisted_n'] ?></strong><span>liste dışı video (isteğe bağlı)</span></div>
      <?php endif; ?>
    </div>
    <p class="hint">
      Tarih aralığı: <?= e(tr_date($stats['oldest'])) ?> – <?= e(tr_date($stats['newest'])) ?>.
      <?php if ($report && $report['skipped']['private'] > 0): ?> <?= (int)$report['skipped']['private'] ?> özel/silinmiş video dosyada vardı, eklenmeyecek.<?php endif; ?>
      <?php if ($report && $report['skipped']['live'] > 0): ?> <?= (int)$report['skipped']['live'] ?> canlı/planlanmış yayın atlandı.<?php endif; ?>
      <?php if ($report && $report['skipped']['state'] > 0): ?> <?= (int)$report['skipped']['state'] ?> işlenmemiş/başarısız video atlandı.<?php endif; ?>
      <?php if ($report && $report['skipped']['other_channel'] > 0): ?> <?= (int)$report['skipped']['other_channel'] ?> video başka bir kanala ait olduğu için atlandı.<?php endif; ?>
      <?php if ($report && $report['duplicates'] > 0): ?> <?= (int)$report['duplicates'] ?> tekrar eden kayıt birleştirildi.<?php endif; ?>
    </p>

    <?php if ($report && ($report['invalid_count'] > 0 || $report['unknown_privacy'])): ?>
      <div class="flash error">
        <?php if ($report['invalid_count'] > 0): ?>
          <strong><?= (int)$report['invalid_count'] ?> kayıt okunamadığı için atlandı:</strong>
          <?= e(implode('; ', $report['invalid'])) ?><?= $report['invalid_count'] > count($report['invalid']) ? ' …' : '' ?>
        <?php endif; ?>
        <?php foreach ($report['unknown_privacy'] as $val => $n): ?>
          <div>“<?= e((string)$val) ?>” gizlilik değeri tanınmadığı için <?= (int)$n ?> video atlandı.</div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($report && $report['by_position']): ?>
      <p class="hint">Dosyadaki sütun adları tanınmadı; Takeout’un standart sütun sırası kullanıldı ve ilk satır doğrulandı. Aşağıdaki örnekleri kontrol edin.</p>
    <?php endif; ?>
    <?php if ($report && $report['notes']): ?>
      <p class="hint"><?= e(implode(' ', $report['notes'])) ?></p>
    <?php endif; ?>

    <h2>Örnek (en yeni videolar)</h2>
    <table class="list">
      <?php foreach (import_queue_sample(5) as $s): ?>
        <tr>
          <td class="thumb"><img src="<?= e(yt_thumb($s['youtube_id'], 'mqdefault')) ?>" alt="" loading="lazy"></td>
          <td><?= e($s['title']) ?><br><small class="muted"><?= e(tr_date($s['published_at'])) ?><?= $s['privacy'] === 'unlisted' ? ' · liste dışı' : '' ?></small></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="job" value="<?= e($job['id']) ?>"><input type="hidden" name="action" value="start">
      <fieldset style="margin-top:20px">
        <legend>İçe aktar</legend>
        <label>Eklenen videolar
          <select name="mode">
            <option value="draft" selected>Taslak olarak ekle (önce gözden geçireyim) — önerilir</option>
            <option value="publish">Hepsini doğrudan yayınla</option>
          </select>
        </label>
        <?php if ($stats['unlisted_n'] > 0): ?>
          <label class="check"><input type="checkbox" name="include_unlisted" value="1"> Liste dışı videoları da ekle</label>
          <p class="hint" style="margin-top:-2px">Liste dışı videolar YouTube’da aramada çıkmaz, yalnızca bağlantıyla açılır. Sitenizde herkese açık yazı olacaklarını unutmayın.</p>
        <?php endif; ?>
        <button class="btn primary">İçe aktarmayı başlat</button>
        <p class="hint">Sitede zaten olan videolar atlanır. Daha önce sildiğiniz bir video dosyada varsa geri gelir; bu yüzden bu işlemi bir kez çalıştırın.</p>
      </fieldset>
    </form>
    <form method="post" data-confirm="Vazgeçilsin mi? Yüklenen dosya silinir, sitede bir şey değişmez.">
      <?= csrf_field() ?><input type="hidden" name="job" value="<?= e($job['id']) ?>"><input type="hidden" name="action" value="cancel">
      <button class="link">Vazgeç</button>
    </form>
  </section>
<?php endif; ?>
<?php admin_footer();
