<?php
declare(strict_types=1);

const LIVE = "p.status = 'published' AND (p.published_at IS NULL OR p.published_at <= NOW())";

/** PHP twin of LIVE: published and not scheduled for the future. */
function post_is_live(array $p): bool
{
    return $p['status'] === 'published'
        && (empty($p['published_at']) || strtotime((string)$p['published_at']) <= time());
}

/** Attach a 'categories' array (id, name, slug) to each post row. */
function attach_categories(array $posts): array
{
    if (!$posts) {
        return $posts;
    }
    $ids = array_column($posts, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT pc.post_id, c.id, c.name, c.slug FROM post_category pc
         JOIN categories c ON c.id = pc.category_id WHERE pc.post_id IN ($in)"
    );
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt as $r) {
        $map[$r['post_id']][] = ['id' => $r['id'], 'name' => $r['name'], 'slug' => $r['slug']];
    }
    foreach ($posts as &$p) {
        $cats = $map[$p['id']] ?? [];
        usort($cats, fn($a, $b) => tr_compare($a['name'], $b['name']));
        $p['categories'] = $cats;
    }
    return $posts;
}

/**
 * Paginated list of live posts. Options: category_id, search (raw user query).
 * Returns [rows, total].
 */
function list_posts(int $page, int $perPage, array $opt = []): array
{
    $page = min(max(1, $page), 1000000);           // keeps OFFSET an integer (an overflow became a float and broke the SQL)
    $offset = ($page - 1) * $perPage;
    $where = [LIVE];
    $params = [];
    $join = '';
    $order = 'COALESCE(p.published_at, p.created_at) DESC, p.id DESC';
    $scoreSel = '';

    if (!empty($opt['category_id'])) {
        $join .= ' JOIN post_category pcf ON pcf.post_id = p.id AND pcf.category_id = ?';
        array_unshift($params, (int)$opt['category_id']);
    }

    if (isset($opt['search'])) {
        [$cond, $sp, $useFt] = search_condition((string)$opt['search'], $opt['_force_like'] ?? false);
        $where[] = $cond;
        $params = array_merge($params, $sp);
    }

    $w = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM posts p $join WHERE $w");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    // Fulltext hits that match nothing (stopwords etc.) fall back to LIKE once.
    if ($total === 0 && isset($opt['search']) && empty($opt['_force_like']) && $useFt) {
        return list_posts($page, $perPage, $opt + ['_force_like' => true]);
    }

    $stmt = db()->prepare(
        "SELECT p.* FROM posts p $join WHERE $w ORDER BY $order LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    return [attach_categories($stmt->fetchAll()), $total];
}

/** Build the WHERE fragment for a search query. Returns [sql, params, usesFulltext]. */
function search_condition(string $q, bool $forceLike = false): array
{
    $tokens = array_values(array_filter(
        preg_split('/[^a-z0-9]+/', fold($q)),
        fn($t) => $t !== ''
    ));
    if (!$tokens) {
        return ['0=1', [], false];
    }
    $tokens = array_slice($tokens, 0, 8);
    $allLong = !array_filter($tokens, fn($t) => strlen($t) < 3);
    if ($allLong && !$forceLike) {
        // prefix match so "araba" also finds "arabalar"
        $bool = implode(' ', array_map(fn($t) => '+' . $t . '*', $tokens));
        return ['MATCH(p.search_text) AGAINST (? IN BOOLEAN MODE)', [$bool], true];
    }
    $conds = [];
    $params = [];
    foreach ($tokens as $t) {
        $conds[] = 'p.search_text LIKE ?';
        $params[] = '%' . addcslashes($t, '%_\\') . '%';
    }
    return ['(' . implode(' AND ', $conds) . ')', $params, false];
}

function get_post_by_slug(string $slug, bool $preview = false): ?array
{
    $sql = 'SELECT p.* FROM posts p WHERE p.slug = ?' . ($preview ? '' : ' AND ' . LIVE);
    $stmt = db()->prepare($sql);
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ? attach_categories([$row])[0] : null;
}

function get_post(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? attach_categories([$row])[0] : null;
}

/** Latest live posts other than $exceptId, for the "more videos" block. */
function related_posts(int $exceptId, int $limit = 3): array
{
    $stmt = db()->prepare(
        'SELECT p.* FROM posts p WHERE ' . LIVE . " AND p.id <> ? ORDER BY COALESCE(p.published_at, p.created_at) DESC LIMIT $limit"
    );
    $stmt->execute([$exceptId]);
    return attach_categories($stmt->fetchAll());
}

/** All categories with the number of live posts, sorted in Turkish alphabetical order. */
function get_categories(bool $onlyUsed = false): array
{
    $rows = db()->query(
        "SELECT c.id, c.name, c.slug, COUNT(p.id) AS post_count
         FROM categories c
         LEFT JOIN post_category pc ON pc.category_id = c.id
         LEFT JOIN posts p ON p.id = pc.post_id AND " . LIVE . "
         GROUP BY c.id, c.name, c.slug"
    )->fetchAll();
    if ($onlyUsed) {
        $rows = array_values(array_filter($rows, fn($r) => (int)$r['post_count'] > 0));
    }
    usort($rows, fn($a, $b) => tr_compare($a['name'], $b['name']));
    return $rows;
}

function get_category_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM categories WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function get_page_by_slug(string $slug, bool $preview = false): ?array
{
    $sql = 'SELECT * FROM pages WHERE slug = ?' . ($preview ? '' : " AND status = 'published'");
    $stmt = db()->prepare($sql);
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function get_nav_pages(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = db()->query(
                "SELECT title, slug FROM pages WHERE status = 'published' AND show_in_nav = 1 ORDER BY sort_order, title"
            )->fetchAll();
        } catch (Throwable $ex) {
            $cache = [];
        }
    }
    return $cache;
}

