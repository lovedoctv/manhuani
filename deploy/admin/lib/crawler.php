<?php
defined('MANHUANI') || exit;

/**
 * One crawler run (called by cron). Checks enabled sources that are due,
 * stores new chapter updates, then refreshes the website's update files.
 * @return array{checked:int, new:int, errors:int}
 */
function crawler_run(?int $onlySourceId = null, bool $force = false): array
{
    $lockFile = config('private_dir') . '/crawler.lock';
    $lock = fopen($lockFile, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        log_warn('Another crawler run is still working, so this one was skipped.');
        return ['checked' => 0, 'new' => 0, 'errors' => 0];
    }
    Http::resetBudget();
    $stats = ['checked' => 0, 'new' => 0, 'errors' => 0];
    try {
        if ($onlySourceId !== null) {
            $sources = db_all('SELECT * FROM sources WHERE id = ? AND enabled = 1', [$onlySourceId]);
        } else {
            $sql = 'SELECT * FROM sources WHERE enabled = 1 AND permission_confirmed = 1 AND feed_url IS NOT NULL'
                . ($force ? '' : ' AND (next_check_at IS NULL OR next_check_at <= ?)')
                . ' ORDER BY next_check_at LIMIT ' . (int) config('max_sources_per_run');
            $sources = db_all($sql, $force ? [] : [now()]);
        }
        foreach ($sources as $s) {
            $stats['checked']++;
            try {
                $stats['new'] += crawl_source($s);
            } catch (Throwable $e) {
                $stats['errors']++;
                source_failed($s, $e->getMessage());
            }
        }
        if ($stats['new'] > 0 || $force || !is_file(site_dir() . '/data/live/latest.json')) {
            publish_updates();
        }
        prune_old_rows();
        meta_set('last_run_at', now());
        meta_set('last_run_stats', json_encode($stats));
        if ($stats['checked']) {
            log_info("Run finished: {$stats['checked']} source(s) checked, {$stats['new']} new update(s), {$stats['errors']} error(s).");
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $stats;
}

/** @return int number of new updates */
function crawl_source(array $s): int
{
    if ($s['method'] === 'mangadex') {
        return mangadex_crawl($s);
    }
    $id = (int) $s['id'];
    $feedUrl = (string) $s['feed_url'];

    $robots = robots_check($feedUrl);
    if (!$robots['allowed']) {
        db_run("UPDATE sources SET enabled = 0, robots_status = 'disallowed', last_checked_at = ?, last_error = ? WHERE id = ?", [now(), $robots['reason'], $id]);
        log_warn("Disabled {$s['name']}: " . $robots['reason'], $id);
        return 0;
    }
    $delay = max($robots['delay'], (int) $s['crawl_delay']);
    Http::setHostDelay((string) parse_url($feedUrl, PHP_URL_HOST), $delay);

    $headers = [];
    if ($s['etag']) $headers[] = 'If-None-Match: ' . $s['etag'];
    if ($s['last_modified']) $headers[] = 'If-Modified-Since: ' . $s['last_modified'];
    $res = Http::get($feedUrl, $headers);

    if ($res['error']) {
        throw new RuntimeException($res['error']);
    }
    if ($blocker = detect_blocker($res)) {
        db_run("UPDATE sources SET enabled = 0, robots_status = 'blocked', last_checked_at = ?, last_error = ? WHERE id = ?", [now(), $blocker, $id]);
        log_error("Disabled {$s['name']}: $blocker", $id);
        return 0;
    }
    if ($res['status'] === 429 || $res['status'] === 503) {
        $retry = (int) ($res['headers']['retry-after'] ?? 0);
        $wait = max($retry, 3600);
        db_run('UPDATE sources SET last_checked_at = ?, next_check_at = ?, last_error = ? WHERE id = ?',
            [now(), gmdate('Y-m-d H:i:s', time() + min($wait, 86400)), 'Site asked us to slow down (HTTP ' . $res['status'] . ')', $id]);
        log_warn("{$s['name']} asked us to slow down (HTTP {$res['status']}). Next check in " . round(min($wait, 86400) / 60) . ' min.', $id);
        return 0;
    }
    if ($res['status'] === 304) {
        source_succeeded($s, null, null);
        return 0;
    }
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Feed answered with HTTP ' . $res['status']);
    }
    $kind = feed_kind($res) ?? $s['method'];
    if (!$kind) {
        throw new RuntimeException('The address no longer returns a feed');
    }
    $parsed = parse_feed($res['body'], $kind, $feedUrl);

    $new = 0;
    foreach ($parsed['items'] as $item) {
        if ($item['title'] === '' || $item['link'] === '' || !preg_match('#^https?://#i', $item['link'])) {
            continue;
        }
        $x = extract_chapter($item['title'], $parsed['title']);
        if ($x['number'] === null || $x['series'] === '') {
            continue; // only chapter releases are stored
        }
        $slug = slugify($x['series']);
        if ($slug === '') {
            continue;
        }
        $dedupe = sha1($slug . '|' . format_chapter($x['number']));
        $inserted = db_insert_ignore('updates', [
            'source_id' => $id,
            'series_title' => $x['series'],
            'series_slug' => $slug,
            'chapter_number' => $x['number'],
            'chapter_label' => $x['label'] ?: null,
            'release_date' => $item['date'],
            'source_url' => str_limit($item['link'], 500),
            'dedupe_key' => $dedupe,
            'created_at' => now(),
        ]);
        if ($inserted) {
            $new++;
        }
    }
    source_succeeded($s, $res['headers']['etag'] ?? null, $res['headers']['last-modified'] ?? null);
    if ($new) {
        log_info("{$s['name']}: $new new update(s).", $id);
    }
    return $new;
}

function source_succeeded(array $s, ?string $etag, ?string $lastModified): void
{
    $next = gmdate('Y-m-d H:i:s', time() + 60 * max((int) $s['interval_minutes'], (int) config('min_interval_minutes')));
    db_run('UPDATE sources SET last_checked_at = ?, last_success_at = ?, last_error = NULL, fail_count = 0, next_check_at = ?, etag = COALESCE(?, etag), last_modified = COALESCE(?, last_modified) WHERE id = ?',
        [now(), now(), $next, $etag, $lastModified, $s['id']]);
}

function source_failed(array $s, string $message): void
{
    $fails = (int) $s['fail_count'] + 1;
    // Back off: wait longer after each failure (interval x 2, x 4, ... up to a day).
    $minutes = min(1440, max((int) $s['interval_minutes'], 30) * (2 ** min($fails - 1, 5)));
    $disable = $fails >= (int) config('disable_after_failures');
    db_run('UPDATE sources SET last_checked_at = ?, last_error = ?, fail_count = ?, next_check_at = ?, enabled = ? WHERE id = ?',
        [now(), str_limit($message, 1000), $fails, gmdate('Y-m-d H:i:s', time() + 60 * $minutes), $disable ? 0 : 1, $s['id']]);
    log_error("{$s['name']}: $message" . ($disable ? " (disabled after $fails failures in a row)" : " (retry in $minutes min)"), (int) $s['id']);
}
