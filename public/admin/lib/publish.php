<?php
defined('MANHUANI') || exit;

/**
 * Writes the public JSON files the website reads:
 *   data/live/latest.json            newest updates for the Latest Updates section
 *   data/live/series/<slug>.json     extra chapters for series pages on your site
 *   data/live/titles.json, title/*   titles found by the connectors (see publish_titles)
 *   data/pages/<id>/*                chapter pages you uploaded, for sources with hosting permission (lib/pages.php)
 * Apart from those uploads: metadata and links only, never images or content from other sites.
 */
function publish_updates(): int
{
    titles_adopt_feed_updates(); // every chapter gets its title and chapter pages
    $base = site_dir() . '/data/live';
    $onSite = site_series_index();
    hosted_pages(pages_publish());
    $limit = (int) config('published_updates');
    $from = 'FROM updates u LEFT JOIN sources s ON s.id = u.source_id LEFT JOIN titles t ON t.id = u.title_id
        WHERE u.hidden = 0 AND (s.id IS NOT NULL OR u.connector IS NOT NULL) AND (t.id IS NULL OR t.hidden = 0)';
    $cols = 'u.*, s.name AS source_name, s.method AS source_method, t.id AS tid, t.slug AS tslug';

    $rows = db_all("SELECT $cols $from ORDER BY COALESCE(u.release_date, u.created_at) DESC, u.id DESC LIMIT $limit");
    $updates = array_map(fn ($r) => [
        'series' => $r['series_title'],
        'slug' => $r['series_slug'],
        'onSite' => isset($onSite[$r['series_slug']]),
        'titlePage' => !isset($onSite[$r['series_slug']]) && $r['tid'] !== null,
        'titleSlug' => $r['tslug'],
        'hosted' => isset(hosted_pages()[(int) $r['id']]),
        'cover' => $onSite[$r['series_slug']]['cover'] ?? null,
        'chapter' => (float) $r['chapter_number'],
        'title' => $r['chapter_label'],
        'date' => gmdate('c', ts($r['release_date'] ?? $r['created_at'])),
        'sourceUrl' => $r['source_url'],
        'readable' => (bool) $r['readable'],
        'source' => update_source_label($r),
        'credit' => $r['credit'],
    ], $rows);

    write_atomic("$base/latest.json", json_encode(['generated' => gmdate('c'), 'updates' => $updates], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Per-series chapter lists, only for series that have a page on your site.
    $written = [];
    foreach (array_keys($onSite) as $slug) {
        $chs = db_all("SELECT $cols $from AND u.series_slug = ? ORDER BY u.chapter_number DESC LIMIT 200", [$slug]);
        if (!$chs) {
            continue;
        }
        write_if_changed("$base/series/$slug.json", json_encode(['chapters' => array_map('chapter_json', $chs)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $written[$slug] = true;
    }
    remove_unlisted("$base/series", $written);

    publish_titles($base, $onSite);
    meta_set('last_publish_at', now());
    meta_set('needs_publish', '0');
    meta_set('published_version', MANHUANI_VERSION);
    return count($updates);
}

/**
 * Titles found by the connectors, for pages that need no rebuild:
 *   data/live/titles.json          newest titles (the "New titles" section and page)
 *   data/live/title/<slug>.json    one title's details, links and chapters (title/?s=<slug>)
 */
function publish_titles(string $base, array $onSite): void
{
    $limit = (int) config('titles_published');
    $titles = db_all("SELECT * FROM titles WHERE hidden = 0 ORDER BY COALESCE(latest_chapter_at, created_at) DESC, id DESC LIMIT $limit");
    $list = [];
    $written = [];
    foreach ($titles as $t) {
        $sources = db_all('SELECT connector, external_id, url FROM title_sources WHERE title_id = ? ORDER BY id', [$t['id']]);
        $credits = array_map(fn ($x) => ['name' => title_source_label($x), 'url' => $x['url']], $sources);
        $links = json_decode((string) $t['links'], true) ?: [];
        $item = [
            'slug' => $t['slug'],
            'title' => $t['title'],
            'type' => $t['type'],
            'status' => $t['status'],
            'year' => $t['year'] !== null ? (int) $t['year'] : null,
            'genres' => json_decode((string) $t['genres'], true) ?: [],
            'rating' => $t['rating'] !== null ? (float) $t['rating'] : null,
            'latestChapter' => $t['latest_chapter'] !== null ? (float) $t['latest_chapter'] : null,
            'latestDate' => $t['latest_chapter_at'] ? gmdate('c', ts($t['latest_chapter_at'])) : null,
            'added' => gmdate('c', ts($t['created_at'])),
            'onSite' => isset($onSite[$t['slug']]),
            'readable' => (bool) array_filter($links, fn ($l) => $l['kind'] === 'read'),
            'sources' => array_values(array_unique(array_column($credits, 'name'))),
        ];
        $list[] = $item;
        $chs = db_all("SELECT u.*, s.name AS source_name, s.method AS source_method FROM updates u LEFT JOIN sources s ON s.id = u.source_id
            WHERE u.title_id = ? AND u.hidden = 0 ORDER BY u.chapter_number DESC LIMIT 300", [$t['id']]);
        $detail = $item + [
            'altTitles' => json_decode((string) $t['alt_titles'], true) ?: [],
            'links' => $links,
            'credits' => $credits,
            'chapters' => array_map(fn ($c) => chapter_json($c + ['tslug' => $t['slug']]), $chs),
        ];
        write_if_changed("$base/title/{$t['slug']}.json", json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $written[$t['slug']] = true;
    }
    remove_unlisted("$base/title", $written);
    write_atomic("$base/titles.json", json_encode(['generated' => gmdate('c'), 'titles' => $list], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** A connector's name, or for one of your own sources, the source's name. */
function title_source_label(array $x): string
{
    if ($x['connector'] === 'source') {
        $row = db_one('SELECT name FROM sources WHERE id = ?', [(int) strtok((string) $x['external_id'], ':')]);
        return $row ? (string) $row['name'] : (string) parse_url((string) $x['url'], PHP_URL_HOST);
    }
    return connector_label($x['connector']);
}

function chapter_json(array $c): array
{
    return [
        'number' => (float) $c['chapter_number'],
        'title' => $c['chapter_label'],
        'date' => gmdate('c', ts($c['release_date'] ?? $c['created_at'])),
        'sourceUrl' => $c['source_url'],
        'readable' => (bool) ($c['readable'] ?? true),
        'source' => update_source_label($c),
        'credit' => $c['credit'],
        'titleSlug' => $c['tslug'] ?? null,
    ] + (($pages = hosted_pages()[(int) $c['id']] ?? null) ? ['pages' => $pages] : []);
}

/** Who to credit for an update: the connector, MangaDex, or the feed source's name. */
function update_source_label(array $r): string
{
    if (!empty($r['connector'])) {
        return connector_label($r['connector']);
    }
    return ($r['source_method'] ?? null) === 'mangadex' ? 'MangaDex' : (string) ($r['source_name'] ?? '');
}

function write_if_changed(string $path, string $contents): void
{
    if (!is_file($path) || file_get_contents($path) !== $contents) {
        write_atomic($path, $contents);
    }
}

function remove_unlisted(string $dir, array $keep): void
{
    foreach (glob("$dir/*.json") ?: [] as $file) {
        if (!isset($keep[basename($file, '.json')])) {
            unlink($file);
        }
    }
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
