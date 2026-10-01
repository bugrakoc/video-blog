<?php
declare(strict_types=1);

// Returns the title of a YouTube video (via the public oEmbed endpoint, no API key needed).
require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();

header('Content-Type: application/json; charset=utf-8');

$id = youtube_id((string)($_GET['url'] ?? ''));
if (!$id) {
    echo json_out(['ok' => false, 'error' => 'Geçersiz YouTube bağlantısı.']);
    exit;
}

// Only the validated 11-character ID is used to build the outgoing URL (no SSRF).
$api = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $id);

$body = false;
if (function_exists('curl_init')) {
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'VideoBlog/1.0',
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($http !== 200) {
        $body = false;
    }
} elseif (ini_get('allow_url_fopen')) {
    $body = @file_get_contents($api, false, stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'VideoBlog/1.0']]));
}

$data = $body ? json_decode($body, true) : null;
if (!is_array($data) || empty($data['title'])) {
    echo json_out(['ok' => true, 'id' => $id, 'title' => null, 'error' => 'Başlık alınamadı; elle yazabilirsiniz.']);
    exit;
}
echo json_out([
    'ok'     => true,
    'id'     => $id,
    'title'  => (string)$data['title'],
    'author' => (string)($data['author_name'] ?? ''),
    'thumb'  => yt_thumb($id),
]);
