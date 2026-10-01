<?php
declare(strict_types=1);

// One-time import of all older videos via the YouTube Data API (see app/import.php).
require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
require APP_PATH . '/autopost.php';
require APP_PATH . '/import.php';
admin_boot();

$channel = setting('channel_id');
$playlist = uploads_playlist_id($channel);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cancel') {
        unset($_SESSION['import']);
        flash('ok', 'İçe aktarma iptal edildi. Şimdiye kadar eklenen videolar sitede duruyor.');
        redirect(url('admin/import.php'));
    }

    if ($action === 'start') {
        $key = trim((string)($_POST['api_key'] ?? ''));
        if (!$playlist) {
            flash('error', 'Önce Ayarlar’dan geçerli bir YouTube kanal kimliği kaydedin.');
            redirect(url('admin/settings.php'));
        }
        if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $key)) {
            flash('error', 'API anahtarı geçersiz görünüyor (harf, rakam, “-” ve “_” içermeli).');
            redirect(url('admin/import.php'));
        }
        $_SESSION['import'] = import_new_state($key, $playlist, (string)($_POST['mode'] ?? 'draft'));
        $action = 'continue';
    }

    if ($action === 'continue' && !empty($_SESSION['import'])) {
        $budget = (float)(config('import_time_budget') ?? 20);
        $maxExec = (int)ini_get('max_execution_time');
        if ($maxExec > 0) {
            $budget = min($budget, $maxExec * 0.5);    // leave headroom under the host's time limit
        }
        $state = &$_SESSION['import'];
        try {
            $done = import_run($state, $budget);
        } catch (Throwable $e) {
            error_log('import: ' . $e->getMessage());
            $state['error'] = 'Beklenmeyen hata: ' . $e->getMessage();
            $done = false;
        }
        if ($done) {
            $msg = 'Tamamlandı: ' . $state['imported'] . ' video eklendi ('
                 . ($state['mode'] === 'publish' ? 'yayında' : 'taslak olarak') . '), '
                 . $state['existing'] . ' video zaten sitedeydi';
            if ($state['unavailable'] > 0) {
                $msg .= ', ' . $state['unavailable'] . ' özel/silinmiş video atlandı';
            }
            $msg .= '.';
            $draft = $state['mode'] !== 'publish';
            unset($state);
            unset($_SESSION['import']);                 // drops the API key
            flash('ok', $msg . ($draft ? ' İnceleyip yayınlayabilirsiniz.' : ''));
            redirect(url($draft ? 'admin/posts.php?status=draft' : 'admin/posts.php'));
        }
        if ($state['error']) {
            flash('error', $state['error']);
        }
        unset($state);
    }
    redirect(url('admin/import.php'));
}

$st = $_SESSION['import'] ?? null;

admin_header('Eski videoları içe aktar', 'settings');
?>
<div class="head">
  <h1>Eski videoları içe aktar</h1>
  <a class="btn" href="settings.php">← Ayarlar</a>
</div>

<?php if (!$playlist): ?>
  <p>Önce <a href="settings.php">Ayarlar</a> sayfasında YouTube kanal kimliğinizi kaydedin.</p>

<?php elseif ($st): ?>
  <section class="editor narrow">
    <h2>İçe aktarma sürüyor</h2>
    <p>
      <strong><?= (int)$st['imported'] ?></strong> video eklendi ·
      <?= (int)$st['existing'] ?> zaten vardı ·
      <?= (int)$st['unavailable'] ?> özel/silinmiş atlandı
      <?= $st['total'] ? ' · kanalda toplam ' . (int)$st['total'] . ' video' : '' ?>
    </p>
    <?php if ($st['total'] > 0): ?>
      <progress max="<?= (int)$st['total'] ?>" value="<?= (int)min($st['total'], $st['imported'] + $st['existing'] + $st['unavailable']) ?>" style="width:100%"></progress>
    <?php endif; ?>
    <div class="row-actions">
      <form method="post"<?= $st['error'] ? '' : ' data-autosubmit="800"' ?> class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="continue">
        <button class="btn primary"><?= $st['error'] ? 'Tekrar dene / Devam et' : 'Devam ediliyor… (bekleyin)' ?></button>
      </form>
      <form method="post" class="inline" data-confirm="İçe aktarma iptal edilsin mi? Eklenen videolar silinmez.">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
        <button class="btn">İptal</button>
      </form>
    </div>
    <p class="hint">Sayfayı kapatmayın; işlem bittiğinde otomatik olarak videolar listesine geçilir. Büyük kanallarda birkaç adımda tamamlanır.</p>
  </section>

<?php else: ?>
  <section class="editor narrow">
    <p>YouTube’un ücretsiz kanal beslemesi yalnızca son 15 videoyu verir. Kanalınızın <strong>tüm</strong> eski videolarını başlıkları, açıklamaları ve gerçek yükleme tarihleriyle sitenize eklemek için YouTube Data API kullanılır. Bu işlem bir kerelik yapılır.</p>

    <details>
      <summary>API anahtarı nasıl alınır? (ücretsiz, ~5 dakika)</summary>
      <ol class="steps">
        <li><a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud Console</a>’da yeni bir proje oluşturun (adı önemli değil).</li>
        <li>“API’ler ve Hizmetler → Kitaplık”tan <strong>YouTube Data API v3</strong>’ü bulup <em>Etkinleştir</em>’e basın.</li>
        <li>“Kimlik Bilgileri → Kimlik bilgisi oluştur → <strong>API anahtarı</strong>” ile bir anahtar oluşturun.</li>
        <li>Anahtarı düzenleyip “API kısıtlamaları”nda yalnızca <em>YouTube Data API v3</em>’ü seçin. <strong>“HTTP yönlendirici” kısıtlaması koymayın</strong>; istek tarayıcınızdan değil sunucunuzdan gelir.</li>
        <li>Anahtarı aşağıya yapıştırın. İşlem bitince Google Cloud’dan silebilirsiniz.</li>
      </ol>
      <p class="hint">Günlük ücretsiz kota (10.000 birim) bu iş için fazlasıyla yeterlidir: 50 video 1 birim tutar.</p>
    </details>

    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="start">
      <fieldset>
        <legend>İçe aktar</legend>
        <p class="hint">Kanal: <code><?= e($channel) ?></code></p>
        <label>YouTube API anahtarı <span class="hint">(sitede saklanmaz; yalnızca bu işlem sırasında oturumunuzda tutulur)</span>
          <input type="password" name="api_key" required autocomplete="off" spellcheck="false" placeholder="AIza…">
        </label>
        <label>Eklenen videolar
          <select name="mode">
            <option value="draft" selected>Taslak olarak ekle (önce gözden geçireyim) — önerilir</option>
            <option value="publish">Hepsini doğrudan yayınla</option>
          </select>
        </label>
        <button class="btn primary">İçe aktarmayı başlat</button>
        <p class="hint">Sitede zaten olan videolar atlanır, özel ve silinmiş videolar eklenmez. Daha önce sildiğiniz bir video kanalda hâlâ duruyorsa geri gelir; bu yüzden bu işlemi bir kez çalıştırın.</p>
      </fieldset>
    </form>
  </section>
<?php endif; ?>
<?php admin_footer();
