<?php
declare(strict_types=1);

/*
 * One-time import of a channel's older videos through the YouTube Data API v3.
 * The channel feed used by the autoposter only lists the latest 15 videos; the API lists them all.
 * The API key is only held in the admin's session while the import runs; it is never stored in the database.
 */

const IMPORT_PAGE_SIZE = 50;

function yt_api_base(): string
{
    // 'youtube_api_base' in config.php overrides the endpoint (used for testing).
    return rtrim((string)(config('youtube_api_base') ?: 'https://www.googleapis.com/youtube/v3'), '/');
}

/** The channel's "all uploads" playlist: the channel ID with the UC prefix swapped for UU. */
function uploads_playlist_id(string $channelId): ?string
{
    return preg_match('/^UC[A-Za-z0-9_-]{22}$/', $channelId) ? 'UU' . substr($channelId, 2) : null;
}

/** Turn a Google API error response into a message the site owner can act on. */
function yt_api_error_message(int $http, ?array $json): string
{
    $haystack = strtolower(json_encode($json['error'] ?? [], JSON_UNESCAPED_UNICODE) ?: '');
    $has = fn(string ...$needles) => (bool)array_filter($needles, fn($n) => str_contains($haystack, $n));

    if ($has('keyinvalid', 'api_key_invalid', 'api key not valid')) {
        return 'API anahtarı geçersiz. Anahtarı eksiksiz kopyaladığınızdan emin olun.';
    }
    if ($has('accessnotconfigured', 'service_disabled', 'has not been used', 'is disabled')) {
        return 'Bu anahtarın projesinde “YouTube Data API v3” etkin değil. Google Cloud’da kitaplıktan etkinleştirin.';
    }
    if ($has('quotaexceeded', 'dailylimitexceeded', 'ratelimitexceeded')) {
        return 'Günlük YouTube API kotası doldu. Yarın “Devam et” ile kaldığınız yerden sürdürebilirsiniz.';
    }
    if ($has('playlistnotfound') || $http === 404) {
        return 'Kanalın yüklemeler listesi bulunamadı. Kanal kimliğini kontrol edin.';
    }
    if ($has('referer', 'referrer', 'ip_address_blocked', 'ipblocked', 'api_target_blocked', 'requests to this api')) {
        return 'Anahtarın kısıtlamaları bu isteğe izin vermiyor. HTTP yönlendirici (referrer) kısıtlamasını kaldırın; yalnızca “YouTube Data API v3” ile sınırlayın.';
    }
    return 'YouTube API hatası (HTTP ' . $http . ').';
}

/** GET an API endpoint. Returns [decoded json|null, error message|null]. */
function yt_api_get(string $endpoint, array $params): array
{
    if (!function_exists('curl_init')) {
        return [null, 'Sunucuda curl etkin değil; içe aktarma yapılamıyor.'];
    }
    $url = yt_api_base() . '/' . $endpoint . '?' . http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXFILESIZE => 4 * 1024 * 1024,
        CURLOPT_USERAGENT => 'VideoBlog/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);   // note: $url (it contains the key) is never included in messages

    if ($body === false) {
        return [null, 'YouTube API’ye bağlanılamadı: ' . ($cerr ?: 'bilinmeyen hata')];
    }
    $json = json_decode((string)$body, true);
    if ($http !== 200) {
        return [null, yt_api_error_message($http, is_array($json) ? $json : null)];
    }
    if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])
        || !array_is_list($json['items']) || (isset($json['nextPageToken']) && !is_string($json['nextPageToken']))) {
        return [null, 'YouTube API beklenmeyen bir yanıt verdi.'];
    }
    return [$json, null];
}

/**
 * One page (up to 50) of the uploads playlist.
 * Returns ['items' => [...], 'next' => token|null, 'total' => int, 'unavailable' => int, 'unlisted' => int] or ['error' => msg].
 * Items: id, title, description, published (DB datetime|null).
 * Unlisted videos are only included when $includeUnlisted is true (they would become public posts).
 */
