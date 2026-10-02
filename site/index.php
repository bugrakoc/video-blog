<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

if (!is_file(APP_PATH . '/installed.lock') && is_file(__DIR__ . '/install.php')) {
    // Only a site without an admin account is sent to the installer (a lost lock file alone doesn't count).
    try {
        $installed = admin_account_exists();
    } catch (Throwable $ex) {
        $installed = false;
    }
    if (!$installed) {
        redirect(url('install.php'));
    }
}

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = trim(rawurldecode($path), '/');
$segments = $path === '' ? [] : explode('/', $path);
$perPage = (int)config('posts_per_page') ?: 12;
$page = max(1, (int)($_GET['page'] ?? 1));

try {
    // /
    if (!$segments || $segments === ['index.php']) {
        [$posts, $total] = list_posts($page, $perPage);
        render('list', [
            'posts' => $posts, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'heading' => null, 'basePath' => '/',
            'meta' => ['description' => setting('tagline'), 'canonical' => url($page > 1 ? "?page=$page" : '')],
        ]);
        exit;
    }

    $first = $segments[0];

    // /post/{slug}
    if ($first === 'post' && count($segments) === 2) {
        $preview = is_admin() && isset($_GET['preview']);
        $post = get_post_by_slug($segments[1], $preview);
        if (!$post) {
            not_found();
        }
        render('post', [
            'post' => $post, 'preview' => $preview && $post['status'] !== 'published',
            'related' => related_posts((int)$post['id']),
            'meta' => post_meta($post),
        ]);
        exit;
    }

    // /category/{slug}
    if ($first === 'category' && count($segments) === 2) {
        $cat = get_category_by_slug($segments[1]);
        if (!$cat) {
            not_found();
        }
        [$posts, $total] = list_posts($page, $perPage, ['category_id' => (int)$cat['id']]);
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
        $q = trim((string)($_GET['q'] ?? ''));
        $posts = [];
        $total = 0;
        if ($q !== '') {
            [$posts, $total] = list_posts($page, $perPage, ['search' => mb_substr($q, 0, 100)]);
        }
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
        output_sitemap();
        exit;
    }
    if ($path === 'robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: /admin/\nDisallow: /search\n\nSitemap: " . url('sitemap.xml') . "\n";
        exit;
    }

    // /{slug}: static page
    if (count($segments) === 1) {
        $preview = is_admin() && isset($_GET['preview']);
        $pg = get_page_by_slug($first, $preview);
        if ($pg) {
            render('page', [
                'pg' => $pg,
                'meta' => [
                    'title' => $pg['title'],
                    'description' => $pg['meta_description'] ?: post_excerpt(['body' => $pg['body'], 'excerpt' => null], 160),
                    'canonical' => url($pg['slug']),
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

/** SEO + JSON-LD (VideoObject) for a single post. */
function post_meta(array $post): array
{
    $desc = $post['meta_description'] ?: post_excerpt($post, 160);
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
        'robots' => $post['status'] === 'published' ? 'index,follow' : 'noindex,nofollow',
        'jsonld' => [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $post['title'],
            'description' => $desc,
            'thumbnailUrl' => [$thumb],
            'uploadDate' => date('c', strtotime($published)),
            'embedUrl' => yt_embed_url($post['youtube_id']),
            'contentUrl' => 'https://www.youtube.com/watch?v=' . $post['youtube_id'],
            'inLanguage' => 'tr',
        ],
    ];
}
