<?php
declare(strict_types=1);

function xml_e(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8');
}

function output_rss(): void
{
    [$posts] = list_posts(1, 20);
    header('Content-Type: application/rss+xml; charset=utf-8');
    $site = setting('site_name', 'Video Blog');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
    echo '<title>' . xml_e($site) . '</title>';
    echo '<link>' . xml_e(url()) . '</link>';
    echo '<description>' . xml_e(setting('tagline', $site)) . '</description>';
    echo '<language>tr</language>';
    echo '<atom:link href="' . xml_e(url('feed.xml')) . '" rel="self" type="application/rss+xml"/>';
    foreach ($posts as $p) {
        $link = url('post/' . $p['slug']);
        $ts = strtotime($p['published_at'] ?: $p['created_at']);
        $html = '<p><a href="' . e($link) . '"><img src="' . e(yt_thumb($p['youtube_id'])) . '" alt=""></a></p>'
              . markdown_html($p['body']);
        echo '<item>';
        echo '<title>' . xml_e($p['title']) . '</title>';
        echo '<link>' . xml_e($link) . '</link>';
        echo '<guid isPermaLink="true">' . xml_e($link) . '</guid>';
        echo '<pubDate>' . date(DATE_RSS, $ts) . '</pubDate>';
        echo '<description>' . xml_e(post_excerpt($p)) . '</description>';
        echo '<content:encoded>' . xml_e($html) . '</content:encoded>';
        echo '</item>';
    }
    echo '</channel></rss>';
}

function output_sitemap(int $page, bool $paged): void
{
    // Leave room under sitemap size limits, including for long URLs.
    $perPage = 10000;
    $categoryFilter = 'EXISTS (
        SELECT 1 FROM post_category pc JOIN posts p ON p.id = pc.post_id
        WHERE pc.category_id = c.id AND ' . LIVE . ')';
    $pdo = db();
    $total = (int)$pdo->query(
        'SELECT 1 + (SELECT COUNT(*) FROM posts p WHERE ' . LIVE . ')
                  + (SELECT COUNT(*) FROM categories c WHERE ' . $categoryFilter . ")
                  + (SELECT COUNT(*) FROM pages WHERE status = 'published')"
    )->fetchColumn();
    $pages = (int)ceil($total / $perPage);

    if ($page === 0 || $page > $pages) {
        not_found();
    }

    $rows = null;
    if ($paged || $pages === 1) {
        $offset = ($page - 1) * $perPage;
        $rows = $pdo->query(
            "SELECT kind, slug, modified FROM (
                 SELECT 0 AS kind, 0 AS id, '' AS slug, NULL AS modified
                 UNION ALL
                 SELECT 1, p.id, p.slug, COALESCE(p.updated_at, p.published_at) FROM posts p WHERE " . LIVE . "
                 UNION ALL
                 SELECT 2, c.id, c.slug, NULL FROM categories c WHERE $categoryFilter
                 UNION ALL
                 SELECT 3, id, slug, updated_at FROM pages WHERE status = 'published'
             ) urls ORDER BY kind, id LIMIT $perPage OFFSET $offset"
        );
    }

    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $namespace = ' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
    if ($rows === null) {
        echo '<sitemapindex' . $namespace . '>';
        for ($n = 1; $n <= $pages; $n++) {
            echo '<sitemap><loc>' . xml_e(url('sitemap.xml?page=' . $n)) . '</loc></sitemap>';
        }
        echo '</sitemapindex>';
        return;
    }

    echo '<urlset' . $namespace . '>';
    $prefixes = ['', 'post/', 'category/', ''];
    foreach ($rows as $row) {
        echo '<url><loc>' . xml_e(url($prefixes[(int)$row['kind']] . $row['slug'])) . '</loc>';
        if ($row['modified']) {
            echo '<lastmod>' . date('c', strtotime($row['modified'])) . '</lastmod>';
        }
        echo '</url>';
    }
    echo '</urlset>';
}