function import_fetch_page(string $key, string $playlistId, ?string $pageToken, bool $includeUnlisted = false): array
{
    [$json, $err] = yt_api_get('playlistItems', [
        'part' => 'snippet,contentDetails,status',
        'playlistId' => $playlistId,
        'maxResults' => IMPORT_PAGE_SIZE,
        'pageToken' => $pageToken,
        'key' => $key,
    ]);
    if ($err !== null) {
        return ['error' => $err];
    }
    $items = [];
    $unavailable = 0;
    $unlisted = 0;
    foreach ($json['items'] as $it) {
        $id = $it['contentDetails']['videoId'] ?? null;
        $title = trim(form_text((array)($it['snippet'] ?? []), 'title'));
        $privacy = $it['status']['privacyStatus'] ?? null;
        // Deleted and private videos stay in the playlist as placeholders.
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) || !in_array($privacy, ['public', 'unlisted'], true)
            || in_array($title, ['Private video', 'Deleted video', '[Private video]', '[Deleted video]'], true)) {
            $unavailable++;
            continue;
        }
        if ($privacy === 'unlisted' && !$includeUnlisted) {
            $unlisted++;
            continue;
        }
        $published = video_date_to_db(form_text((array)($it['contentDetails'] ?? []), 'videoPublishedAt'));
        if ($published === null) {
            $unavailable++;
            continue;
        }
        $items[] = [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Başlıksız video', 0, 255),
            'description' => form_text((array)($it['snippet'] ?? []), 'description'),
            'published' => $published,
        ];
    }
    return [
        'items' => $items,
        'next' => !empty($json['nextPageToken']) ? (string)$json['nextPageToken'] : null,
        'total' => (int)($json['pageInfo']['totalResults'] ?? 0),
        'unavailable' => $unavailable,
        'unlisted' => $unlisted,
    ];
}

/**
 * Save one video as a post unless the site already has it. Marks it as seen for the autoposter either way.
 * $v: id, title, description, published (DB datetime|null). Returns 'imported' or 'existing'.
 * Shared by the API import and the file import.
 */
function import_save_video(array $v, string $mode): string
{
    $result = 'existing';
    if (!post_exists_for_video($v['id'])) {
        save_post([
            'title'        => $v['title'],
            'slug'         => '',
            'youtube_id'   => $v['id'],
            'body'         => description_to_markdown($v['description']),
            'status'       => $mode === 'publish' ? 'published' : 'draft',
            'published_at' => $v['published'],
            'source'       => 'auto',
        ]);
        $result = 'imported';
    }
    mark_video_seen($v['id']);
    return $result;
}

/** Fresh import state (kept in the admin's session between requests). */
function import_new_state(string $key, string $playlistId, string $mode, bool $includeUnlisted = false): array
{
    return [
        'id' => bin2hex(random_bytes(16)), 'key' => $key, 'playlist' => $playlistId, 'mode' => $mode === 'publish' ? 'publish' : 'draft',
        'include_unlisted' => $includeUnlisted,
        'token' => null, 'seen_tokens' => [], 'pages' => 0, 'total' => 0,
        'imported' => 0, 'existing' => 0, 'unavailable' => 0, 'skipped_unlisted' => 0,
        'done' => false, 'error' => null,
    ];
}

/**
 * Import pages until the playlist ends or the time budget runs out (shared hosts have request time limits).
 * Updates $state in place (resumable via $state['token']). Returns true when everything has been imported.
 */
function import_run(array &$state, float $budgetSeconds): bool
{
    if (!site_lock(CONTENT_LOCK)) {
        $state['error'] = 'Başka bir içe aktarma veya otomatik kontrol çalışıyor. Birkaç saniye sonra tekrar deneyin.';
        return false;
    }

    $started = microtime(true);
    $state['error'] = null;
    try {
        settings_all(true);
        if (uploads_playlist_id(setting('channel_id')) !== $state['playlist']) {
            $state['error'] = 'Kanal ayarlarda değişti. Bu içe aktarmayı iptal edip yeni kanal için yeniden başlatın.';
            return false;
        }
        autopost_ensure_schema();
        ensure_video_id_collations();
        do {
            $page = import_fetch_page($state['key'], $state['playlist'], $state['token'], !empty($state['include_unlisted']));
            if (isset($page['error'])) {
                $state['error'] = $page['error'];
                return false;
            }
            $next = $page['next'];
            if ($next !== null && ($next === $state['token'] || isset($state['seen_tokens'][$next]))) {
                $state['error'] = 'YouTube aynı sayfayı tekrar döndürdü. İşlemi iptal edip yeniden başlatın.';
                return false;
            }

            $counts = db_transaction(function () use ($page, $state): array {
                $counts = ['imported' => 0, 'existing' => 0];
                foreach ($page['items'] as $v) {
                    $counts[import_save_video($v, $state['mode'])]++;
                }
                if ($page['next'] === null) {
                    set_setting('autopost_baseline', '1');
                }
                return $counts;
            });
            $state['total'] = $page['total'];
            $state['imported'] += $counts['imported'];
            $state['existing'] += $counts['existing'];
            $state['unavailable'] += $page['unavailable'];
            $state['skipped_unlisted'] = ($state['skipped_unlisted'] ?? 0) + $page['unlisted'];

            // Only advance after the whole page is saved, so a crash re-runs this page (dedupe makes that safe).
            if ($next !== null) {
                $state['seen_tokens'][$next] = true;
            }
            $state['token'] = $next;
            $state['pages']++;
            if ($page['next'] === null) {
                $state['done'] = true;
                return true;
            }
        } while ((microtime(true) - $started) < $budgetSeconds);

        return false;
    } finally {
        site_unlock(CONTENT_LOCK);
    }
}
