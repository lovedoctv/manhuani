<?php
defined('MANHUANI') || exit;

/**
 * Writes the public JSON files the website reads:
 *   data/live/latest.json            newest updates for the Latest Updates section
 *   data/live/series/<slug>.json     extra chapters for series pages on your site
 * Metadata and links only. No images or chapter content.
 */
function publish_updates(): int
{
    $base = site_dir() . '/data/live';
    $onSite = site_series_index();
    $limit = (int) config('published_updates');

    $rows = db_all("SELECT u.*, s.name AS source_name FROM updates u JOIN sources s ON s.id = u.source_id
        WHERE u.hidden = 0 ORDER BY COALESCE(u.release_date, u.created_at) DESC, u.id DESC LIMIT $limit");

    $updates = array_map(fn ($r) => [
        'series' => $r['series_title'],
        'slug' => $r['series_slug'],
        'onSite' => isset($onSite[$r['series_slug']]),
        'cover' => $onSite[$r['series_slug']]['cover'] ?? null,
        'chapter' => (float) $r['chapter_number'],
        'title' => $r['chapter_label'],
        'date' => gmdate('c', ts($r['release_date'] ?? $r['created_at'])),
        'sourceUrl' => $r['source_url'],
        'source' => $r['source_name'],
    ], $rows);

    write_atomic("$base/latest.json", json_encode(['generated' => gmdate('c'), 'updates' => $updates], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Per-series chapter lists, only for series that have a page on your site.
    $written = [];
    foreach (array_keys($onSite) as $slug) {
        $chs = db_all('SELECT chapter_number, chapter_label, release_date, created_at, source_url FROM updates WHERE series_slug = ? AND hidden = 0 ORDER BY chapter_number DESC LIMIT 200', [$slug]);
        if (!$chs) {
            continue;
        }
        $chapters = array_map(fn ($c) => [
            'number' => (float) $c['chapter_number'],
            'title' => $c['chapter_label'],
            'date' => gmdate('c', ts($c['release_date'] ?? $c['created_at'])),
            'sourceUrl' => $c['source_url'],
        ], $chs);
        write_atomic("$base/series/$slug.json", json_encode(['chapters' => $chapters], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $written[$slug] = true;
    }
    foreach (glob("$base/series/*.json") ?: [] as $file) {
        if (!isset($written[basename($file, '.json')])) {
            unlink($file);
        }
    }
    meta_set('last_publish_at', now());
    return count($updates);
}

/** Series that already have pages on the site, read from the site's own search index. */
function site_series_index(): array
{
    $file = site_dir() . '/data/search-index.json';
    $list = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $out = [];
    foreach (is_array($list) ? $list : [] as $s) {
        if (isset($s['slug'])) {
            $out[$s['slug']] = ['cover' => $s['cover'] ?? null];
        }
    }
    return $out;
}
