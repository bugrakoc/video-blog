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
const IMPORT_FILE_MAX_TOTAL = 80 * 1024 * 1024;   // upper limit before checking the server's memory budget
const IMPORT_BATCH = 20;
const IMPORT_MAX_DESCRIPTION_BYTES = 100000;

/* ---------- Schema / queue ---------- */

function import_ensure_schema(): void
{
    autopost_ensure_schema();
    db()->exec(
        "CREATE TABLE IF NOT EXISTS import_queue (
            youtube_id VARCHAR(20) COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description MEDIUMTEXT NULL,
            published_at DATETIME NULL,
            privacy ENUM('public','unlisted') NOT NULL DEFAULT 'public',
            KEY idx_published (published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** Store the queue and its preview together. Call with the content lock held. */
function import_queue_replace(array $videos, array $job): void
{
    db_transaction(function () use ($videos, $job): void {
        $pdo = db();
        $pdo->exec('DELETE FROM import_queue');
        $ins = $pdo->prepare('INSERT INTO import_queue (youtube_id, title, description, published_at, privacy) VALUES (?,?,?,?,?)');
        foreach ($videos as $v) {
            $ins->execute([$v['id'], $v['title'], $v['description'], $v['published'], $v['privacy']]);
        }
        import_file_store_job($job);
    });
}

function import_file_store_job(?array $job): void
{
    set_setting('import_file_job', $job === null ? '' : json_out($job));
}

function import_file_job(string $channel): ?array
{
    $raw = db()->query("SELECT `value` FROM settings WHERE `key` = 'import_file_job'")->fetchColumn();
    if (is_string($raw) && $raw !== '') {
        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
    // A pre-upgrade queue can still be previewed and confirmed.
    if (db()->query('SELECT 1 FROM import_queue LIMIT 1')->fetchColumn()) {
        return ['id' => 'legacy-' . hash('sha256', $channel), 'channel' => $channel, 'report' => null, 'run' => null];
    }
    return null;
}

function import_queue_clear(): void
{
    db_transaction(function (): void {
        db()->exec('DELETE FROM import_queue');
        import_file_store_job(null);
    });
}

/** Counts for the preview: total, already on the site, public/unlisted, date range. */
function import_queue_stats(): array
{
    $r = db()->query(
        "SELECT COUNT(*) AS total,
                SUM(q.privacy = 'public') AS public_n,
                SUM(q.privacy = 'unlisted') AS unlisted_n,
                SUM(p.id IS NOT NULL) AS existing,
                SUM(p.id IS NULL AND q.privacy = 'public') AS new_public,
                SUM(p.id IS NULL AND q.privacy = 'unlisted') AS new_unlisted,
                MIN(q.published_at) AS oldest, MAX(q.published_at) AS newest
         FROM import_queue q LEFT JOIN posts p ON p.youtube_id = q.youtube_id COLLATE utf8mb4_bin"
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
    $limits = array_filter([$toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size'))], fn($n) => $n > 0);
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
 * Expand uploaded files ([['name' => ..., 'path' => ...], ...]) into [label, text, fromArchive] tuples.
 * Zips are opened in memory (nothing is extracted to disk). Also returns notes about files that were skipped.
 * @return array{0: array, 1: array}
 */
function import_expand_uploads(array $uploads): array
{
    $blobs = [];
    $notes = [];
    $remainingEntries = 10000; // Also bound directory scans and per-entry bookkeeping for split ZIPs.
    $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
    $budget = $memoryLimit > 0
        ? min(IMPORT_FILE_MAX_TOTAL, max(0, intdiv($memoryLimit - memory_get_usage(true), 4)))
        : IMPORT_FILE_MAX_TOTAL;

    foreach ($uploads as $up) {
        $label = import_label((string)$up['name']);
        $size = filesize($up['path']);
        if ($size === false) {
            throw new RuntimeException('“' . $label . '” okunamadı.');
        }
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
            if ($zip->open($up['path'], ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('“' . $label . '” zip dosyası açılamadı; bozuk olabilir. Takeout dosyasını yeniden indirin.');
            }
            $remainingEntries -= $zip->numFiles;
            if ($remainingEntries < 0) {
                $zip->close();
                throw new RuntimeException('Arşivlerde çok fazla dosya var. Yalnızca video meta verisi CSV dosyalarını yükleyin.');
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
                $data = $zip->getFromIndex($i, min($declared, IMPORT_FILE_MAX_ENTRY, $budget) + 1);
                if ($data === false) {
                    $notes[] = '“' . $entryLabel . '” zip içinden okunamadı.';
                    continue;
                }
                if (strlen($data) !== $declared) {
                    $notes[] = '“' . $entryLabel . '” dosyasının boyutu arşiv kaydıyla eşleşmiyor.';
                    continue;
                }
                $budget -= strlen($data);
                $text = import_decode_text($data);
                if ($text === null) {
                    $notes[] = '“' . $entryLabel . '” metin olarak okunamadı (karakter kodlaması tanınmadı).';
                    continue;
                }
                $blobs[] = [$entryLabel, $text, true];
            }
            $zip->close();
            continue;
        }

        if ($size > IMPORT_FILE_MAX_ENTRY || $size > $budget) {
            throw new RuntimeException('“' . $label . '” çok büyük (en fazla ' . (int)(IMPORT_FILE_MAX_ENTRY / 1048576) . ' MB). Yalnızca video meta verilerini içeren dosyayı yükleyin.');
        }
        $data = file_get_contents($up['path'], false, null, 0, min(IMPORT_FILE_MAX_ENTRY, $budget) + 1);
        if ($data === false || strlen($data) > min(IMPORT_FILE_MAX_ENTRY, $budget)) {
            throw new RuntimeException('“' . $label . '” okunamadı veya dosya çok büyük.');
        }
        $budget -= strlen($data);
        $text = import_decode_text($data);
        if ($text === null) {
            throw new RuntimeException('“' . $label . '” metin dosyası olarak okunamadı (karakter kodlaması tanınmadı).');
        }
        $blobs[] = [$label, $text, false];
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

/** Read nonempty CSV records, using the first nonempty record to select the delimiter. */
function import_csv_rows(string $text): Generator
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $delim = ',';
    try {
        while (($line = fgets($fh)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $comma = str_getcsv($line, ',', '"', '');
            $semi = str_getcsv($line, ';', '"', '');
            $delim = count($semi) > count($comma) ? ';' : ',';
            yield $delim === ';' ? $semi : $comma;
            break;
        }
        while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($row !== [null] && array_filter($row, fn($c) => trim((string)$c) !== '')) {
                yield $row;
            }
        }
    } finally {
        fclose($fh);
    }
}

/**
 * Parse one CSV. Returns null when it is not the Takeout video table (other Takeout CSVs are ignored),
 * otherwise a source result (see import_new_source()).
 */
function import_parse_takeout_csv(string $text, string $label): ?array
{
    $rows = import_csv_rows($text);
    $header = $rows->current();
    if ($header === null) {
        return null;
    }
    $rows->next();
    $cols = import_takeout_columns($header);
    $byPosition = false;

    $named = isset($cols['id'], $cols['title'], $cols['description'], $cols['privacy']) && (isset($cols['published']) || isset($cols['created']));
    if (!$named) {
        // Unknown header language: fall back to the fixed 28-column layout, but only if the data fits it.
        if (count($header) !== 28 || !$rows->valid()) {
            return null;
        }
        $first = $rows->current();
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', trim((string)($first[0] ?? ''))) || (video_date_to_db($first[27] ?? null) ?? video_date_to_db($first[26] ?? null)) === null) {
            return null;
        }
        $cols = IMPORT_TAKEOUT_POSITIONS;
        $byPosition = true;
    }

    $src = import_new_source('Google Takeout');
    $src['by_position'] = $byPosition;
    $get = fn(array $r, string $k): string => isset($cols[$k]) ? trim((string)($r[$cols[$k]] ?? '')) : '';

    for (; $rows->valid(); $rows->next()) {
        $r = $rows->current();
        $where = $label . ', kayıt ' . $rows->key();
        $id = $get($r, 'id');                            // Takeout sometimes leaves a leading space on IDs
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            import_note_invalid($src, $where, 'geçersiz video kimliği');
            continue;
        }
        if (count($r) !== count($header)) {
            import_note_invalid($src, $where, 'sütun sayısı geçersiz');
            $src['excluded_ids'][$id] = true;
            continue;
        }
        $privacy = import_privacy($get($r, 'privacy'));
        $title = $get($r, 'title');
        if ($privacy === 'private' || import_placeholder_title($title)) {
            $src['skipped']['private']++;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        if ($privacy === null) {
            $raw = mb_substr($get($r, 'privacy'), 0, 80);
            $src['unknown_privacy'][$raw] = ($src['unknown_privacy'][$raw] ?? 0) + 1;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        if ($privacy === 'unlisted') {
            $src['unlisted_ids'][$id] = true;
        }
        if (preg_match('/(basarisiz|failed|reddedil|rejected|silin|delet|iptal|abandon|processing|unprocessed|isleniyor)/', fold($get($r, 'state')))) {
            $src['skipped']['state']++;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        $when = $get($r, 'published') !== '' ? $get($r, 'published') : $get($r, 'created');
        $published = video_date_to_db($when);
        if ($published === null) {
            import_note_invalid($src, $where, 'yayın tarihi okunamadı');
            continue;
        }
        if (strtotime($published) > time()) {
            $src['skipped']['live']++;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        if ($title === '' || strlen($r[$cols['description']]) > IMPORT_MAX_DESCRIPTION_BYTES) {
            import_note_invalid($src, $where, 'başlık veya açıklama geçersiz');
            continue;
        }
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
function import_ytdlp_objects(string $text, string $label): Generator
{
    $text = trim($text);
    if ($text === '') {
        return;
    }
    // A compact JSONL file may contain many full --dump-json records with large format lists.
    // Release the unused fields from one record before decoding the next.
    $length = strlen($text);
    $line = 0;
    for ($start = 0; $start < $length;) {
        $span = strcspn($text, "\r\n", $start);
        $record = trim(substr($text, $start, $span));
        $start += $span;
        $start += strspn($text, "\r\n", $start);
        $line++;
        if ($record === '') {
            continue;
        }
        $obj = json_decode($record, true);
        if (!is_array($obj) && $line === 1 && $start < $length) {
            // One pretty-printed playlist, array or video object.
            $obj = json_decode($text, true);
            $start = $length;
        }
        if (!is_array($obj)) {
            throw new RuntimeException('“' . $label . '” geçerli yt-dlp JSON çıktısı değil (kayıt ' . $line . ').');
        }
        if ($record[0] === '[') {
            yield from import_ytdlp_entries($obj);
        } else {
            yield from import_ytdlp_entries([$obj]);
        }
        unset($obj);
    }
}

/** Walk a playlist dump without building another array of every video. */
function import_ytdlp_entries(array $objects): Generator
{
    foreach ($objects as $o) {
        if (is_array($o['entries'] ?? null)) {
            yield from import_ytdlp_entries($o['entries']);
        } else {
            yield is_array($o) ? $o : [];
        }
    }
}

function import_parse_ytdlp(string $text, string $label): array
{
    $src = import_new_source('yt-dlp');
    $n = 0;
    foreach (import_ytdlp_objects($text, $label) as $o) {
        $where = $label . ', kayıt ' . ++$n;
        $id = trim(form_text($o, 'id'));
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            import_note_invalid($src, $where, 'geçersiz video kimliği');
            continue;
        }
        $title = trim(form_text($o, 'title'));

        $published = null;
        foreach (['timestamp', 'release_timestamp'] as $k) {
            if (isset($o[$k]) && is_numeric($o[$k]) && (int)$o[$k] > 0) {
                $published = video_date_to_db(gmdate('Y-m-d\TH:i:s\Z', (int)$o[$k]));
                break;
            }
        }
        if ($published === null && preg_match('/^(\d{4})(\d{2})(\d{2})$/D', form_text($o, 'upload_date'), $m)) {
            $published = video_date_to_db("$m[1]-$m[2]-$m[3]T12:00:00");
            if ($published !== null && substr($published, 0, 10) === date('Y-m-d')) {
                $published = min($published, date('Y-m-d H:i:s'));
            }
        }
        $avail = $o['availability'] ?? null;
        if (import_placeholder_title($title)
            || in_array($avail, ['private', 'needs_auth', 'premium_only', 'subscriber_only'], true)) {
            $src['skipped']['private']++;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        if (in_array($o['live_status'] ?? null, ['is_live', 'is_upcoming'], true)) {
            $src['skipped']['live']++;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        if ($avail === 'public') {
            $privacy = 'public';
        } elseif ($avail === 'unlisted') {
            $privacy = 'unlisted';
            $src['unlisted_ids'][$id] = true;
        } else {
            $key = is_string($avail) ? mb_substr($avail, 0, 80) : '(boş veya geçersiz)';
            $src['unknown_privacy'][$key] = ($src['unknown_privacy'][$key] ?? 0) + 1;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        if ($published === null) {
            import_note_invalid($src, $where, 'yükleme tarihi yok veya geçersiz');
            continue;
        }
        if (strtotime($published) > time()) {
            $src['skipped']['live']++;
            $src['excluded_ids'][$id] = true;
            continue;
        }
        if ($title === '' || !is_string($o['description'] ?? null) || strlen($o['description']) > IMPORT_MAX_DESCRIPTION_BYTES) {
            import_note_invalid($src, $where, 'başlık veya açıklama eksik ya da geçersiz');
            continue;
        }
        $src['videos'][] = [
            'id' => $id,
            'title' => mb_substr($title, 0, 255),
            'description' => $o['description'],
            'published' => $published,
            'privacy' => $privacy,
            'channel' => trim(form_text($o, 'channel_id')),
        ];
    }

    if ($n === 0) {
        throw new RuntimeException('“' . $label . '” içinde video bulunamadı.');
    }
    return $src;
}

/* ---------- Combining ---------- */

function import_new_source(string $kind): array
{
    return [
        'kind' => $kind, 'videos' => [], 'excluded_ids' => [], 'unlisted_ids' => [], 'by_position' => false,
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
    foreach ($blobs as [$label, $text, $fromArchive]) {
        $trim = ltrim($text);
        if ($trim === '') {
            $ignored[] = $label;
            continue;
        }
        if ($trim[0] === '{' || $trim[0] === '[') {
            try {
                $src = import_parse_ytdlp($text, $label);
            } catch (RuntimeException $e) {
                // Do not proceed with older records after an explicitly uploaded export failed validation.
                if (!$fromArchive || count($blobs) === 1 || preg_match('/\.(?:jsonl|ndjson)$/i', $label)) {
                    throw $e;
                }
                $notes[] = $e->getMessage();
                continue;
            }
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
    $excludedIds = [];
    $unlistedIds = [];
    $skipped = ['private' => 0, 'state' => 0, 'live' => 0, 'other_channel' => 0];
    $unknown = [];
    $invalid = [];
    $invalidCount = 0;
    $channels = [];
    $kinds = [];
    $byPosition = false;
    foreach ($sources as $s) {
        $kinds[$s['kind']] = true;
        $excludedIds += $s['excluded_ids'];
        $unlistedIds += $s['unlisted_ids'];
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
            if ($channel !== '' && $v['channel'] !== '' && $v['channel'] !== $channel) {
                $skipped['other_channel']++;
                continue;
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
    $videos = array_diff_key($videos, $excludedIds);
    foreach (array_intersect_key($unlistedIds, $videos) as $id => $_) {
        $videos[$id]['privacy'] = 'unlisted';
    }
    if (!$videos) {
        $why = [];
        if ($skipped['private'] > 0) {
            $why[] = $skipped['private'] . ' özel/silinmiş';
        }
        if ($skipped['live'] > 0) {
            $why[] = $skipped['live'] . ' canlı/planlanmış';
        }
        if ($invalidCount > 0) {
            $why[] = $invalidCount . ' eksik/geçersiz';
        }
        if ($unknown) {
            $why[] = array_sum($unknown) . ' gizliliği bilinmeyen';
        }
        throw new RuntimeException('İçe aktarılabilecek video bulunamadı' . ($why ? ' (' . implode(', ', $why) . ' video atlandı)' : '') . '.');
    }

    return [
        'videos' => array_values($videos),
        'report' => [
            'kind' => implode(' + ', array_keys($kinds)),
            'files' => array_map(fn($v) => mb_substr($v, 0, 120), array_slice($recognized, 0, 7)),
            'notes' => array_map(fn($v) => mb_substr($v, 0, 200), array_slice($notes, 0, 10)),
            'skipped' => $skipped,
            'unknown_privacy' => array_slice($unknown, 0, 20, true),
            'invalid' => array_map(fn($v) => mb_substr($v, 0, 200), $invalid),
            'invalid_count' => $invalidCount,
            'duplicates' => $dupes,
            'channel' => $channel !== '' && isset($channels[$channel]) ? $channel : ($channels ? mb_substr((string)array_key_first($channels), 0, 80) : null),
            'by_position' => $byPosition,
        ],
    ];
}

/* ---------- Running ---------- */

function import_file_new_state(string $mode, int $total, int $skippedUnlisted): array
{
    return [
        'mode' => $mode === 'publish' ? 'publish' : 'draft',
        'total' => $total, 'skipped_unlisted' => $skippedUnlisted,
        'imported' => 0, 'existing' => 0, 'error' => null,
    ];
}

/**
 * Turn queued videos into posts until the queue is empty or the time budget runs out.
 * Resumable by design: a row leaves the queue only after its post is saved. Returns true when finished.
 */
function import_file_run(array &$job, float $budgetSeconds): bool
{
    $started = microtime(true);
    $pdo = db();
    do {
        $rows = $pdo->query(
            'SELECT youtube_id, title, description, published_at FROM import_queue
             ORDER BY published_at IS NULL, published_at, youtube_id LIMIT ' . IMPORT_BATCH
        )->fetchAll();
        if (!$rows) {
            return true;
        }
        $job = db_transaction(function () use ($pdo, $job, $rows): array {
            $del = $pdo->prepare('DELETE FROM import_queue WHERE youtube_id = ?');
            $job['run']['error'] = null;
            foreach ($rows as $r) {
                $result = import_save_video([
                    'id' => $r['youtube_id'], 'title' => $r['title'],
                    'description' => (string)$r['description'], 'published' => $r['published_at'],
                ], $job['run']['mode']);
                $del->execute([$r['youtube_id']]);
                $job['run'][$result]++;
            }
            import_file_store_job($job);
            return $job;
        });
        if (count($rows) < IMPORT_BATCH) {
            return true;
        }
    } while ((microtime(true) - $started) < $budgetSeconds);

    return false;
}
