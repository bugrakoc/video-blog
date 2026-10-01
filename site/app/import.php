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
    if (!is_array($json)) {
        return [null, 'YouTube API beklenmeyen bir yanıt verdi.'];
    }
    return [$json, null];
}

/**
 * One page (up to 50) of the uploads playlist.
 * Returns ['items' => [...], 'next' => token|null, 'total' => int, 'unavailable' => int] or ['error' => msg].
 * Items: id, title, description, published (DB datetime|null).
 */
function import_fetch_page(string $key, string $playlistId, ?string $pageToken): array
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
    foreach ((array)($json['items'] ?? []) as $it) {
        $id = (string)($it['contentDetails']['videoId'] ?? '');
        $title = trim((string)($it['snippet']['title'] ?? ''));
        $privacy = (string)($it['status']['privacyStatus'] ?? 'public');
        // Deleted and private videos stay in the playlist as placeholders.
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id) || $privacy === 'private'
            || in_array($title, ['Private video', 'Deleted video'], true)) {
            $unavailable++;
            continue;
        }
        $items[] = [
            'id' => $id,
            'title' => mb_substr($title !== '' ? $title : 'Başlıksız video', 0, 255),
            'description' => (string)($it['snippet']['description'] ?? ''),
            'published' => video_date_to_db($it['contentDetails']['videoPublishedAt'] ?? ($it['snippet']['publishedAt'] ?? null)),
        ];
    }
    return [
        'items' => $items,
        'next' => !empty($json['nextPageToken']) ? (string)$json['nextPageToken'] : null,
        'total' => (int)($json['pageInfo']['totalResults'] ?? 0),
        'unavailable' => $unavailable,
    ];
}

/** Fresh import state (kept in the admin's session between requests). */
function import_new_state(string $key, string $playlistId, string $mode): array
{
    return [
        'key' => $key, 'playlist' => $playlistId, 'mode' => $mode === 'publish' ? 'publish' : 'draft',
        'token' => null, 'pages' => 0, 'total' => 0,
        'imported' => 0, 'existing' => 0, 'unavailable' => 0,
        'done' => false, 'error' => null,
    ];
}

/**
 * Import pages until the playlist ends or the time budget runs out (shared hosts have request time limits).
 * Updates $state in place (resumable via $state['token']). Returns true when everything has been imported.
 */
function import_run(array &$state, float $budgetSeconds): bool
{
    autopost_ensure_schema();
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
        $inPosts = $pdo->prepare('SELECT 1 FROM posts WHERE youtube_id = ?');
        $markSeen = $pdo->prepare('INSERT IGNORE INTO autopost_seen (youtube_id) VALUES (?)');

        do {
            $page = import_fetch_page($state['key'], $state['playlist'], $state['token']);
            if (isset($page['error'])) {
                $state['error'] = $page['error'];
                return false;
            }
            if ($state['total'] === 0) {
                $state['total'] = $page['total'];
            }
            $state['unavailable'] += $page['unavailable'];

            foreach ($page['items'] as $v) {
                $inPosts->execute([$v['id']]);
                if ($inPosts->fetchColumn()) {
                    $state['existing']++;
                    $markSeen->execute([$v['id']]);
                    continue;
                }
                try {
                    save_post([
                        'title'        => $v['title'],
                        'slug'         => '',
                        'youtube_id'   => $v['id'],
                        'body'         => description_to_markdown($v['description']),
                        'status'       => $state['mode'] === 'publish' ? 'published' : 'draft',
                        'published_at' => $v['published'],
                        'source'       => 'auto',
                    ]);
                    $state['imported']++;
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                        throw $e;
                    }
                    $state['existing']++;                 // added by someone else meanwhile
                }
                $markSeen->execute([$v['id']]);
            }

            // Only advance after the whole page is saved, so a crash re-runs this page (dedupe makes that safe).
            $state['token'] = $page['next'];
            $state['pages']++;
            if ($page['next'] === null) {
                $state['done'] = true;
                set_setting('autopost_baseline', '1');    // everything is known now; the autoposter only needs future videos
                return true;
            }
        } while ((microtime(true) - $started) < $budgetSeconds);

        return false;
    } finally {
        $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([AUTOPOST_LOCK]);
    }
}
