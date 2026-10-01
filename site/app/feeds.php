<?php
declare(strict_types=1);

function xml_e(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
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
        echo '<content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $html) . ']]></content:encoded>';
        echo '</item>';
    }
    echo '</channel></rss>';
}

function output_sitemap(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $urls = [[url(), null]];
    [$posts] = list_posts(1, 5000);
    foreach ($posts as $p) {
        $urls[] = [url('post/' . $p['slug']), $p['updated_at'] ?? $p['published_at']];
    }
    foreach (get_categories(true) as $c) {
        $urls[] = [url('category/' . $c['slug']), null];
    }
    foreach (db()->query("SELECT slug, updated_at FROM pages WHERE status = 'published'") as $pg) {
        $urls[] = [url($pg['slug']), $pg['updated_at']];
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as [$loc, $mod]) {
        echo '<url><loc>' . xml_e($loc) . '</loc>';
        if ($mod) {
            echo '<lastmod>' . date('c', strtotime($mod)) . '</lastmod>';
        }
        echo '</url>';
    }
    echo '</urlset>';
}
