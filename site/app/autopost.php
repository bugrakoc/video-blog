<?php
declare(strict_types=1);

/*
 * Autoposter: imports new videos from the channel's public YouTube feed.
 * No API key needed. Used by cron/autopost.php (cron) and admin/autopost.php (manual run).
 */

const AUTOPOST_LOCK = 'videoblog_autopost';

// autopost_ensure_schema() and mark_video_seen() live in queries.php, because the admin panel also needs them.

function autopost_feed_url(string $channelId): string
{
    // 'autopost_feed_url' in config.php overrides the URL (used for testing).
    $override = config('autopost_feed_url');
    return $override ? (string)$override
        : 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId);
}

/** GET a URL. Returns [body, null] on success or [null, error message] on failure. */
function autopost_http_get(string $url): array
{
    $maxBytes = 4 * 1024 * 1024;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_USERAGENT => 'VideoBlog/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [null, 'Bağlantı hatası: ' . ($err ?: 'bilinmeyen hata')];
        }
        if ($http === 404) {
            return [null, 'Kanal bulunamadı (404). Kanal kimliğini kontrol edin.'];
        }
        if ($http !== 200) {
            return [null, 'YouTube beklenmeyen bir yanıt verdi (HTTP ' . $http . ').'];
        }
        return [(string)$body, null];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'VideoBlog/1.0', 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx, 0, $maxBytes);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
        if ($body === false || $status !== 200) {
            return [null, $status === 404 ? 'Kanal bulunamadı (404). Kanal kimliğini kontrol edin.' : 'YouTube kanalına ulaşılamadı.'];
        }
        return [$body, null];
    }
    return [null, 'Sunucuda curl veya allow_url_fopen etkin değil.'];
}

/**
 * Convert an ISO 8601 timestamp from YouTube to a DB datetime in the site's timezone.
 * Never returns a future time (clock skew would otherwise hide an imported video).
 */
function video_date_to_db(?string $iso): ?string
{
    $iso = trim((string)$iso);
    if ($iso === '') {
        return null;
    }
    try {
        $dt = new DateTime($iso);
        if ($dt->getTimestamp() > time()) {
            $dt = new DateTime('now');
        }
        return $dt->setTimezone(new DateTimeZone((string)(config('timezone') ?: 'Europe/Istanbul')))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Parse a YouTube Atom feed. Returns entries newest-first:
 * [['id', 'title', 'published' (DB datetime|null), 'description', 'is_short'], ...]
 * Throws RuntimeException when the XML is unusable.
 */
function autopost_parse_feed(string $xml): array
{
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) {
        throw new RuntimeException('Beslemesi (feed) okunamadı: geçerli bir XML değil.');
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('a', 'http://www.w3.org/2005/Atom');
    $xp->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
    $xp->registerNamespace('media', 'http://search.yahoo.com/mrss/');

    $out = [];
    foreach ($xp->query('//a:entry') as $entry) {
        $id = trim((string)$xp->evaluate('string(yt:videoId)', $entry));
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            continue;
        }
        $title = trim((string)$xp->evaluate('string(a:title)', $entry));
        $desc = (string)$xp->evaluate('string(media:group/media:description)', $entry);
        $link = (string)$xp->evaluate('string(a:link[@rel="alternate"]/@href)', $entry);
        $out[] = [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Başlıksız video', 0, 255),
            'published' => video_date_to_db(trim((string)$xp->evaluate('string(a:published)', $entry))),
            'description' => $desc,
            'is_short' => stripos($link, '/shorts/') !== false,
        ];
    }
    return $out;
}

/**
 * Turn a plain-text YouTube description into safe Markdown for the body field.
 * Keeps line breaks and links; stops "#hashtag" lines from becoming headings.
 */
function description_to_markdown(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        $line = rtrim($line);
        if ($line !== '' && $line[0] === '#') {
            $line = '\\' . $line;
        }
        $lines[] = $line;
    }
    $text = trim(implode("\n", $lines));
    return preg_replace("/\n{3,}/", "\n\n", $text);
}

function autopost_record(bool $ok, string $message): void
{
    set_setting('autopost_last_run', date('Y-m-d H:i:s'));
    set_setting('autopost_last_ok', $ok ? '1' : '0');
    set_setting('autopost_last_result', $message);
}

