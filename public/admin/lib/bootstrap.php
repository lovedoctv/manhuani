<?php
// Shared setup for the dashboard (web) and the crawler (command line).
declare(strict_types=1);

if (!defined('MANHUANI')) {
    http_response_code(404);
    exit;
}

const MANHUANI_VERSION = '1.2.0';

require __DIR__ . '/util.php';
require __DIR__ . '/db.php';
require __DIR__ . '/log.php';
require __DIR__ . '/http.php';
require __DIR__ . '/robots.php';
require __DIR__ . '/feeds.php';
require __DIR__ . '/extract.php';
require __DIR__ . '/apis.php';
require __DIR__ . '/titles.php';
require __DIR__ . '/connectors.php';
require __DIR__ . '/sources.php';
require __DIR__ . '/crawler.php';
require __DIR__ . '/publish.php';

/** @return list<string> what this server is missing, empty when everything is there */
function missing_requirements(): array
{
    $missing = [];
    if (PHP_VERSION_ID < 80000) {
        $missing[] = 'PHP 8.0 or newer (this is ' . PHP_VERSION . ')';
    }
    foreach (['pdo', 'curl', 'simplexml', 'json', 'mbstring'] as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = "the PHP extension \"$ext\"";
        }
    }
    if (!extension_loaded('pdo_sqlite') && !extension_loaded('pdo_mysql')) {
        $missing[] = 'the PHP extension "pdo_sqlite" or "pdo_mysql"';
    }
    return $missing;
}

/**
 * Finds the private folder that holds config.php and the database.
 * It lives OUTSIDE the website folder (by default ~/manhuani_private),
 * so nothing in it can ever be downloaded from the web.
 */
function private_dir(): ?string
{
    $env = getenv('MANHUANI_PRIVATE_DIR');
    if ($env !== false && $env !== '') {
        return rtrim($env, '/');
    }
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        if (is_file($dir . '/manhuani_private/config.php')) {
            return $dir . '/manhuani_private';
        }
        if ($dir === '/' || $dir === '.') {
            break;
        }
    }
    return null;
}

/** Folder of the public website (the one containing index.html and data/). */
function site_dir(): string
{
    return dirname(__DIR__, 2);
}

function config(?string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $dir = private_dir();
        if ($dir === null) {
            throw new RuntimeException('Not installed yet: run "php admin/cli.php setup" in cPanel Terminal.');
        }
        $loaded = require $dir . '/config.php';
        $config = array_replace(default_config($dir), is_array($loaded) ? $loaded : []);
    }
    return $key === null ? $config : ($config[$key] ?? $default);
}

function default_config(string $dir): array
{
    return [
        'private_dir' => $dir,
        'db_driver' => 'sqlite',                    // 'sqlite' or 'mysql'
        'sqlite_path' => $dir . '/crawler.sqlite',
        'mysql_host' => 'localhost',
        'mysql_name' => '',
        'mysql_user' => '',
        'mysql_pass' => '',
        'admin_password_hash' => '',
        'user_agent' => 'ManhuaniBot/1.0 (+https://aduna.fit/manhuani/about/)',
        'timeout_seconds' => 12,
        'max_bytes' => 2 * 1024 * 1024,              // largest response we will read
        'min_delay_seconds' => 3,                    // pause between requests to the same site
        'max_delay_seconds' => 30,
        'max_requests_per_run' => 40,
        'max_sources_per_run' => 10,
        'default_interval_minutes' => 60,
        'min_interval_minutes' => 30,
        'max_items_per_feed' => 100,
        'published_updates' => 60,                   // how many updates the website shows
        'log_retention_days' => 30,
        'disable_after_failures' => 10,
        'mangadex_api' => 'https://api.mangadex.org',
        'mangadex_language' => 'en',                 // chapter language to follow on MangaDex
        'anilist_api' => 'https://graphql.anilist.co',
        // Built-in connectors (dashboard > Connectors)
        'discover_types' => ['manhua', 'manhwa'],      // which titles connectors add to the site
        'mangadex_original_languages' => ['ko', 'zh', 'zh-hk'],
        'mangadex_discover_pages' => 2,              // pages of 100 newest chapters read per run
        'mangaupdates_api' => 'https://api.mangaupdates.com/v1',
        'mangaupdates_lookups_per_run' => 9,         // new series looked up per run
        'bangumi_api' => 'https://api.bgm.tv',
        'ann_api' => 'https://cdn.animenewsnetwork.com/encyclopedia',
        'enrich_per_run' => 4,                       // titles matched per run on AniList, Bangumi and ANN
        'connector_max_requests' => 12,              // request limit for each connector per run
        'connector_intervals' => [],                 // e.g. ['mangadex' => 30] (minutes)
        'titles_published' => 300,                   // how many discovered titles the website lists
        'allow_private_hosts' => false,              // only for local testing
    ];
}

date_default_timezone_set('UTC');