/* ---------- Saving (used by the admin panel and the autoposter) ---------- */

/**
 * Insert or update a post. $data keys: title, slug, youtube_id, body, excerpt,
 * status, published_at, meta_description, source, category_ids.
 * Returns the post id.
 */
function save_post(array $data, ?int $id = null): int
{
    $title = trim($data['title']);
    $slug = unique_slug('posts', slugify($data['slug'] ?? '') ?: slugify($title), $id, 'video');
    $body = (string)($data['body'] ?? '');
    $status = ($data['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $publishedAt = $data['published_at'] ?? null;
    if ($status === 'published' && !$publishedAt) {
        $publishedAt = date('Y-m-d H:i:s');
    }
    $fields = [
        $title,
        $slug,
        $data['youtube_id'],
        $body,
        ($data['excerpt'] ?? '') !== '' ? $data['excerpt'] : null,
        $status,
        $publishedAt,
        ($data['meta_description'] ?? '') !== '' ? $data['meta_description'] : null,
        ($data['source'] ?? 'manual') === 'auto' ? 'auto' : 'manual',
        search_text($title, $body),
    ];
    $pdo = db();
    if ($id) {
        $stmt = $pdo->prepare(
            'UPDATE posts SET title=?, slug=?, youtube_id=?, body=?, excerpt=?, status=?, published_at=?,
             meta_description=?, source=?, search_text=? WHERE id=?'
        );
        $stmt->execute([...$fields, $id]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO posts (title, slug, youtube_id, body, excerpt, status, published_at,
             meta_description, source, search_text) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute($fields);
        $id = (int)$pdo->lastInsertId();
    }
    if (isset($data['category_ids'])) {
        sync_post_categories($id, array_map('intval', $data['category_ids']));
    }
    return $id;
}

/** Table of every video the autoposter has already handled, so deleted posts don't come back. */
function autopost_ensure_schema(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS autopost_seen (
            youtube_id VARCHAR(20) NOT NULL PRIMARY KEY,
            seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Remember a video as handled, so the autoposter never re-creates it. Called when a post is deleted or
 * pointed at a different video (otherwise a video removed before the next cron run would come back).
 */
function mark_video_seen(string $youtubeId): void
{
    autopost_ensure_schema();
    db()->prepare('INSERT IGNORE INTO autopost_seen (youtube_id) VALUES (?)')->execute([$youtubeId]);
}

function sync_post_categories(int $postId, array $categoryIds): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM post_category WHERE post_id = ?')->execute([$postId]);
    $ins = $pdo->prepare('INSERT IGNORE INTO post_category (post_id, category_id) VALUES (?, ?)');
    foreach (array_unique($categoryIds) as $cid) {
        if ($cid > 0) {
            $ins->execute([$postId, $cid]);
        }
    }
}

function save_category(string $name, ?int $id = null): int
{
    $name = trim($name);
    $slug = unique_slug('categories', slugify($name), $id, 'kategori');
    $pdo = db();
    if ($id) {
        $pdo->prepare('UPDATE categories SET name=?, slug=? WHERE id=?')->execute([$name, $slug, $id]);
        return $id;
    }
    $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)')->execute([$name, $slug]);
    return (int)$pdo->lastInsertId();
}

function save_page(array $data, ?int $id = null): int
{
    $title = trim($data['title']);
    $slug = unique_slug('pages', slugify($data['slug'] ?? '') ?: slugify($title), $id, 'sayfa');
    $vals = [
        $title,
        $slug,
        (string)($data['body'] ?? ''),
        ($data['status'] ?? 'published') === 'draft' ? 'draft' : 'published',
        !empty($data['show_in_nav']) ? 1 : 0,
        (int)($data['sort_order'] ?? 0),
        ($data['meta_description'] ?? '') !== '' ? $data['meta_description'] : null,
    ];
    $pdo = db();
    if ($id) {
        $pdo->prepare(
            'UPDATE pages SET title=?, slug=?, body=?, status=?, show_in_nav=?, sort_order=?, meta_description=? WHERE id=?'
        )->execute([...$vals, $id]);
        return $id;
    }
    $pdo->prepare(
        'INSERT INTO pages (title, slug, body, status, show_in_nav, sort_order, meta_description) VALUES (?,?,?,?,?,?,?)'
    )->execute($vals);
    return (int)$pdo->lastInsertId();
}