/**
 * Check the channel feed and import new videos.
 * $importAll = also import videos that were only "seen" (the baseline) but are not on the site.
 * Returns ['ok' => bool, 'message' => string, 'imported' => string[] titles].
 */
function autopost_run(bool $importAll = false): array
{
    $channel = trim(setting('channel_id'));
    if ($channel === '' && !config('autopost_feed_url')) {
        return ['ok' => false, 'message' => 'YouTube kanal kimliği ayarlanmamış.', 'imported' => []];
    }

    autopost_ensure_schema();
    $pdo = db();

    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([AUTOPOST_LOCK]);
    if ((int)$lock->fetchColumn() !== 1) {
        return ['ok' => true, 'message' => 'Başka bir kontrol zaten çalışıyor; atlandı.', 'imported' => []];
    }

    try {
        [$body, $error] = autopost_http_get(autopost_feed_url($channel));
        if ($error !== null) {
            autopost_record(false, $error);
            return ['ok' => false, 'message' => $error, 'imported' => []];
        }
        try {
            $entries = autopost_parse_feed((string)$body);
        } catch (RuntimeException $e) {
            autopost_record(false, $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage(), 'imported' => []];
        }

        $isPublish = setting('autopost_mode', 'draft') === 'publish';
        $skipShorts = setting('autopost_shorts', 'include') === 'skip';
        $baseline = setting('autopost_baseline') === '';

        $inPosts = $pdo->prepare('SELECT 1 FROM posts WHERE youtube_id = ?');
        $isSeen = $pdo->prepare('SELECT 1 FROM autopost_seen WHERE youtube_id = ?');
        $markSeen = $pdo->prepare('INSERT IGNORE INTO autopost_seen (youtube_id) VALUES (?)');

        // First ever run: remember what already exists on the channel, import nothing.
        if ($baseline && !$importAll) {
            foreach ($entries as $en) {
                $markSeen->execute([$en['id']]);
            }
            set_setting('autopost_baseline', '1');
            $msg = 'İlk kontrol tamamlandı: kanaldaki ' . count($entries) . ' mevcut video işaretlendi, hiçbiri eklenmedi. '
                 . 'Bundan sonra yüklenen yeni videolar otomatik eklenecek.';
            autopost_record(true, $msg);
            return ['ok' => true, 'message' => $msg, 'imported' => []];
        }

        $imported = [];
        $skipped = 0;
        foreach (array_reverse($entries) as $en) {          // oldest first
            $inPosts->execute([$en['id']]);
            if ($inPosts->fetchColumn()) {
                $markSeen->execute([$en['id']]);
                continue;
            }
            if (!$importAll) {
                $isSeen->execute([$en['id']]);
                if ($isSeen->fetchColumn()) {
                    continue;                                // handled before (maybe deleted on purpose)
                }
            }
            if ($skipShorts && $en['is_short']) {
                $markSeen->execute([$en['id']]);
                $skipped++;
                continue;
            }
            try {
                save_post([
                    'title'        => $en['title'],
                    'slug'         => '',
                    'youtube_id'   => $en['id'],
                    'body'         => description_to_markdown($en['description']),
                    'status'       => $isPublish ? 'published' : 'draft',
                    'published_at' => $en['published'],
                    'source'       => 'auto',
                ]);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {  // duplicate: added by someone else meanwhile
                    $markSeen->execute([$en['id']]);
                    continue;
                }
                throw $e;
            }
            $markSeen->execute([$en['id']]);
            $imported[] = $en['title'];
        }
        set_setting('autopost_baseline', '1');

        $n = count($imported);
        if ($n > 0) {
            $msg = $n . ' yeni video eklendi (' . ($isPublish ? 'yayında' : 'taslak olarak') . ').';
        } else {
            $msg = 'Yeni video yok.';
        }
        if ($skipped > 0) {
            $msg .= ' ' . $skipped . ' Shorts atlandı.';
        }
        autopost_record(true, $msg);
        return ['ok' => true, 'message' => $msg, 'imported' => $imported];
    } finally {
        $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([AUTOPOST_LOCK]);
    }
}
