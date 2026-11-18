<?php
declare(strict_types=1);

// Returns the title of a YouTube video (via the public oEmbed endpoint, no API key needed).
require __DIR__ . '/../app/bootstrap.php';
require APP_PATH . '/admin.php';
admin_boot();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$id = youtube_id(form_text($_GET, 'url'));
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
        CURLOPT_MAXFILESIZE => 65536,
        CURLOPT_USERAGENT => 'VideoBlog/1.0',
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($http !== 200) {
        $body = false;
    }
} elseif (ini_get('allow_url_fopen')) {
    $body = @file_get_contents($api, false, stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'VideoBlog/1.0', 'follow_location' => 0]]), 0, 65537);
}

$data = is_string($body) && strlen($body) <= 65536 ? json_decode($body, true) : null;
if (!is_array($data) || form_text($data, 'title') === '') {
    echo json_out(['ok' => true, 'id' => $id, 'title' => null, 'error' => 'Başlık alınamadı; elle yazabilirsiniz.']);
    exit;
}
echo json_out([
    'ok'     => true,
    'id'     => $id,
    'title'  => mb_substr(form_text($data, 'title'), 0, 255),
    'author' => form_text($data, 'author_name'),
    'thumb'  => yt_thumb($id),
]);
