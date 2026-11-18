<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
header('Referrer-Policy: strict-origin-when-cross-origin');   // YouTube embeds need the origin, so not no-referrer

if (($_GET['preview'] ?? null) === '1') {
    // Browsers and proxies must fetch previews anew, even after a guest received a 404.
    header('Cache-Control: private, no-store');
}
$path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
$prefix = rtrim((string)parse_url(base_url(), PHP_URL_PATH), '/');
if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
    $path = substr($path, strlen($prefix));
}
$path = trim(rawurldecode($path), '/');
$segments = $path === '' ? [] : explode('/', $path);
$perPage = max(1, min(100, (int)(config('posts_per_page') ?: 12)));
// ?page=N must be 1..999999. Anything else becomes 0 and gets a 404 (huge numbers used to cause a database error).
$rawPage = $_GET['page'] ?? '1';
$page = is_string($rawPage) && preg_match('/^[1-9][0-9]{0,5}$/D', $rawPage) ? (int)$rawPage : 0;

try {
    if (!is_file(APP_PATH . '/installed.lock') && is_file(__DIR__ . '/install.php') && !admin_account_exists()) {
        redirect(url('install.php'));
    }

    // /
    if (!$segments || $segments === ['index.php']) {
        [$posts, $total] = $page === 0 ? [[], 0] : list_posts($page, $perPage);
        check_page($page, $posts);
        render('list', [
            'posts' => $posts, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'heading' => null, 'basePath' => '/',
            'meta' => ['description' => setting('tagline'), 'canonical' => url($page > 1 ? "?page=$page" : '')],
        ]);
        exit;
    }

    $first = $segments[0];

    // /post/{slug}
    if ($first === 'post' && count($segments) === 2 && preg_match('/^[a-z0-9-]+$/iD', $segments[1])) {
        $preview = is_admin_preview();
        $post = get_post_by_slug($segments[1], $preview);
        if (!$post) {
            not_found();
        }
        render('post', [
            'post' => $post, 'preview' => $preview && !post_is_live($post),
            'related' => related_posts((int)$post['id']),
            'meta' => post_meta($post),
        ]);
        exit;
    }

    // /category/{slug}
    if ($first === 'category' && count($segments) === 2 && preg_match('/^[a-z0-9-]+$/iD', $segments[1])) {
        $cat = get_category_by_slug($segments[1]);
        if (!$cat) {
            not_found();
        }
        [$posts, $total] = $page === 0 ? [[], 0] : list_posts($page, $perPage, ['category_id' => (int)$cat['id']]);
        check_page($page, $posts);
        render('list', [
            'posts' => $posts, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'heading' => 'Kategori: ' . $cat['name'], 'basePath' => '/category/' . $cat['slug'],
            'meta' => [
                'title' => $cat['name'],
                'description' => $cat['name'] . ' kategorisindeki videolar',
                'canonical' => url('category/' . $cat['slug'] . ($page > 1 ? "?page=$page" : '')),
            ],
        ]);
        exit;
    }

    // /search?q=
    if ($first === 'search' && count($segments) === 1) {
        $q = mb_substr(trim(mb_scrub(is_string($_GET['q'] ?? null) ? $_GET['q'] : '')), 0, 100);
        $posts = [];
        $total = 0;
        if ($q !== '') {
            [$posts, $total] = $page === 0 ? [[], 0] : list_posts($page, $perPage, ['search' => $q]);
        }
        check_page($page, $posts);
        render('list', [
            'posts' => $posts, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'heading' => $q === '' ? 'Ara' : 'Arama: ' . $q, 'basePath' => '/search',
            'query' => $q, 'isSearch' => true,
            'meta' => ['title' => $q === '' ? 'Ara' : 'Arama: ' . $q, 'robots' => 'noindex,follow'],
        ]);
        exit;
    }

    // Feeds & robots
    if ($path === 'feed.xml') {
        require APP_PATH . '/feeds.php';
        output_rss();
        exit;
    }
    if ($path === 'sitemap.xml') {
        require APP_PATH . '/feeds.php';
        output_sitemap($page, isset($_GET['page']));
        exit;
    }
    if ($path === 'robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: " . $prefix . "/admin/\nDisallow: " . $prefix . "/search\n\nSitemap: " . url('sitemap.xml') . "\n";
        exit;
    }

    // /{slug}: static page
    if (count($segments) === 1 && preg_match('/^[a-z0-9-]+$/iD', $first)) {
        $preview = is_admin_preview();
        $pg = get_page_by_slug($first, $preview);
        if ($pg) {
            render('page', [
                'pg' => $pg,
                'preview' => $pg['status'] === 'draft',
                'meta' => [
                    'title' => $pg['title'],
                    'description' => ($pg['meta_description'] ?? '') !== '' ? $pg['meta_description'] : post_excerpt(['body' => $pg['body']], 160),
                    'canonical' => url($pg['slug']),
                    'robots' => $pg['status'] === 'draft' ? 'noindex,nofollow' : 'index,follow',
                ],
            ]);
            exit;
        }
    }

    not_found();
} catch (Throwable $ex) {
    error_log($ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    http_response_code(500);
    if (config('debug')) {
        echo '<pre>' . e((string)$ex) . '</pre>';
    } else {
        echo 'Bir hata oluştu. Lütfen daha sonra tekrar deneyin.';
    }
}

/** 404 for a malformed ?page value or a page past the last one (instead of an empty "page 500"). */
function check_page(int $page, array $posts): void
{
    if ($page === 0 || ($page > 1 && !$posts)) {
        not_found();
    }
}

/** SEO + JSON-LD (VideoObject) for a single post. */
function post_meta(array $post): array
{
    $desc = ($post['meta_description'] ?? '') !== '' ? $post['meta_description'] : post_excerpt($post, 160);
    $canonical = url('post/' . $post['slug']);
    $thumb = yt_thumb($post['youtube_id']);
    $published = $post['published_at'] ?: $post['created_at'];
    return [
        'title' => $post['title'],
        'description' => $desc,
        'canonical' => $canonical,
        'image' => $thumb,
        'type' => 'article',
        'published' => date('c', strtotime($published)),
        'video' => yt_embed_url($post['youtube_id']),
        'robots' => post_is_live($post) ? 'index,follow' : 'noindex,nofollow',
        'jsonld' => [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $post['title'],
            'description' => $desc,
            'thumbnailUrl' => [$thumb],
            'uploadDate' => date('c', strtotime($published)),
            'embedUrl' => yt_embed_url($post['youtube_id']),
            'inLanguage' => 'tr',
        ],
    ];
}
