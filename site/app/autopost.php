<?php
declare(strict_types=1);

/*
 * Autoposter: imports new videos from the channel's public YouTube feed.
 * No API key needed. Used by cron/autopost.php (cron) and admin/autopost.php (manual run).
 */

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
    [$body, $http] = http_get($url, 4 * 1024 * 1024, 20);
    if ($http === 404) {
        return [null, 'Kanal bulunamadı (404). Kanal kimliğini kontrol edin.'];
    }
    if ($body === null) {
        return [null, 'YouTube beslemesi alınamadı veya boyut sınırını aştı.'];
    }
    if ($http !== 200) {
        return [null, 'YouTube beklenmeyen bir yanıt verdi (HTTP ' . $http . ').'];
    }
    return [$body, null];
}

/** Read an absolute video timestamp, preserving its real date in the site's timezone. */
function video_date_to_db(?string $iso): ?string
{
    $iso = trim((string)$iso);
    if (!preg_match('/^[1-9]\d{3}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2}| UTC)?$/D', $iso)) {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($iso);
        if (DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }
        return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function import_placeholder_title(string $title): bool
{
    return in_array($title, ['Private video', 'Deleted video', '[Private video]', '[Deleted video]'], true);
}

/**
 * Parse a YouTube Atom feed. Returns entries newest-first:
 * [['id', 'title', 'published' (DB datetime|null), 'description', 'is_short'], ...]
 * Throws RuntimeException when the XML is unusable.
 */
function autopost_parse_feed(string $xml, string $channel = ''): array
{
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    try {
        $loaded = trim($xml) !== '' && $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }
    if (!$loaded) {
        throw new RuntimeException('Beslemesi (feed) okunamadı: geçerli bir XML değil.');
    }

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('a', 'http://www.w3.org/2005/Atom');
    $xp->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
    $xp->registerNamespace('media', 'http://search.yahoo.com/mrss/');

    if (!$xp->evaluate('boolean(/a:feed)') || $dom->doctype !== null) {
        throw new RuntimeException('Yanıt, geçerli bir YouTube kanal beslemesi değil.');
    }
    $feedChannel = trim((string)$xp->evaluate('string(/a:feed/yt:channelId)'));
    // Channel feeds can omit the UC prefix at the feed level.
    if ($channel !== '' && $feedChannel !== $channel && 'UC' . $feedChannel !== $channel) {
        throw new RuntimeException('Beslemedeki kanal kimliği ayarlarla eşleşmiyor.');
    }

    $out = [];
    foreach ($xp->query('/a:feed/a:entry') as $entry) {
        $id = trim((string)$xp->evaluate('string(yt:videoId)', $entry));
        $published = video_date_to_db($xp->evaluate('string(a:published)', $entry));
        if (youtube_id($id) !== $id || $published === null) {
            throw new RuntimeException('YouTube beslemesinde geçersiz bir video kaydı var.');
        }
        $title = trim((string)$xp->evaluate('string(a:title)', $entry));
        $desc = (string)$xp->evaluate('string(media:group/media:description)', $entry);
        $link = (string)$xp->evaluate('string(a:link[@rel="alternate"]/@href)', $entry);
        $out[$id] = [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Başlıksız video', 0, 255),
            'published' => $published,
            'description' => $desc,
            'is_short' => stripos($link, '/shorts/') !== false,
        ];
    }
    $out = array_values($out);
    usort($out, fn($a, $b) => strcmp($b['published'], $a['published']));
    return $out;
}

/**
 * Turn a plain-text YouTube description into safe Markdown for the body field.
 * Keeps line breaks and links; every line stays plain text (see description_line_to_markdown()).
 */
function description_to_markdown(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
    $lines = array_map('description_line_to_markdown', explode("\n", $text));
    $text = trim(implode("\n", $lines));
    return preg_replace("/\n{3,}/", "\n\n", $text);
}

/** Escape Markdown punctuation while keeping HTTP(S) URLs clickable. */
function description_line_to_markdown(string $line): string
{
    $parts = preg_split('~(https?://[^\s<>"\']+)~iu', trim($line), -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return '';
    }
    foreach ($parts as $i => &$part) {
        if ($i % 2 === 1) {
            $end = strlen($part);
            $excess = [];
            foreach ([')' => '(', ']' => '[', '}' => '{'] as $close => $open) {
                $excess[$close] = substr_count($part, $close) - substr_count($part, $open);
            }
            while ($end > 0) {
                $last = $part[$end - 1];
                if (($excess[$last] ?? 0) > 0) {
                    $excess[$last]--;
                } elseif (!str_contains('.,;:!?', $last)) {
                    break;
                }
                $end--;
            }
            $part = '<' . substr($part, 0, $end) . '>' . substr($part, $end);
        } else {
            $part = strtr($part, [
                '&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '\\' => '&#92;',
                '#' => '\\#', '*' => '\\*', '_' => '\\_', '+' => '\\+', '-' => '\\-',
                '[' => '\\[', ']' => '\\]', '`' => '\\`', '|' => '&#124;', '~' => '&#126;', '=' => '&#61;',
            ]);
            if ($i === 0) {
                $part = preg_replace_callback('/^(\d{1,9})([.)])/',
                    static fn($m) => $m[1] . ($m[2] === '.' ? '&#46;' : '&#41;'), $part);
            }
        }
    }
    unset($part);
    return implode('', $parts);
}

function autopost_record(bool $ok, string $message): void
{
    db_transaction(function () use ($ok, $message): void {
        set_setting('autopost_last_run', date('Y-m-d H:i:s'));
        set_setting('autopost_last_ok', $ok ? '1' : '0');
        set_setting('autopost_last_result', $message);
    });
}

/**
 * Check the channel feed and import new videos.
 * $importAll = also import videos that were only "seen" (the baseline) but are not on the site.
 * Returns ['ok' => bool, 'message' => string, 'imported' => string[] titles].
 */
function autopost_run(bool $importAll = false): array
{
    if (!site_lock(CONTENT_LOCK)) {
        return ['ok' => true, 'message' => 'Başka bir kontrol veya içerik işlemi çalışıyor; atlandı.', 'imported' => []];
    }

    try {
        settings_all(true);
        $channel = trim(setting('channel_id'));
        if ($channel === '' && !config('autopost_feed_url')) {
            return ['ok' => false, 'message' => 'YouTube kanal kimliği ayarlanmamış.', 'imported' => []];
        }
        autopost_ensure_schema();
        ensure_video_id_collations();
        $pdo = db();
        [$body, $error] = autopost_http_get(autopost_feed_url($channel));
        if ($error !== null) {
            autopost_record(false, $error);
            return ['ok' => false, 'message' => $error, 'imported' => []];
        }
        try {
            $entries = autopost_parse_feed((string)$body, $channel);
        } catch (RuntimeException $e) {
            autopost_record(false, $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage(), 'imported' => []];
        }

        $isPublish = setting('autopost_mode', 'draft') === 'publish';
        $skipShorts = setting('autopost_shorts', 'include') === 'skip';
        $baseline = setting('autopost_baseline') === '';

        $isSeen = $pdo->prepare('SELECT 1 FROM autopost_seen WHERE youtube_id = ?');
        $markSeen = $pdo->prepare('INSERT IGNORE INTO autopost_seen (youtube_id) VALUES (?)');

        // First ever run: remember what already exists on the channel, import nothing.
        if ($baseline && !$importAll) {
            db_transaction(function () use ($entries, $markSeen): void {
                foreach ($entries as $en) {
                    $markSeen->execute([$en['id']]);
                }
                set_setting('autopost_baseline', '1');
            });
            $msg = 'İlk kontrol tamamlandı: kanaldaki ' . count($entries) . ' mevcut video işaretlendi, hiçbiri eklenmedi. '
                 . 'Bundan sonra yüklenen yeni videolar otomatik eklenecek.';
            autopost_record(true, $msg);
            return ['ok' => true, 'message' => $msg, 'imported' => []];
        }

        $imported = [];
        $skipped = 0;
        foreach (array_reverse($entries) as $en) {          // oldest first
            if (post_exists_for_video($en['id'])) {
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
            db_transaction(function () use ($en, $isPublish, $markSeen): void {
                save_post([
                    'title'        => $en['title'],
                    'slug'         => '',
                    'youtube_id'   => $en['id'],
                    'body'         => description_to_markdown($en['description']),
                    'status'       => $isPublish ? 'published' : 'draft',
                    'published_at' => $en['published'],
                    'source'       => 'auto',
                ]);
                $markSeen->execute([$en['id']]);
            });
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
    } catch (Throwable $e) {
        autopost_record(false, 'Otomatik kontrol sırasında bir hata oluştu. Ayrıntılar hata günlüğünde.');
        throw $e;
    } finally {
        site_unlock(CONTENT_LOCK);
    }
}
