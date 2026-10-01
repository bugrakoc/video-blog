<?php
declare(strict_types=1);

/*
 * Import of older videos from a file the site owner uploads:
 *   - a Google Takeout export ("video metadata": a zip, or the CSV inside it), or
 *   - the output of yt-dlp (JSON lines / JSON array).
 * Both carry the real title, description and publish date of every video. A bare list of IDs is
 * deliberately NOT accepted: it would only produce empty posts.
 *
 * Flow: analyze (parse + validate, pure functions) -> stage in the import_queue table -> preview ->
 * confirm -> resumable, time-budgeted run that turns queue rows into posts (via import_save_video()).
 */

const IMPORT_FILE_MAX_ENTRY = 40 * 1024 * 1024;   // largest single file / zip entry we read
const IMPORT_FILE_MAX_TOTAL = 80 * 1024 * 1024;   // largest total we read per upload
const IMPORT_BATCH = 20;

/* ---------- Schema / queue ---------- */

function import_ensure_schema(): void
{
    autopost_ensure_schema();
    db()->exec(
        "CREATE TABLE IF NOT EXISTS import_queue (
            youtube_id VARCHAR(20) NOT NULL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description MEDIUMTEXT NULL,
            published_at DATETIME NULL,
            privacy ENUM('public','unlisted') NOT NULL DEFAULT 'public',
            KEY idx_published (published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Replace the whole queue with $videos (each: id, title, description, published, privacy). */
function import_queue_replace(array $videos): void
{
    import_ensure_schema();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM import_queue');
        $ins = $pdo->prepare('INSERT INTO import_queue (youtube_id, title, description, published_at, privacy) VALUES (?,?,?,?,?)');
        foreach ($videos as $v) {
            $ins->execute([$v['id'], $v['title'], $v['description'], $v['published'], $v['privacy']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function import_queue_clear(): void
{
    import_ensure_schema();
    db()->exec('DELETE FROM import_queue');
}

/** Counts for the preview: total, already on the site, public/unlisted, date range. */
function import_queue_stats(): array
{
    import_ensure_schema();
    $r = db()->query(
        "SELECT COUNT(*) AS total,
                SUM(q.privacy = 'public') AS public_n,
                SUM(q.privacy = 'unlisted') AS unlisted_n,
                SUM(p.id IS NOT NULL) AS existing,
                SUM(p.id IS NULL AND q.privacy = 'public') AS new_public,
                SUM(p.id IS NULL AND q.privacy = 'unlisted') AS new_unlisted,
                MIN(q.published_at) AS oldest, MAX(q.published_at) AS newest
         FROM import_queue q LEFT JOIN posts p ON p.youtube_id = q.youtube_id"
    )->fetch();
    foreach (['total', 'public_n', 'unlisted_n', 'existing', 'new_public', 'new_unlisted'] as $k) {
        $r[$k] = (int)$r[$k];
    }
    return $r;
}

/** A few newest queued videos, so the owner can eyeball titles and dates before confirming. */
function import_queue_sample(int $n = 5): array
{
    $st = db()->prepare('SELECT youtube_id, title, published_at, privacy FROM import_queue ORDER BY published_at IS NULL, published_at DESC, youtube_id LIMIT ' . (int)$n);
    $st->execute();
    return $st->fetchAll();
}

/* ---------- Reading uploads ---------- */

/** PHP's own upload limit in bytes (the smaller of upload_max_filesize and post_max_size). */
function upload_limit_bytes(): int
{
    $toBytes = function (string $v): int {
        $v = trim($v);
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    };
    $limits = array_filter([$toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size'))]);
    return $limits ? min($limits) : 0;
}

/** Raw file text to UTF-8. Handles BOMs, UTF-16 (PowerShell redirects) and legacy Windows-1254. Null if unusable. */
function import_decode_text(string $raw): ?string
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (str_starts_with($raw, "\xFF\xFE")) {
        return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    } elseif (str_starts_with($raw, "\xFE\xFF")) {
        return mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    } elseif (strlen($raw) >= 4 && $raw[1] === "\0" && $raw[3] === "\0" && $raw[0] !== "\0") {
        return mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');   // UTF-16 without BOM
    } elseif (strlen($raw) >= 4 && $raw[0] === "\0" && $raw[2] === "\0" && $raw[1] !== "\0") {
        return mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
    }
    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }
    $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1254');   // a CSV re-saved by Excel on Turkish Windows
    return is_string($converted) && $converted !== '' ? $converted : null;
}

function import_label(string $name): string
{
    $name = str_replace('\\', '/', $name);
    return mb_scrub(basename($name), 'UTF-8');
}

/**
 * Expand uploaded files ([['name' => ..., 'path' => ...], ...]) into text blobs [['label', 'text'], ...].
 * Zips are opened in memory (nothing is extracted to disk). Also returns notes about files that were skipped.
 * @return array{0: array, 1: array}
 */
function import_expand_uploads(array $uploads): array
{
    $blobs = [];
    $notes = [];
    $budget = IMPORT_FILE_MAX_TOTAL;

    foreach ($uploads as $up) {
        $label = import_label((string)$up['name']);
        $size = (int)filesize($up['path']);
        $fh = fopen($up['path'], 'rb');
        $magic = $fh ? (string)fread($fh, 4) : '';
        if ($fh) {
            fclose($fh);
        }

        if (str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06")) {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('Bu sunucuda zip dosyaları açılamıyor. Zip’i bilgisayarınızda açıp içindeki “videolar.csv” (video meta verileri klasöründeki) dosyasını yükleyin.');
            }
            $zip = new ZipArchive();
            if ($zip->open($up['path']) !== true) {
                throw new RuntimeException('“' . $label . '” zip dosyası açılamadı; bozuk olabilir. Takeout dosyasını yeniden indirin.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = (string)($st['name'] ?? '');
                if ($name === '' || str_ends_with($name, '/') || str_contains($name, '__MACOSX/')) {
                    continue;
                }
                if (!preg_match('/\.(csv|json|jsonl|ndjson|txt)$/i', $name)) {
                    continue;
                }
                $entryLabel = import_label($name);
                $declared = (int)($st['size'] ?? 0);
                if ($declared > IMPORT_FILE_MAX_ENTRY || $declared > $budget) {
                    $notes[] = '“' . $entryLabel . '” çok büyük olduğu için atlandı.';
                    continue;
                }
                $data = $zip->getFromIndex($i, min(IMPORT_FILE_MAX_ENTRY, $budget) + 1);
                if ($data === false) {
                    $notes[] = '“' . $entryLabel . '” zip içinden okunamadı.';
                    continue;
                }
                $budget -= strlen($data);
                $text = import_decode_text($data);
                if ($text === null) {
                    $notes[] = '“' . $entryLabel . '” metin olarak okunamadı (karakter kodlaması tanınmadı).';
                    continue;
                }
                $blobs[] = [$entryLabel, $text];
            }
            $zip->close();
            continue;
        }

        if ($size > IMPORT_FILE_MAX_ENTRY || $size > $budget) {
            throw new RuntimeException('“' . $label . '” çok büyük (en fazla ' . (int)(IMPORT_FILE_MAX_ENTRY / 1048576) . ' MB). Yalnızca video meta verilerini içeren dosyayı yükleyin.');
        }
        $data = (string)file_get_contents($up['path']);
        $budget -= strlen($data);
        $text = import_decode_text($data);
        if ($text === null) {
            throw new RuntimeException('“' . $label . '” metin dosyası olarak okunamadı (karakter kodlaması tanınmadı).');
        }
        $blobs[] = [$label, $text];
    }
    return [$blobs, $notes];
}

/* ---------- Takeout CSV ---------- */

/** Column-name vocabulary: Takeout localizes its headers, so match on folded words, Turkish and English. */
function import_takeout_columns(array $header): array
{
    $map = [];
    foreach ($header as $i => $h) {
        $f = trim(fold((string)$h));
        if (preg_match('/(segment|metin|metni|text)/', $f)) {
            continue;                                   // transcript tables (video metinleri.csv) are not the video table
        }
        $isLang = (bool)preg_match('/\b(dili|dil|language)\b/', $f);
        $cand = null;
        if (preg_match('/^video (kimligi|id)$/', $f)) {
            $cand = 'id';
        } elseif (preg_match('/^kanal (kimligi|id)$/', $f) || $f === 'channel id') {
            $cand = 'channel';
        } elseif (!$isLang && preg_match('/(basli|title)/', $f)) {
            $cand = 'title';
        } elseif (!$isLang && preg_match('/(aciklama|description)/', $f)) {
            $cand = 'description';
        } elseif (preg_match('/^(gizlilik|privacy)$/', $f)) {
            $cand = 'privacy';
        } elseif (preg_match('/(videonun durumu|video state|video status)/', $f)) {
            $cand = 'state';
        } elseif (preg_match('/(yayinlanma|publish)/', $f) && preg_match('/(zaman|time)/', $f)) {
            $cand = 'published';
        } elseif (preg_match('/(olusturulma|creat)/', $f) && preg_match('/(zaman|time)/', $f)) {
            $cand = 'created';
        }
        if ($cand !== null && !isset($map[$cand])) {
            $map[$cand] = $i;
        }
    }
    return $map;
}

/** Layout of the 28-column video table, used only when the header language is not recognised. */
const IMPORT_TAKEOUT_POSITIONS = ['id' => 0, 'description' => 4, 'channel' => 6, 'title' => 22, 'privacy' => 24, 'state' => 25, 'created' => 26, 'published' => 27];

function import_is_iso_date(string $v): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', trim($v));
}

/** Split CSV text into rows. Accepts "," and ";" (a CSV re-saved by Turkish Excel uses ";"). */
function import_csv_rows(string $text): array
{
    $firstLine = strtok($text, "\n") ?: '';
    $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($row === [null] || !array_filter($row, fn($c) => trim((string)$c) !== '')) {
            continue;                                   // blank row
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

/**
 * Parse one CSV. Returns null when it is not the Takeout video table (other Takeout CSVs are ignored),
 * otherwise a source result (see import_new_source()).
 */
function import_parse_takeout_csv(string $text, string $label): ?array
{
    $rows = import_csv_rows($text);
    if (count($rows) < 1) {
        return null;
    }
    $header = array_shift($rows);
    $cols = import_takeout_columns($header);
    $byPosition = false;

    $named = isset($cols['id'], $cols['title']) && (isset($cols['published']) || isset($cols['created']));
    if (!$named) {
        // Unknown header language: fall back to the fixed 28-column layout, but only if the data fits it.
        if (count($header) !== 28 || !$rows) {
            return null;
        }
        $first = $rows[0];
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', trim((string)($first[0] ?? ''))) || !import_is_iso_date((string)($first[27] ?? ''))) {
            return null;
        }
        $cols = IMPORT_TAKEOUT_POSITIONS;
        $byPosition = true;
    }

    $src = import_new_source('Google Takeout');
    $src['by_position'] = $byPosition;
    $get = fn(array $r, string $k): string => isset($cols[$k]) ? trim((string)($r[$cols[$k]] ?? '')) : '';

    foreach ($rows as $n => $r) {
        $where = $label . ', satır ' . ($n + 2);
        $id = $get($r, 'id');                            // Takeout sometimes leaves a leading space on IDs
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            import_note_invalid($src, $where, 'geçersiz video kimliği');
            continue;
        }
        $privacy = import_privacy($get($r, 'privacy'));
        if ($privacy === 'private') {
            $src['skipped']['private']++;
            continue;
        }
        if ($privacy === null) {
            $raw = $get($r, 'privacy');
            $src['unknown_privacy'][$raw] = ($src['unknown_privacy'][$raw] ?? 0) + 1;
            continue;
        }
        if (preg_match('/(basarisiz|failed|reddedil|rejected|silin|delet|iptal|abandon)/', fold($get($r, 'state')))) {
            $src['skipped']['state']++;
            continue;
        }
        $when = $get($r, 'published') !== '' ? $get($r, 'published') : $get($r, 'created');
        $published = video_date_to_db($when);
        if ($published === null) {
            import_note_invalid($src, $where, 'yayın tarihi okunamadı');
            continue;
        }
        $title = $get($r, 'title');
        $src['videos'][] = [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Başlıksız video', 0, 255),
            'description' => isset($cols['description']) ? (string)($r[$cols['description']] ?? '') : '',
            'published' => $published,
            'privacy' => $privacy,
            'channel' => $get($r, 'channel'),
        ];
    }
    return $src;
}

/** 'public' | 'unlisted' | 'private' | null (unrecognised). */
function import_privacy(string $v): ?string
{
    return match (trim(fold($v))) {
        'herkese acik', 'public' => 'public',
        'liste disi', 'unlisted' => 'unlisted',
        'ozel', 'private' => 'private',
        default => null,
    };
}

/* ---------- yt-dlp JSON ---------- */

/** Decode yt-dlp output: JSON lines (--print-to-file / -j), a JSON array, or one object (-J playlist dump). */
function import_ytdlp_objects(string $text, string $label): array
{
    $text = trim($text);
    $objects = [];
    if ($text[0] === '[') {
        $all = json_decode($text, true);
        if (!is_array($all)) {
            throw new RuntimeException('“' . $label . '” geçerli bir JSON dizisi değil.');
        }
        return array_values(array_filter($all, 'is_array'));
    }
    $lines = preg_split('/\r\n|\n|\r/', $text);
    $okLines = true;
    foreach ($lines as $i => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $obj = json_decode($line, true);
        if (!is_array($obj)) {
            $okLines = false;
            break;
        }
        $objects[] = $obj;
    }
    if ($okLines) {
        return $objects;
    }
    $whole = json_decode($text, true);               // a single pretty-printed object
    if (is_array($whole)) {
        return [$whole];
    }
    throw new RuntimeException('“' . $label . '” yt-dlp çıktısı gibi okunamadı (satır ' . ($i + 1) . ' geçerli JSON değil). Dosya komutun ürettiği hâliyle, düzenlenmeden yüklenmeli.');
}

/** Walk playlist dumps (-J) down to the individual videos. */
function import_ytdlp_flatten(array $objs): array
{
    $out = [];
    foreach ($objs as $o) {
        if (isset($o['entries']) && is_array($o['entries'])) {
            array_push($out, ...import_ytdlp_flatten(array_values(array_filter($o['entries'], 'is_array'))));
        } elseif (isset($o['id'])) {
            $out[] = $o;
        }
    }
    return $out;
}

function import_parse_ytdlp(string $text, string $label): array
{
    $entries = import_ytdlp_flatten(import_ytdlp_objects($text, $label));
    $src = import_new_source('yt-dlp');
    if (!$entries) {
        throw new RuntimeException('“' . $label . '” içinde video bulunamadı.');
    }

    $dated = 0;
    $described = 0;
    foreach ($entries as $n => $o) {
        $where = $label . ', kayıt ' . ($n + 1);
        $id = trim((string)($o['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            import_note_invalid($src, $where, 'geçersiz video kimliği');
            continue;
        }
        $title = trim((string)($o['title'] ?? ''));

        $published = null;
        foreach (['timestamp', 'release_timestamp'] as $k) {
            if (isset($o[$k]) && is_numeric($o[$k]) && (int)$o[$k] > 0) {
                $published = video_date_to_db(gmdate('Y-m-d\TH:i:s\Z', (int)$o[$k]));
                break;
            }
        }
        if ($published === null && isset($o['upload_date']) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string)$o['upload_date'], $m)) {
            $published = video_date_to_db("$m[1]-$m[2]-$m[3]T09:00:00Z");   // date only: noon in Istanbul
        }
        if ($published !== null) {
            $dated++;
        }
        if (isset($o['description']) && is_string($o['description'])) {
            $described++;
        }

        $avail = $o['availability'] ?? null;
        if (in_array($title, ['[Private video]', '[Deleted video]', 'Private video', 'Deleted video'], true)
            || in_array($avail, ['private', 'needs_auth', 'premium_only', 'subscriber_only'], true)) {
            $src['skipped']['private']++;
            continue;
        }
        if (in_array($o['live_status'] ?? null, ['is_live', 'is_upcoming'], true)) {
            $src['skipped']['live']++;
            continue;
        }
        if ($avail === null || $avail === 'public') {
            $privacy = 'public';
        } elseif ($avail === 'unlisted') {
            $privacy = 'unlisted';
        } else {
            $key = (string)$avail;
            $src['unknown_privacy'][$key] = ($src['unknown_privacy'][$key] ?? 0) + 1;
            continue;
        }
        if ($published === null) {
            import_note_invalid($src, $where, 'yükleme tarihi yok');
            continue;
        }
        $src['videos'][] = [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Başlıksız video', 0, 255),
            'description' => (string)($o['description'] ?? ''),
            'published' => $published,
            'privacy' => $privacy,
            'channel' => trim((string)($o['channel_id'] ?? '')),
        ];
    }

    // A --flat-playlist run lists IDs and titles only. Without dates and descriptions the posts would be
    // empty shells, which is exactly the degraded import this feature refuses to do.
    if ($dated === 0 || (count($entries) >= 3 && $described === 0)) {
        throw new RuntimeException(
            '“' . $label . '” içinde yükleme tarihleri' . ($dated === 0 ? '' : ' ve açıklamalar')
            . ' yok. Komutta “--flat-playlist” kullanılmış olabilir; yalnızca kimlik ve başlık verir. Rehberdeki komutu olduğu gibi çalıştırıp yeni dosyayı yükleyin.'
        );
    }
    return $src;
}

/* ---------- Combining ---------- */

function import_new_source(string $kind): array
{
    return [
        'kind' => $kind, 'videos' => [], 'by_position' => false,
        'skipped' => ['private' => 0, 'state' => 0, 'live' => 0, 'other_channel' => 0],
        'unknown_privacy' => [], 'invalid' => [], 'invalid_count' => 0,
    ];
}

function import_note_invalid(array &$src, string $where, string $why): void
{
    $src['invalid_count']++;
    if (count($src['invalid']) < 5) {
        $src['invalid'][] = $where . ': ' . $why;
    }
}

/**
 * Parse uploaded files into a validated, de-duplicated list of importable videos.
 * $uploads: [['name' => original name, 'path' => temp file], ...]. $channel: the channel ID saved in Settings ('' if none).
 * Returns ['videos' => [...], 'report' => [...]]; throws RuntimeException (Turkish message) when nothing usable is found.
 */
function import_file_analyze(array $uploads, string $channel): array
{
    [$blobs, $notes] = import_expand_uploads($uploads);

    $sources = [];
    $recognized = [];
    $ignored = [];
    foreach ($blobs as [$label, $text]) {
        $trim = ltrim($text);
        if ($trim === '') {
            $ignored[] = $label;
            continue;
        }
        if ($trim[0] === '{' || $trim[0] === '[') {
            $src = import_parse_ytdlp($text, $label);          // throws with a precise message when unusable
        } else {
            $src = import_parse_takeout_csv($text, $label);
            if ($src === null) {
                $ignored[] = $label;                           // e.g. Takeout's recordings and transcripts CSVs
                continue;
            }
        }
        $recognized[] = $label;
        $sources[] = $src;
    }

    if (!$sources) {
        $msg = 'Yüklenen dosyalarda video bilgisi bulunamadı.';
        if ($ignored) {
            $msg .= ' İncelenen dosyalar: ' . implode(', ', array_slice($ignored, 0, 6)) . (count($ignored) > 6 ? ' …' : '') . '.';
        }
        throw new RuntimeException($msg . ' Takeout’ta yalnızca “video meta verileri” seçili olmalı (içinde “videolar.csv” bulunur); yt-dlp için rehberdeki komutu kullanın. Yalnızca video kimliği listeleri kabul edilmez.');
    }

    // Merge, drop duplicates (a video can appear in several CSVs of a split export).
    $videos = [];
    $dupes = 0;
    $skipped = ['private' => 0, 'state' => 0, 'live' => 0, 'other_channel' => 0];
    $unknown = [];
    $invalid = [];
    $invalidCount = 0;
    $channels = [];
    $kinds = [];
    $byPosition = false;
    foreach ($sources as $s) {
        $kinds[$s['kind']] = true;
        $byPosition = $byPosition || $s['by_position'];
        foreach ($s['skipped'] as $k => $n) {
            $skipped[$k] += $n;
        }
        foreach ($s['unknown_privacy'] as $k => $n) {
            $unknown[$k] = ($unknown[$k] ?? 0) + $n;
        }
        $invalidCount += $s['invalid_count'];
        $invalid = array_slice([...$invalid, ...$s['invalid']], 0, 5);
        foreach ($s['videos'] as $v) {
            if ($v['channel'] !== '') {
                $channels[$v['channel']] = ($channels[$v['channel']] ?? 0) + 1;
            }
            if (isset($videos[$v['id']])) {
                $dupes++;
                continue;
            }
            $videos[$v['id']] = $v;
        }
    }

    // The channel check catches the classic mistake: an export or command run for the wrong channel/account.
    if ($channel !== '' && $channels && !isset($channels[$channel])) {
        $found = array_key_first($channels);
        throw new RuntimeException(
            'Dosyadaki videolar başka bir kanala ait (' . $found . '), ayarlardaki kanal ise ' . $channel . '. '
            . 'Doğru hesap/kanal için dosyayı yeniden oluşturun; kanalı gerçekten değiştirdiyseniz önce Ayarlar’daki kanal kimliğini güncelleyin.'
        );
    }
    if ($channel !== '' && $channels) {
        foreach ($videos as $id => $v) {
            if ($v['channel'] !== '' && $v['channel'] !== $channel) {
                unset($videos[$id]);
                $skipped['other_channel']++;
            }
        }
    }

    if (!$videos) {
        $why = [];
        if ($skipped['private'] > 0) {
            $why[] = $skipped['private'] . ' özel/silinmiş';
        }
        if ($skipped['live'] > 0) {
            $why[] = $skipped['live'] . ' canlı/planlanmış';
        }
        throw new RuntimeException('İçe aktarılabilecek video bulunamadı' . ($why ? ' (' . implode(', ', $why) . ' video atlandı)' : '') . '.');
    }

    $dates = array_filter(array_column($videos, 'published'));
    return [
        'videos' => array_values($videos),
        'report' => [
            'kind' => implode(' + ', array_keys($kinds)),
            'files' => $recognized,
            'ignored' => $ignored,
            'notes' => $notes,
            'skipped' => $skipped,
            'unknown_privacy' => $unknown,
            'invalid' => $invalid,
            'invalid_count' => $invalidCount,
            'duplicates' => $dupes,
            'channel' => $channels ? array_key_first($channels) : null,
            'by_position' => $byPosition,
            'oldest' => $dates ? min($dates) : null,
            'newest' => $dates ? max($dates) : null,
        ],
    ];
}

/* ---------- Running ---------- */

function import_file_new_state(string $mode, int $total, int $skippedUnlisted): array
{
    return [
        'mode' => $mode === 'publish' ? 'publish' : 'draft',
        'total' => $total, 'skipped_unlisted' => $skippedUnlisted,
        'imported' => 0, 'existing' => 0, 'error' => null, 'done' => false,
    ];
}

/**
 * Turn queued videos into posts until the queue is empty or the time budget runs out.
 * Resumable by design: a row leaves the queue only after its post is saved. Returns true when finished.
 */
function import_file_run(array &$state, float $budgetSeconds): bool
{
    import_ensure_schema();
    $pdo = db();

    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([AUTOPOST_LOCK]);
    if ((int)$lock->fetchColumn() !== 1) {
        $state['error'] = 'Başka bir içe aktarma veya otomatik kontrol çalışıyor. Birkaç saniye sonra tekrar deneyin.';
        return false;
    }

    $started = microtime(true);
    $state['error'] = null;
    try {
        $del = $pdo->prepare('DELETE FROM import_queue WHERE youtube_id = ?');
        do {
            $rows = $pdo->query(
                'SELECT youtube_id, title, description, published_at FROM import_queue
                 ORDER BY published_at IS NULL, published_at, youtube_id LIMIT ' . IMPORT_BATCH
            )->fetchAll();
            if (!$rows) {
                $state['done'] = true;
                return true;
            }
            foreach ($rows as $r) {
                $result = import_save_video([
                    'id' => $r['youtube_id'], 'title' => $r['title'],
                    'description' => (string)$r['description'], 'published' => $r['published_at'],
                ], $state['mode']);
                $state[$result]++;
                $del->execute([$r['youtube_id']]);
            }
            if (count($rows) < IMPORT_BATCH) {          // a short batch means the queue is now empty
                $state['done'] = true;
                return true;
            }
        } while ((microtime(true) - $started) < $budgetSeconds);
        return false;
    } finally {
        $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([AUTOPOST_LOCK]);
    }
}
