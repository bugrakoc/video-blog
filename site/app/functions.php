<?php
declare(strict_types=1);

/* ---------- Output & URLs ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    return rtrim((string)config('base_url'), '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function json_out($data): string
{
    // Invalid UTF-8 is replaced instead of making json_encode() fail (which used to crash the page).
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? 'null' : $json;
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

/* ---------- Turkish-safe text handling ---------- */

/** Turkish-aware lowercase (PHP's own lowercasing gets İ and I wrong for Turkish). */
function tr_lower(string $s): string
{
    return mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
}

/** Fold to lowercase ASCII: "Çay İçelim" -> "cay icelim". Used for slugs and search. */
function fold(string $s): string
{
    $s = strtr($s, [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g',
        'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's',
        'Ü' => 'u', 'ü' => 'u',
        'Â' => 'a', 'â' => 'a', 'Î' => 'i', 'î' => 'i', 'Û' => 'u', 'û' => 'u',
        "\u{0307}" => '', // stray combining dot above
    ]);
    static $tl = null;
    if ($tl === null) {
        $tl = class_exists('Transliterator')
            ? (Transliterator::create('Any-Latin; Latin-ASCII') ?: false)
            : false;
    }
    if ($tl) {
        $s = $tl->transliterate($s) ?: $s;
    }
    return strtolower($s);
}

function slugify(string $s, int $max = 100): string
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', fold($s)), '-');
    if (strlen($slug) > $max) {
        $slug = trim(substr($slug, 0, $max), '-');
        $cut = strrpos($slug, '-');
        if ($cut !== false && $cut > $max / 2) {
            $slug = substr($slug, 0, $cut);
        }
    }
    return $slug;
}

/** Make $slug unique within $table (posts, pages, categories), appending -2, -3... */
function unique_slug(string $table, string $slug, ?int $excludeId = null, string $fallback = 'yazi'): string
{
    if (!in_array($table, ['posts', 'pages', 'categories'], true)) {
        throw new InvalidArgumentException('bad table');
    }
    $slug = $slug !== '' ? $slug : $fallback;
    $base = $slug;
    $i = 2;
    $stmt = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE slug = ? AND id <> ?");
    while (true) {
        $stmt->execute([$slug, $excludeId ?? 0]);
        $taken = (int)$stmt->fetchColumn() > 0;
        if (!$taken && !($table === 'pages' && is_reserved_slug($slug))) {
            return $slug;
        }
        $slug = $base . '-' . $i++;
    }
}

function is_reserved_slug(string $slug): bool
{
    return in_array($slug, [
        'post', 'category', 'search', 'admin', 'assets', 'app', 'cron', 'install',
        'index', 'feed', 'feed.xml', 'sitemap.xml', 'robots.txt', 'favicon.ico', 'login', 'logout',
    ], true);
}

/** Accent-folded text stored next to each post for searching. */
function search_text(string $title, string $body): string
{
    $plain = html_entity_decode(strip_tags(markdown_html($body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return fold($title . ' ' . $plain);
}

/** Truncate on a word boundary without splitting multibyte characters. */
function truncate(string $s, int $len = 220): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', mb_scrub($s, 'UTF-8')));
    if (mb_strlen($s) <= $len) {
        return $s;
    }
    $cut = mb_substr($s, 0, $len);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $len * 0.6) {
        $cut = mb_substr($cut, 0, $sp);
    }
    // Character-aware trim: rtrim() works on bytes and used to cut emojis (and À, Ó, Ô...) in half.
    return (string)preg_replace('/[\s,.;:\-–—]+$/u', '', $cut) . '…';
}

/* ---------- Markdown ---------- */

function markdown_html(string $md): string
{
    static $pd = null;
    if ($pd === null) {
        $pd = new Parsedown();
        $pd->setSafeMode(true);
        $pd->setBreaksEnabled(true);
        $pd->setUrlsLinked(true);
    }
    return $pd->text($md);
}

/** Plain-text excerpt: manual override if set, otherwise derived from the body. */
function post_excerpt(array $post, int $len = 220): string
{
    if (!empty($post['excerpt'])) {
        return truncate($post['excerpt'], $len);
    }
    $text = html_entity_decode(strip_tags(markdown_html((string)$post['body'])), ENT_QUOTES, 'UTF-8');
    $text = preg_replace('~https?://\S+~u', '', $text);   // drop bare links from descriptions
    return truncate($text, $len);
}

/* ---------- Dates (Turkish) ---------- */

function tr_date(?string $datetime, bool $withTime = false): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    static $months = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $out = date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    return $withTime ? $out . ' ' . date('H:i', $ts) : $out;
}

