<?php
defined('MANHUANI') || exit;

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (config('db_driver') === 'mysql') {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', config('mysql_host'), config('mysql_name'));
        $pdo = new PDO($dsn, config('mysql_user'), config('mysql_pass'), $opts);
        $pdo->exec("SET time_zone = '+00:00'");
    } else {
        $pdo = new PDO('sqlite:' . config('sqlite_path'), null, null, $opts);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function is_mysql(): bool
{
    return config('db_driver') === 'mysql';
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

/** INSERT that silently skips rows that would break a UNIQUE rule (used to prevent duplicates). */
function db_insert_ignore(string $table, array $row): bool
{
    $cols = array_keys($row);
    $verb = is_mysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $sql = sprintf('%s INTO %s (%s) VALUES (%s)', $verb, $table, implode(',', $cols), implode(',', array_fill(0, count($cols), '?')));
    return db_run($sql, array_values($row)) > 0;
}

function meta_get(string $key): ?string
{
    $row = db_one('SELECT v FROM meta WHERE k = ?', [$key]);
    return $row['v'] ?? null;
}

function meta_set(string $key, string $value): void
{
    if (is_mysql()) {
        db_run('INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
    } else {
        db_run('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$key, $value]);
    }
}

/** Creates the tables if they don't exist. Safe to run again after updates. */
function db_migrate(): void
{
    $mysql = is_mysql();
    $id = $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $mysql ? 'TEXT' : 'TEXT';
    $str = fn (int $n) => $mysql ? "VARCHAR($n)" : 'TEXT';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $stmts = [
        "CREATE TABLE IF NOT EXISTS sources (
            id $id,
            name {$str(120)} NOT NULL,
            url {$str(500)} NOT NULL,
            feed_url {$str(500)} NULL,
            method {$str(20)} NULL,
            enabled INTEGER NOT NULL DEFAULT 0,
            permission_confirmed INTEGER NOT NULL DEFAULT 0,
            robots_status {$str(20)} NOT NULL DEFAULT 'unknown',
            crawl_delay INTEGER NOT NULL DEFAULT 0,
            interval_minutes INTEGER NOT NULL DEFAULT 60,
            check_summary $text NULL,
            notes $text NULL,
            etag {$str(255)} NULL,
            last_modified {$str(100)} NULL,
            last_checked_at {$str(19)} NULL,
            last_success_at {$str(19)} NULL,
            last_error $text NULL,
            fail_count INTEGER NOT NULL DEFAULT 0,
            next_check_at {$str(19)} NULL,
            created_at {$str(19)} NOT NULL,
            updated_at {$str(19)} NOT NULL
        )$engine",
        "CREATE TABLE IF NOT EXISTS updates (
            id $id,
            source_id INTEGER NOT NULL,
            series_title {$str(255)} NOT NULL,
            series_slug {$str(255)} NOT NULL,
            chapter_number DECIMAL(10,2) NULL,
            chapter_label {$str(255)} NULL,
            release_date {$str(19)} NULL,
            source_url {$str(500)} NOT NULL,
            credit {$str(255)} NULL,
            title_id INTEGER NULL,
            connector {$str(20)} NULL,
            readable INTEGER NOT NULL DEFAULT 1,
            dedupe_key CHAR(40) NOT NULL,
            hidden INTEGER NOT NULL DEFAULT 0,
            created_at {$str(19)} NOT NULL,
            UNIQUE (dedupe_key)
        )$engine",
        "CREATE TABLE IF NOT EXISTS logs (
            id $id,
            level {$str(10)} NOT NULL,
            source_id INTEGER NULL,
            message $text NOT NULL,
            created_at {$str(19)} NOT NULL
        )$engine",
        "CREATE TABLE IF NOT EXISTS robots_cache (
            host {$str(255)} NOT NULL PRIMARY KEY,
            status INTEGER NOT NULL,
            body $text NULL,
            fetched_at {$str(19)} NOT NULL
        )$engine",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id $id,
            ip {$str(64)} NOT NULL,
            attempted_at {$str(19)} NOT NULL
        )$engine",
        // Catalogue of titles found by the connectors (lib/titles.php).
        "CREATE TABLE IF NOT EXISTS titles (
            id $id,
            slug {$str(191)} NOT NULL,
            title {$str(255)} NOT NULL,
            alt_titles $text NULL,
            type {$str(10)} NULL,
            status {$str(20)} NULL,
            year INTEGER NULL,
            genres $text NULL,
            rating DECIMAL(3,1) NULL,
            links $text NULL,
            latest_chapter DECIMAL(10,2) NULL,
            latest_chapter_at {$str(19)} NULL,
            hidden INTEGER NOT NULL DEFAULT 0,
            created_at {$str(19)} NOT NULL,
            updated_at {$str(19)} NOT NULL,
            UNIQUE (slug)
        )$engine",
        // One row per title per connector: the id it has there, its page, and what we learned.
        "CREATE TABLE IF NOT EXISTS title_sources (
            id $id,
            title_id INTEGER NOT NULL,
            connector {$str(20)} NOT NULL,
            external_id {$str(64)} NOT NULL,
            url {$str(500)} NULL,
            data $text NULL,
            fetched_at {$str(19)} NULL,
            UNIQUE (connector, external_id)
        )$engine",
        // Normalised names (and cross-site ids) pointing at a title, so the same series
        // found by several sources is stored once.
        "CREATE TABLE IF NOT EXISTS title_keys (
            k {$str(191)} NOT NULL PRIMARY KEY,
            title_id INTEGER NOT NULL
        )$engine",
        // Built-in API connectors and their health.
        "CREATE TABLE IF NOT EXISTS connectors (
            name {$str(20)} NOT NULL PRIMARY KEY,
            enabled INTEGER NOT NULL DEFAULT 0,
            terms_accepted INTEGER NOT NULL DEFAULT 0,
            state $text NULL,
            last_run_at {$str(19)} NULL,
            last_success_at {$str(19)} NULL,
            last_error $text NULL,
            last_stats $text NULL,
            fail_count INTEGER NOT NULL DEFAULT 0,
            next_run_at {$str(19)} NULL
        )$engine",
        // Short-lived copies of API answers, so lookups aren't repeated.
        "CREATE TABLE IF NOT EXISTS api_cache (
            k CHAR(40) NOT NULL PRIMARY KEY,
            body $text NOT NULL,
            expires_at {$str(19)} NOT NULL
        )$engine",
        // Your own chapters and their page images (lib/library.php).
        "CREATE TABLE IF NOT EXISTS chapters (
            id $id,
            title_id INTEGER NOT NULL,
            number DECIMAL(10,2) NOT NULL,
            label {$str(255)} NULL,
            status {$str(10)} NOT NULL DEFAULT 'draft',
            published_at {$str(19)} NULL,
            created_at {$str(19)} NOT NULL,
            updated_at {$str(19)} NOT NULL,
            UNIQUE (title_id, number)
        )$engine",
        "CREATE TABLE IF NOT EXISTS chapter_pages (
            id $id,
            chapter_id INTEGER NOT NULL,
            position INTEGER NOT NULL,
            file {$str(64)} NOT NULL,
            width INTEGER NULL,
            height INTEGER NULL,
            bytes INTEGER NULL,
            created_at {$str(19)} NOT NULL
        )$engine",
        "CREATE TABLE IF NOT EXISTS meta (
            k {$str(64)} NOT NULL PRIMARY KEY,
            v $text NULL
        )$engine",
    ];
    foreach ($stmts as $sql) {
        db()->exec($sql);
    }
    // Schema 2: who to credit for a chapter (e.g. the scanlation group on MangaDex).
    if (!db_has_column('updates', 'credit')) {
        db()->exec('ALTER TABLE updates ADD COLUMN credit ' . ($mysql ? 'VARCHAR(255)' : 'TEXT') . ' NULL');
    }
    // Schema 3: updates from the built-in connectors and the titles catalogue.
    foreach (['title_id' => 'INTEGER NULL', 'connector' => ($mysql ? 'VARCHAR(20)' : 'TEXT') . ' NULL', 'readable' => 'INTEGER NOT NULL DEFAULT 1'] as $col => $def) {
        if (!db_has_column('updates', $col)) {
            db()->exec("ALTER TABLE updates ADD COLUMN $col $def");
        }
    }
    // Schema 4: chapter pages you have permission to host (lib/pages.php).
    foreach (['hosting_allowed' => 'INTEGER NOT NULL DEFAULT 0', 'hosting_note' => ($mysql ? 'VARCHAR(500)' : 'TEXT') . ' NULL'] as $col => $def) {
        if (!db_has_column('sources', $col)) {
            db()->exec("ALTER TABLE sources ADD COLUMN $col $def");
        }
    }
    if (!db_has_column('updates', 'pages')) {
        db()->exec('ALTER TABLE updates ADD COLUMN pages TEXT NULL');
    }
    // Schema 5: titles you add yourself (origin = 'own') with a cover and description.
    foreach (['origin' => ($mysql ? 'VARCHAR(10)' : 'TEXT') . ' NULL', 'description' => 'TEXT NULL', 'author' => ($mysql ? 'VARCHAR(255)' : 'TEXT') . ' NULL',
        'artist' => ($mysql ? 'VARCHAR(255)' : 'TEXT') . ' NULL', 'cover' => ($mysql ? 'VARCHAR(64)' : 'TEXT') . ' NULL'] as $col => $def) {
        if (!db_has_column('titles', $col)) {
            db()->exec("ALTER TABLE titles ADD COLUMN $col $def");
        }
    }
    $indexes = [
        'idx_updates_created' => 'updates (created_at)',
        'idx_updates_slug' => 'updates (series_slug)',
        'idx_logs_created' => 'logs (created_at)',
        'idx_updates_title' => 'updates (title_id)',
        'idx_title_sources_title' => 'title_sources (title_id)',
        'idx_title_keys_title' => 'title_keys (title_id)',
        'idx_titles_created' => 'titles (created_at)',
        'idx_login_ip' => 'login_attempts (ip, attempted_at)',
        'idx_chapters_title' => 'chapters (title_id)',
        'idx_chapter_pages_chapter' => 'chapter_pages (chapter_id, position)',
    ];
    foreach ($indexes as $name => $on) {
        if ($mysql) {
            $exists = db_one('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = ?', [$name]);
            if (!$exists) {
                db()->exec("CREATE INDEX $name ON $on");
            }
        } else {
            db()->exec("CREATE INDEX IF NOT EXISTS $name ON $on");
        }
    }
    meta_set('schema_version', (string) DB_SCHEMA_VERSION);
}

const DB_SCHEMA_VERSION = 5;

/** Runs db_migrate() when the database is older than this code, so updates need no manual step. */
function db_ensure_schema(): void
{
    if ((int) (meta_get('schema_version') ?? 0) < DB_SCHEMA_VERSION) {
        db_migrate();
    }
}

function db_has_column(string $table, string $column): bool
{
    if (is_mysql()) {
        return (bool) db_one('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]);
    }
    foreach (db_all("PRAGMA table_info($table)") as $col) {
        if ($col['name'] === $column) {
            return true;
        }
    }
    return false;
}
