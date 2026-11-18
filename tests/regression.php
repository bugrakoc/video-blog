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

$description = "1) Çay & kahve <ince>\n1. Örnek\n=====\n~Etiket | Çizgi\\\\";
$html = markdown_html(description_to_markdown($description));
expect_same(false, str_contains($html, '<ol>'), 'Parenthesized numbering stays literal');
expect_same(true, str_contains($html, '&lt;ince&gt;'), 'Angle brackets remain escaped HTML text');
expect_same($description, trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'Imported punctuation round trip');
expect_same('<p>1. A &amp; B</p>', markdown_html('1&#46; A &amp; B'), 'Existing stored descriptions render correctly');
expect_same(true, str_contains(markdown_html(description_to_markdown('([https://example.org/a_(b)]).')), 'href="https://example.org/a_(b)"'), 'Mixed trailing URL punctuation');
expect_same('ĞİŞ', import_decode_text("\xD0\xDD\xDE"), 'Turkish Windows-1254 CSV');

$channel = 'UC' . str_repeat('x', 22);
$feed = '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015">'
    . '<yt:channelId>%s</yt:channelId></feed>';
foreach ([$channel, substr($channel, 2)] as $feedId) {
    expect_same([], autopost_parse_feed(sprintf($feed, $feedId), $channel), 'Matching channel feed');
}
try {
    autopost_parse_feed(sprintf($feed, str_repeat('y', 22)), $channel);
    throw new LogicException('Wrong channel was accepted');
} catch (RuntimeException $e) {
    // Expected channel mismatch.
}

$public = $entry + ['availability' => 'public'];
$other = ['id' => 'Another0001'] + $public;
foreach ([json_encode([$public, $other]), json_encode(['entries' => [$public, $other]], JSON_PRETTY_PRINT),
          json_encode($public) . "\r\n" . json_encode($other)] as $json) {
    expect_same([$id, $other['id']], array_column(import_parse_ytdlp($json, 'export')['videos'], 'id'), 'Supported yt-dlp containers');
}

$file = tempnam(sys_get_temp_dir(), 'video-blog-');
try {
    $uploads = [['name' => 'export.jsonl', 'path' => $file]];
    $match = ['channel_id' => $channel] + $public;
    $wrong = ['channel_id' => 'UC' . str_repeat('y', 22)] + $other;
    $noChannel = ['id' => 'NoChannel01'] + $public;
    file_put_contents($file, json_encode([$noChannel, $wrong, $match]));
    $result = import_file_analyze($uploads, $channel);
    expect_same([$id], array_column($result['videos'], 'id'), 'Only the configured channel is staged');
    expect_same(1, $result['report']['skipped']['missing_channel'], 'Missing channel reported');
    expect_same(1, $result['report']['skipped']['other_channel'], 'Different channel reported');
    file_put_contents($file, json_encode($noChannel));
    expect_same([$noChannel['id']], array_column(import_file_analyze($uploads, '')['videos'], 'id'), 'Channel is optional on an unconfigured site');
    try {
        import_file_analyze($uploads, $channel);
        throw new LogicException('An unattributed export was accepted for a configured channel');
    } catch (RuntimeException $error) {
        expect_same(true, str_contains($error->getMessage(), 'kanal kimliği eksik'), 'Empty preview explains the missing channel');
    }
    expect_same(false, import_file_channel_error(['channel' => $channel], $channel) === null, 'Old configured-channel queue needs revalidation');
    expect_same(null, import_file_channel_error(['channel' => ''], ''), 'Old unfiltered queue can resume');
    foreach ([$entry, ['availability' => 'private'] + $entry] as $restricted) {
        file_put_contents($file, json_encode([$public, $other, $restricted]));
        $result = import_file_analyze($uploads, '');
        expect_same([$other['id']], array_column($result['videos'], 'id'), 'A conflicting restriction wins over an older public record');
    }
    file_put_contents($file, json_encode([['availability' => 'unlisted', 'description' => null] + $entry, $public]));
    expect_same('unlisted', import_file_analyze($uploads, '')['videos'][0]['privacy'], 'Unlisted visibility survives an incomplete duplicate');
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        expect_same(true, $zip->open($file, ZipArchive::OVERWRITE), 'Create Takeout fixture');
        $zip->addFromString('Takeout/videos.csv', $csv);
        $zip->addFromString('Takeout/notes.txt', 'Export notes');
        $zip->close();
        $result = import_file_analyze([['name' => 'takeout.zip', 'path' => $file]], '');
        expect_same([$id], array_column($result['videos'], 'id'), 'ZIP metadata import');
    }
} finally {
    unlink($file);
}

echo "Regression tests passed.\n";
