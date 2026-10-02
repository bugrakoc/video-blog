<?php
declare(strict_types=1);

/* Shared helpers for the admin panel. Lives in app/ so it is not web-accessible. */

const LOGIN_MAX_ATTEMPTS = 5;      // failed attempts allowed ...
const LOGIN_WINDOW_MIN = 15;       // ... within this many minutes, per IP
const ADMIN_IDLE_SECONDS = 7200;   // auto-logout after 2 hours of inactivity
const ADMIN_PER_PAGE = 25;

/** Common setup for every admin script: headers, session, idle timeout, auth. */
function admin_boot(bool $requireLogin = true): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    start_session();
    if (!empty($_SESSION['admin_id'])) {
        $now = time();
        $expired = $now - (int)($_SESSION['last_seen'] ?? $now) > ADMIN_IDLE_SECONDS;
        $revoked = !$expired && !admin_session_matches_password();
        if ($expired || $revoked) {
            session_unset();
            session_destroy();
            start_session();
            session_regenerate_id(true);
            flash('error', $expired
                ? 'Oturum süresi doldu. Lütfen tekrar giriş yapın.'
                : 'Şifre değiştirildiği için oturum kapatıldı. Lütfen tekrar giriş yapın.');
        } else {
            $_SESSION['last_seen'] = $now;
        }
    }
    if ($requireLogin) {
        require_admin();
    }
}

/* ---------- Session ↔ password binding ---------- */

/** Value stored in the session at login. Changing the password changes it, which ends every other session. */
function admin_password_fingerprint(string $passwordHash): string
{
    return hash('sha256', 'vb-session|' . $passwordHash);
}

/** Mark the current session as logged in as $adminId (whose current hash is $passwordHash). */
function admin_session_login(int $adminId, string $passwordHash): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf']);                     // fresh CSRF token for the logged-in session
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_pw'] = admin_password_fingerprint($passwordHash);
    $_SESSION['last_seen'] = time();
}

/** False when the account is gone or its password changed after this session logged in. */
function admin_session_matches_password(): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $hash = $stmt->fetchColumn();
    return is_string($hash)
        && hash_equals(admin_password_fingerprint($hash), (string)($_SESSION['admin_pw'] ?? ''));
}

/* ---------- Flash messages ---------- */

function flash(string $type, string $msg): void
{
    start_session();
    $_SESSION['flash'][] = [$type === 'error' ? 'error' : 'ok', $msg];
}

function take_flashes(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Login throttling ---------- */

function login_recent_failures(string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)'
    );
    $stmt->execute([$ip]);
    return (int)$stmt->fetchColumn();
}

/**
 * Reserve one password attempt for $ip. Returns false when the IP is over the limit.
 * The attempt is recorded BEFORE the password is checked and counted afterwards, so a burst of parallel
 * requests can't all pass the check before any failure is stored. A successful login clears the record.
 */
function login_attempt_allowed(string $ip): bool
{
    db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    if (login_recent_failures($ip) >= LOGIN_MAX_ATTEMPTS) {
        return false;
    }
    login_record_failure($ip);
    return login_recent_failures($ip) <= LOGIN_MAX_ATTEMPTS;
}

function login_record_failure(string $ip): void
{
    db()->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
}

function login_clear_failures(string $ip): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
}

/**
 * A throwaway hash made with the current default algorithm and cost, checked when the username doesn't exist,
 * so a wrong username takes as long as a wrong password (a fixed cost-12 hash made unknown names ~4x slower).
 */
function login_dummy_hash(): string
{
    $h = setting('login_dummy_hash');
    if ($h === '' || password_needs_rehash($h, PASSWORD_DEFAULT)) {
        $h = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        set_setting('login_dummy_hash', $h);
    }
    return $h;
}

/* ---------- Layout ---------- */

