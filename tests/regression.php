<?php
declare(strict_types=1);

require __DIR__ . '/../site/app/bootstrap.php';
require APP_PATH . '/admin.php';
require APP_PATH . '/autopost.php';
require APP_PATH . '/import_file.php';

ini_set('display_errors', '1');
set_error_handler(static function (int $number, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $number, $file, $line);
});
date_default_timezone_set('Europe/Istanbul');

function expect_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
}

$id = 'Abcdefghijk';
foreach ([$id, "https://WWW.YouTube.com/watch?a=1&v=$id", "youtu.be/$id?t=12", "https://www.youtube-nocookie.com/embed/$id"] as $input) {
    expect_same($id, youtube_id($input), 'Valid YouTube URL');
}
foreach (["https://notyoutube.com/watch?v=$id", "https://youtu.be/{$id}a", "https://youtube.com/watch?v[]=$id"] as $input) {
    expect_same(null, youtube_id($input), 'Invalid YouTube URL');
}

expect_same('2024-02-29 01:02:03', parse_local_datetime('2024-02-29T01:02:03'), 'Second precision');
expect_same(null, parse_local_datetime('2025-02-29T01:02'), 'Impossible date');
expect_same(null, parse_local_datetime('next Thursday'), 'Date must be absolute');
expect_same('2024-07-13 02:00:00', video_date_to_db('2024-07-12T23:00:00Z'), 'Istanbul date conversion');
expect_same(null, video_date_to_db('2024-04-31T03:00:00Z'), 'Reject malformed upload date');
expect_same('0', requested_slug('0', 'Title'), 'Numeric slug unchanged');
expect_same('0', post_excerpt(['body' => 'body', 'excerpt' => '0']), 'Zero excerpt preserved');

$html = markdown_html(description_to_markdown("**Yazı**\n-------\n1. Madde\n[bağlantı] ve `kod` (https://example.org/?a=1&b=2)"));
expect_same(false, (bool)preg_match('~<(?:h[1-6]|ol|hr|strong|code)\b~', $html), 'YouTube description kept as literal text');
expect_same(true, str_contains($html, 'href="https://example.org/?a=1&amp;b=2"'), 'URL still clickable');

$csv = "\nVideo ID;Title;Description;Privacy;Published Time\n$id;Çay;\"İlk satır\nİkinci satır\";Public;2024-07-12T23:00:00Z\n";
$decoded = import_decode_text("\xFF\xFE" . mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8'));
$takeout = import_parse_takeout_csv($decoded, 'videos.csv');
expect_same("İlk satır\nİkinci satır", $takeout['videos'][0]['description'], 'UTF-16, blank rows and multiline CSV');
expect_same($id, $takeout['videos'][0]['id'], 'YouTube ID casing preserved');

$entry = ['id' => $id, 'title' => 'Çay', 'upload_date' => '20240712', 'description' => ''];
$src = import_parse_ytdlp(json_encode($entry), 'videos.jsonl');
expect_same([], $src['videos'], 'Missing privacy stays excluded');
$src = import_parse_ytdlp(json_encode(array_merge($entry, ['availability' => 'public', 'description' => null])), 'videos.jsonl');
expect_same(1, $src['invalid_count'], 'Missing description gets reported');
$src = import_parse_ytdlp(json_encode($entry + ['availability' => 'public']), 'videos.jsonl');
expect_same('2024-07-12 12:00:00', $src['videos'][0]['published'], 'Date-only video accepted');

foreach (['', '<html>Not a feed</html>'] as $badFeed) {
    try {
        autopost_parse_feed($badFeed);
    } catch (RuntimeException $e) {
        continue;
    }
    throw new RuntimeException('Invalid Atom feed must not initialize a baseline');
}

echo "Regression tests passed.\n";
