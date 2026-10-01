<?php
declare(strict_types=1);

// One-time import of older videos. Hub for three methods: Google Takeout file, yt-dlp file (both handled by
// import-file.php) and the YouTube Data API (handled here; see app/import.php and app/import_file.php).
require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
require APP_PATH . '/autopost.php';
require APP_PATH . '/import.php';
require APP_PATH . '/import_file.php';
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
$staged = $st ? 0 : import_queue_stats()['total'];       // videos from an uploaded file waiting for confirmation
$limit = upload_limit_bytes();
$limitText = $limit ? 'En fazla ' . round($limit / 1048576, 1) . ' MB.' : '';
$ytdlpCmd = $playlist
    ? 'yt-dlp --skip-download --ignore-no-formats-error --no-warnings --print-to-file "%(.{id,title,description,upload_date,timestamp,channel_id,availability,live_status})j" videolar.jsonl "https://www.youtube.com/playlist?list=' . $playlist . '"'
    : '';

admin_header('Eski videoları içe aktar', 'settings');
?>
<div class="head">
  <h1>Eski videoları içe aktar</h1>
  <a class="btn" href="settings.php">← Ayarlar</a>
</div>

<?php if ($st): ?>
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
    <p>YouTube’un ücretsiz kanal beslemesi yalnızca son 15 videoyu verir. Kanalınızın <strong>tüm</strong> eski videolarını başlıkları, açıklamaları ve gerçek yükleme tarihleriyle sitenize eklemek için aşağıdaki üç yoldan birini seçin. Hepsi bir kerelik bir işlemdir ve hepsi aynı sonucu verir.</p>

    <?php if ($staged > 0): ?>
      <div class="flash ok">Yüklediğiniz bir dosya onayınızı bekliyor (<?= (int)$staged ?> video). <a href="import-file.php">İncele ve içe aktar →</a></div>
    <?php endif; ?>

    <details class="method" open>
      <summary><strong>1. Google Takeout dosyası</strong> <span class="badge live">Önerilen</span><br>
        <span class="hint">Anahtar ve komut gerekmez; Google’dan indirdiğiniz dosyayı yüklersiniz.</span></summary>
      <ol class="steps">
        <li><a href="https://takeout.google.com" target="_blank" rel="noopener">takeout.google.com</a> adresini açın ve kanalın sahibi Google hesabıyla giriş yapın.</li>
        <li>Önce <em>Tümünün seçimini kaldır</em>’a basın, sonra listede <strong>YouTube ve YouTube Music</strong>’i bulup işaretleyin.</li>
        <li>Altındaki <em>Tüm YouTube verileri dahil</em> düğmesine basın, <em>Tümünün seçimini kaldır</em>’ı seçin ve yalnızca <strong>video meta verileri</strong>’ni işaretleyip <em>Tamam</em>’a basın. (İngilizce arayüzde: <em>Deselect all → YouTube and YouTube Music → All YouTube data included → video metadata</em>.) Videoların kendisini seçmeyin; gerekmez ve dosya çok büyür.</li>
        <li>Sayfanın en altında <em>Sonraki adım</em>’a basın. Dışa aktarma türü <em>Bir kez dışa aktar</em>, dosya türü <em>.zip</em> olsun, ardından <em>Dışa aktarma oluştur</em>’a basın.</li>
        <li>Hazır olduğunda Google e-posta ile bir indirme bağlantısı yollar (genellikle birkaç dakika, nadiren birkaç saat sürer). Zip’i indirin ve <strong>açmadan</strong> aşağıdan yükleyin. Birden fazla parça (<code>…-001.zip</code>, <code>…-002.zip</code>) gelirse hepsini birlikte seçin.</li>
      </ol>
      <p class="hint">Özel (private) videolar eklenmez; liste dışı videoları eklemek isteyip istemediğinizi bir sonraki ekranda seçersiniz. Kanal bir marka hesabına bağlıysa, dosyadaki kanal kimliği Ayarlar’dakiyle eşleşmediğinde site sizi uyarır.</p>
      <form method="post" action="import-file.php" enctype="multipart/form-data">
        <?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <label>Takeout dosyası <span class="hint">(.zip veya içindeki video bilgisi .csv dosyası. <?= e($limitText) ?>)</span>
          <input type="file" name="files[]" multiple required accept=".zip,.csv,application/zip,text/csv">
        </label>
        <button class="btn primary">Yükle ve incele</button>
      </form>
    </details>

    <details class="method">
      <summary><strong>2. yt-dlp çıktısı</strong><br>
        <span class="hint">Bilgisayarınızda küçük bir komut çalıştırırsınız; Takeout beklemek istemeyenler için.</span></summary>
      <?php if (!$playlist): ?>
        <p>Komutu hazırlayabilmem için önce <a href="settings.php">Ayarlar</a> sayfasında YouTube kanal kimliğinizi kaydedin.</p>
      <?php else: ?>
        <ol class="steps">
          <li><a href="https://github.com/yt-dlp/yt-dlp#installation" target="_blank" rel="noopener">yt-dlp</a>’yi kurun. Python varsa: <code>pip install -U yt-dlp</code> (Mac’te <code>brew install yt-dlp</code>, Windows’ta <code>yt-dlp.exe</code> da olur). Eskiyse <code>yt-dlp -U</code> ile güncelleyin.</li>
          <li>Terminali (Windows’ta PowerShell veya Komut İstemi) boş bir klasörde açın ve aşağıdaki komutu <strong>olduğu gibi</strong> çalıştırın. Kanalınızdaki her video için açıklama ve tarih okunur; video indirilmez. Çok sayıda videoda birkaç dakika sürebilir.
            <pre class="cmd"><?= e($ytdlpCmd) ?></pre>
          </li>
          <li>Komut bitince klasörde <code>videolar.jsonl</code> oluşur. Komutu ikinci kez çalıştıracaksanız önce bu dosyayı silin (yeni kayıtlar sona eklenir). Dosyayı açmadan aşağıdan yükleyin.</li>
        </ol>
        <p class="hint">“Sign in to confirm you’re not a bot” benzeri bir hata alırsanız komuta <code>--cookies-from-browser chrome</code> ekleyin (tarayıcı adını kendinize göre değiştirin). Komutta <code>--flat-playlist</code> kullanmayın; tarih ve açıklama vermez, site böyle bir dosyayı kabul etmez.</p>
        <form method="post" action="import-file.php" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="upload">
          <label>yt-dlp çıktısı <span class="hint">(<code>videolar.jsonl</code>. <?= e($limitText) ?>)</span>
            <input type="file" name="files[]" required accept=".jsonl,.json,.ndjson,.txt,application/json,text/plain">
          </label>
          <button class="btn primary">Yükle ve incele</button>
        </form>
      <?php endif; ?>
    </details>

    <details class="method">
      <summary><strong>3. YouTube API anahtarı</strong><br>
        <span class="hint">Dosya gerekmez; ücretsiz anahtar alıp yapıştırırsınız (~5 dakika).</span></summary>
      <?php if (!$playlist): ?>
        <p>Önce <a href="settings.php">Ayarlar</a> sayfasında YouTube kanal kimliğinizi kaydedin.</p>
      <?php else: ?>
        <ol class="steps">
          <li><a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud Console</a>’da yeni bir proje oluşturun (adı önemli değil).</li>
          <li>“API’ler ve Hizmetler → Kitaplık”tan <strong>YouTube Data API v3</strong>’ü bulup <em>Etkinleştir</em>’e basın.</li>
          <li>“Kimlik Bilgileri → Kimlik bilgisi oluştur → <strong>API anahtarı</strong>” ile bir anahtar oluşturun.</li>
          <li>Anahtarı düzenleyip “API kısıtlamaları”nda yalnızca <em>YouTube Data API v3</em>’ü seçin. <strong>“HTTP yönlendirici” kısıtlaması koymayın</strong>; istek tarayıcınızdan değil sunucunuzdan gelir.</li>
          <li>Anahtarı aşağıya yapıştırın. İşlem bitince Google Cloud’dan silebilirsiniz.</li>
        </ol>
        <p class="hint">Günlük ücretsiz kota (10.000 birim) bu iş için fazlasıyla yeterlidir: 50 video 1 birim tutar.</p>
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
      <?php endif; ?>
    </details>
  </section>
<?php endif; ?>
<?php admin_footer();
