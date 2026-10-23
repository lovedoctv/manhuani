<?php
// Shared setup for the blog admin (admin/index.php) and the page that shows its posts (post.php).
declare(strict_types=1);

if (!defined('ABROADPATH')) {
    http_response_code(404);
    exit;
}

const ADMIN_VERSION = '1.0.0';
const DB_SCHEMA = 2;

date_default_timezone_set('UTC');

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** The website folder (the one with index.html, admin/ and post.php). */
function site_dir(): string
{
    return dirname(__DIR__, 2);
}

/** Facts about the site written at build time: categories, countries, ad codes, offers, built posts. */
function site(?string $key = null)
{
    static $site = null;
    if ($site === null) {
        $json = @file_get_contents(__DIR__ . '/site.json');
        $site = $json ? json_decode($json, true) : null;
        if (!is_array($site)) {
            throw new RuntimeException('admin/inc/site.json is missing. Deploy the site again.');
        }
    }
    return $key === null ? $site : ($site[$key] ?? null);
}

/** The posts in the site's code, in full (title, facts, text...), keyed by their address. */
function code_posts(): array
{
    static $posts = null;
    if ($posts === null) {
        $json = @file_get_contents(__DIR__ . '/code-posts.json');
        $list = $json ? json_decode($json, true) : null;
        $posts = is_array($list) ? array_column($list, null, 'slug') : [];
    }
    return $posts;
}

/** The folder the site is served from, e.g. "/AbroadPath/" (always ends with "/"). */
function base(): string
{
    return rtrim((string) site('base'), '/') . '/';
}

/** "blog/x/" -> "/AbroadPath/blog/x/" */
function url(string $path = ''): string
{
    return base() . ltrim($path, '/');
}

/** "blog/x/" -> "https://aduna.fit/AbroadPath/blog/x/" */
function abs_url(string $path = ''): string
{
    return rtrim((string) site('url'), '/') . url($path);
}

function post_path(string $slug): string
{
    return url("blog/$slug/");
}

function category_name(string $slug): string
{
    foreach (site('categories') as $c) {
        if ($c['slug'] === $slug) {
            return $c['name'];
        }
    }
    return $slug;
}

function country_info(string $slug): ?array
{
    foreach (site('countries') as $c) {
        if ($c['slug'] === $slug) {
            return $c;
        }
    }
    return null;
}

/** Same rule the site uses for addresses: "Study in Canada!" -> "study-in-canada". */
function slugify(string $s): string
{
    $s = strtolower(trim($s));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) {
            $s = strtolower($t);
        }
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return substr(trim($s, '-'), 0, 80);
}

function str_limit(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/** "2026-10-23" or "2026-10-23 10:00:00" -> 23 Oct 2026, the way the rest of the site writes dates. */
function format_date(string $date): string
{
    $t = strtotime(substr($date, 0, 10) . ' UTC');
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
    return gmdate('j', $t) . ' ' . $months[(int) gmdate('n', $t) - 1] . ' ' . gmdate('Y', $t);
}

/** "2026-10-23" -> "2026-10-23T00:00:00.000Z", the format the built pages use. */
function iso_date(string $date): string
{
    return gmdate('Y-m-d\TH:i:s.000\Z', strtotime(substr($date, 0, 10) . ' UTC'));
}

/** Days from today (UTC) until a date; 0 on the day itself. */
function days_left(string $date): int
{
    $today = strtotime(gmdate('Y-m-d') . ' UTC');
    return (int) round((strtotime(substr($date, 0, 10) . ' UTC') - $today) / 86400);
}

function days_left_label(int $left): string
{
    return $left < 0 ? 'Closed' : ($left === 0 ? 'Closes today' : ($left === 1 ? '1 day left' : "$left days left"));
}

/** Writes a file in one step, so visitors never read a half-written one. */
function write_atomic(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create the folder $dir");
    }
    $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contents) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Cannot write $path");
    }
}

// ---------- Private folder: password and database ----------

/**
 * The folder that holds the admin password (config.php) and the posts database (blog.sqlite).
 * It sits next to the domain's folder, outside anything the web can reach: for a site in
 * ~/aduna.fit/AbroadPath/ it is ~/abroadpath_private/.
 */
function private_dir(): string
{
    $env = getenv('ABROADPATH_PRIVATE_DIR');
    if ($env !== false && $env !== '') {
        return rtrim($env, '/');
    }
    $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $site = site_dir();
    if ($docroot === '' || strpos($site . '/', $docroot . '/') !== 0) {
        $docroot = dirname($site);
    }
    return dirname($docroot) . '/abroadpath_private';
}

/** Creates the private folder the first time, locked down in case it is ever inside the web folder. */
function ensure_private_dir(): string
{
    $dir = private_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create the private folder $dir");
    }
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "Require all denied\n");
    }
    return $dir;
}

function config(?string $key = null)
{
    $file = private_dir() . '/config.php';
    $config = is_file($file) ? require $file : [];
    $config = is_array($config) ? $config : [];
    return $key === null ? $config : ($config[$key] ?? null);
}

function save_config(array $values): void
{
    $dir = ensure_private_dir();
    $config = array_replace(config(), $values);
    write_atomic("$dir/config.php", "<?php\n// Blog admin settings. Delete this file to set a new password with a setup code.\nreturn " . var_export($config, true) . ";\n");
}

/** The posts database. $create = false (post.php) never creates anything on a visitor's request. */
function db(bool $create = true): ?PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $file = private_dir() . '/blog.sqlite';
    if (!$create && !is_file($file)) {
        return null;
    }
    if ($create) {
        ensure_private_dir();
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($create) {
        db_schema($pdo);
    }
    return $pdo;
}

function db_schema(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= DB_SCHEMA) {
        return;
    }
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL,
            countries TEXT NOT NULL DEFAULT '[]',
            body TEXT NOT NULL DEFAULT '',
            facts TEXT NOT NULL DEFAULT '[]',
            deadline TEXT,
            official_name TEXT NOT NULL DEFAULT '',
            official_url TEXT NOT NULL DEFAULT '',
            checked TEXT NOT NULL,
            cover TEXT,
            status TEXT NOT NULL DEFAULT 'draft',
            published_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS media (
            id INTEGER PRIMARY KEY,
            file TEXT NOT NULL UNIQUE,
            width INTEGER NOT NULL,
            height INTEGER NOT NULL,
            bytes INTEGER NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, attempted_at TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS login_attempts_ip ON login_attempts (ip, attempted_at);
        SQL);
    // 2: from_code = 1 marks the edited version of a post in the site's code, at that post's address.
    if ($version < 2) {
        $pdo->exec('ALTER TABLE posts ADD COLUMN from_code INTEGER NOT NULL DEFAULT 0');
    }
    $pdo->exec('PRAGMA user_version = ' . DB_SCHEMA);
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_run(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

// ---------- Sessions ----------

const SESSION_IDLE_SECONDS = 7200;

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('abroadpath_admin');
    // The whole site folder, so a draft's preview page (/blog/<slug>/) knows you're signed in.
    session_set_cookie_params(['lifetime' => 0, 'path' => base(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}