/** Turkish-aware alphabetical comparison (Ç after C, Ş after S, ı before i...). */
function tr_compare(string $a, string $b): int
{
    static $col = null;
    if ($col === null) {
        $col = class_exists('Collator') ? new Collator('tr_TR') : false;
    }
    if ($col) {
        return $col->compare($a, $b);
    }
    $order = 'abcçdefgğhıijklmnoöprsştuüvyz';
    $map = [];
    foreach (mb_str_split($order) as $i => $ch) {
        $map[$ch] = chr(65 + $i);
    }
    $key = fn(string $s) => strtr(tr_lower($s), $map);
    return strcmp($key($a), $key($b));
}

/* ---------- YouTube ---------- */

/** Accepts a full URL (watch, youtu.be, embed, shorts, live) or a bare 11-char ID. */
function youtube_id(string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
        return $input;
    }
    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/))([A-Za-z0-9_-]{11})~', $input, $m)) {
        return $m[1];
    }
    return null;
}

function yt_thumb(string $id, string $size = 'hqdefault'): string
{
    return 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/' . $size . '.jpg';
}

function yt_embed_url(string $id): string
{
    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id) . '?rel=0';
}

/* ---------- Settings ---------- */

function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $defaults = [
            'site_name' => 'Video Blog',
            'tagline' => '',
            'channel_id' => '',
            'autopost_mode' => 'draft',
            'default_og_image' => '',
            'twitter_handle' => '',
            'footer_text' => '',
        ];
        $cache = $defaults;
        try {
            foreach (db()->query('SELECT `key`, `value` FROM settings') as $r) {
                $cache[$r['key']] = (string)$r['value'];
            }
        } catch (Throwable $ex) {
            // tables not installed yet
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    $stmt->execute([$key, $value]);
}

/* ---------- Sessions, CSRF, auth ---------- */

const SESSION_NAME = 'vbsid';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $sent = (string)($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        exit('Geçersiz istek (CSRF).');
    }
}

function is_admin(): bool
{
    start_session();
    return !empty($_SESSION['admin_id']);
}

/**
 * Admin preview check for public pages (?preview=1). Ordinary visitors never get a session (no cookie, no
 * session file): one is only opened when the preview flag is present and the browser already has a session cookie.
 */
function is_admin_preview(): bool
{
    return isset($_GET['preview']) && isset($_COOKIE[SESSION_NAME]) && is_admin();
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect(url('admin/login.php'));
    }
}

/**
 * True when the admins table exists and holds at least one account (the site is installed).
 * A missing table means "not installed yet"; any other database error is passed on.
 */
function admin_account_exists(): bool
{
    try {
        return (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? '') === '42S02' || (int)($e->errorInfo[1] ?? 0) === 1146) {
            return false;
        }
        throw $e;
    }
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/* ---------- Rendering ---------- */

/** Render templates/$name.php with $vars inside the shared layout (unless $layout is false). */
function render(string $name, array $vars = [], bool $layout = true): void
{
    $vars += ['meta' => []];
    extract($vars, EXTR_SKIP);
    ob_start();
    require APP_PATH . '/templates/' . $name . '.php';
    $content = ob_get_clean();
    if ($layout) {
        require APP_PATH . '/templates/layout.php';
    } else {
        echo $content;
    }
}

function not_found(): never
{
    http_response_code(404);
    render('404', ['meta' => ['title' => 'Sayfa bulunamadı', 'robots' => 'noindex']]);
    exit;
}

/** Build the SEO/social meta array for a page. */
function seo(array $o = []): array
{
    $siteName = setting('site_name', 'Video Blog');
    $title = $o['title'] ?? $siteName;
    $fullTitle = isset($o['title']) && $o['title'] !== $siteName ? $o['title'] . ' | ' . $siteName : $siteName;
    $img = $o['image'] ?? (setting('default_og_image') ?: '');
    return [
        'title' => $title,
        'full_title' => $fullTitle,
        'description' => truncate($o['description'] ?? setting('tagline'), 160),
        'canonical' => $o['canonical'] ?? null,
        'image' => $img,
        'type' => $o['type'] ?? 'website',
        'robots' => $o['robots'] ?? 'index,follow',
        'jsonld' => $o['jsonld'] ?? null,
        'published' => $o['published'] ?? null,
        'video' => $o['video'] ?? null,
    ];
}