function admin_header(string $title, string $active = ''): void
{
    $site = setting('site_name', 'Video Blog');
    $nav = [
        'dashboard'  => ['index.php', 'Panel'],
        'posts'      => ['posts.php', 'Videolar'],
        'pages'      => ['pages.php', 'Sayfalar'],
        'categories' => ['categories.php', 'Kategoriler'],
        'settings'   => ['settings.php', 'Ayarlar'],
    ];
    ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?> · Yönetim · <?= e($site) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>">
</head>
<body>
<?php if (is_admin()): ?>
<header class="bar">
  <div class="bar-in">
    <a class="brand" href="<?= e(url('admin/index.php')) ?>">⚙ <?= e($site) ?></a>
    <nav>
      <?php foreach ($nav as $key => [$file, $label]): ?>
        <a href="<?= e(url('admin/' . $file)) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <span class="grow"></span>
    <a href="<?= e(url()) ?>" target="_blank" rel="noopener">Siteyi gör ↗</a>
    <form method="post" action="<?= e(url('admin/logout.php')) ?>" class="inline">
      <?= csrf_field() ?>
      <button class="link">Çıkış</button>
    </form>
  </div>
</header>
<?php endif; ?>
<main class="shell">
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash <?= e($type) ?>" role="status"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php
}

function admin_footer(): void
{
    ?>
</main>
<script src="<?= e(url('assets/js/admin.js')) ?>"></script>
</body>
</html>
<?php
}

/* ---------- Posts: admin listing and display helpers ---------- */

/** [label, css class] describing a post's public state. */
function post_state(array $p): array
{
    if ($p['status'] === 'draft') {
        return ['Taslak', 'draft'];
    }
    if (!empty($p['published_at']) && strtotime($p['published_at']) > time()) {
        return ['Zamanlanmış', 'scheduled'];
    }
    return ['Yayında', 'live'];
}

/** Paginated admin list (includes drafts and scheduled). Returns [rows, total]. */
function admin_list_posts(int $page, string $status, string $q): array
{
    $where = ['1=1'];
    $params = [];
    if ($status === 'published') {
        $where[] = "status = 'published' AND (published_at IS NULL OR published_at <= NOW())";
    } elseif ($status === 'scheduled') {
        $where[] = "status = 'published' AND published_at > NOW()";
    } elseif ($status === 'draft') {
        $where[] = "status = 'draft'";
    }
    foreach (array_slice(array_filter(preg_split('/[^a-z0-9]+/', fold($q))), 0, 8) as $t) {
        $where[] = 'search_text LIKE ?';
        $params[] = '%' . addcslashes($t, '%_\\') . '%';
    }
    $w = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM posts WHERE $w");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $per = ADMIN_PER_PAGE;
    $offset = (max(1, $page) - 1) * $per;
    $stmt = db()->prepare(
        "SELECT * FROM posts WHERE $w ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($params);
    return [attach_categories($stmt->fetchAll()), $total];
}

/** Convert a <input type=datetime-local> value to a DB datetime, or null. */
function parse_local_datetime(string $v): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $ts = strtotime($v);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function to_local_datetime(?string $db): string
{
    return $db ? date('Y-m-d\TH:i', strtotime($db)) : '';
}

/** Simple prev/next pager for admin lists. $link receives a page number and returns a URL. */
function admin_pager(int $page, int $total, callable $link): void
{
    $pages = (int)ceil($total / ADMIN_PER_PAGE);
    if ($pages <= 1) {
        return;
    }
    echo '<nav class="pager">';
    if ($page > 1) {
        echo '<a href="' . e($link($page - 1)) . '">← Önceki</a>';
    }
    echo '<span>Sayfa ' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) {
        echo '<a href="' . e($link($page + 1)) . '">Sonraki →</a>';
    }
    echo '</nav>';
}

/** Resolve a comma-separated list of new category names to ids (creating them as needed). */
function resolve_new_categories(string $csv): array
{
    $ids = [];
    foreach (explode(',', $csv) as $name) {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 100) {
            continue;
        }
        $slug = slugify($name);
        if ($slug === '') {
            continue;
        }
        $existing = get_category_by_slug($slug);
        $ids[] = $existing ? (int)$existing['id'] : save_category($name);
    }
    return $ids;
}
