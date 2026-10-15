<?php
defined('MANHUANI') || exit;

function source_get(int $id): ?array
{
    return db_one('SELECT * FROM sources WHERE id = ?', [$id]);
}

function sources_all(): array
{
    return db_all('SELECT s.*, (SELECT COUNT(*) FROM updates u WHERE u.source_id = s.id) AS update_count FROM sources s ORDER BY s.name');
}

function normalize_url(string $url): ?string
{
    $url = trim($url);
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url) && !preg_match('#^[^/:]+:\d+(/|$)#', $url)) {
        return null; // another scheme such as ftp:, file: or javascript:
    }
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $p = parse_url($url);
    if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    return $url;
}

/** @return array{0:?int,1:?string} [id, error] */
function source_save(?int $id, array $in): array
{
    $name = str_limit((string) ($in['name'] ?? ''), 120);
    $url = normalize_url((string) ($in['url'] ?? ''));
    $interval = max((int) config('min_interval_minutes'), (int) ($in['interval_minutes'] ?? config('default_interval_minutes')));
    $permission = !empty($in['permission_confirmed']) ? 1 : 0;
    $notes = str_limit((string) ($in['notes'] ?? ''), 1000);
    if ($url === null) {
        return [null, 'Please enter a valid web address starting with http:// or https://'];
    }
    if ($name === '') {
        $name = is_mangadex_url($url) ? 'MangaDex' : (parse_url($url, PHP_URL_HOST) ?: $url);
    }
    $dupe = db_one('SELECT id FROM sources WHERE url = ? AND id <> ?', [$url, $id ?? 0]);
    if ($dupe) {
        return [null, 'That address is already saved as a source.'];
    }
    if ($id === null) {
        db_run('INSERT INTO sources (name, url, interval_minutes, permission_confirmed, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $url, $interval, $permission, $notes, now(), now()]);
        $id = (int) db()->lastInsertId();
        log_info("Source added: $name ($url)", $id);
        return [$id, null];
    }
    $old = source_get($id);
    if (!$old) {
        return [null, 'Source not found.'];
    }
    $urlChanged = $old['url'] !== $url;
    db_run('UPDATE sources SET name = ?, url = ?, interval_minutes = ?, permission_confirmed = ?, notes = ?, updated_at = ? WHERE id = ?',
        [$name, $url, $interval, $permission, $notes, now(), $id]);
    if ($urlChanged) {
        // A new address must be checked again before it can run.
        db_run("UPDATE sources SET feed_url = NULL, method = NULL, enabled = 0, robots_status = 'unknown', check_summary = NULL, etag = NULL, last_modified = NULL WHERE id = ?", [$id]);
    }
    if (!$permission && $old['enabled']) {
        db_run('UPDATE sources SET enabled = 0 WHERE id = ?', [$id]);
    }
    log_info("Source edited: $name" . ($urlChanged ? ' (address changed, needs a new test)' : ''), $id);
    return [$id, null];
}

/** @return ?string error, or null on success */
function source_set_enabled(int $id, bool $on): ?string
{
    $s = source_get($id);
    if (!$s) {
        return 'Source not found.';
    }
    if ($on) {
        if (!$s['feed_url']) {
            return 'Run "Test" first. A source can only be enabled once an authorized feed has been found.';
        }
        if ($s['robots_status'] !== 'allowed' && !($s['robots_status'] === 'api' && $s['method'] === 'mangadex')) {
            return 'This source\'s robots.txt does not allow automated access, so it can\'t be enabled.';
        }
        if (!$s['permission_confirmed']) {
            return 'Edit the source and confirm you are allowed to use it before enabling.';
        }
    }
    db_run('UPDATE sources SET enabled = ?, fail_count = 0, next_check_at = ?, updated_at = ? WHERE id = ?', [$on ? 1 : 0, now(), now(), $id]);
    log_info('Source ' . ($on ? 'enabled' : 'disabled') . ": {$s['name']}", $id);
    return null;
}

function source_delete(int $id, bool $withUpdates): void
{
    $s = source_get($id);
    if (!$s) {
        return;
    }
    if ($withUpdates) {
        db_run('DELETE FROM updates WHERE source_id = ?', [$id]);
    }
    db_run('DELETE FROM sources WHERE id = ?', [$id]);
    log_info("Source removed: {$s['name']}" . ($withUpdates ? ' (and its updates)' : ''));
}

/**
 * "Test" button: checks robots.txt, anti-bot walls, and looks for an authorized
 * feed. Saves what it finds; never enables the source by itself.
 * @return array{ok:bool, lines:list<array{0:string,1:string}>}  [level, text]
 */
function source_test(int $id): array
{
    $s = source_get($id);
    if (!$s) {
        return ['ok' => false, 'lines' => [['error', 'Source not found.']]];
    }
    if (is_mangadex_url($s['url'])) {
        return mangadex_test($s);
    }
    $lines = [];
    $add = function (string $level, string $text) use (&$lines) { $lines[] = [$level, $text]; };
    $feedUrl = null;
    $method = null;
    $robotsStatus = 'unknown';
    $delay = 0;

    $robots = robots_check($s['url']);
    $delay = $robots['delay'];
    $robotsStatus = $robots['allowed'] ? 'allowed' : 'disallowed';
    $add($robots['allowed'] ? 'ok' : 'error', $robots['reason']);

    if ($robots['allowed']) {
        $host = (string) parse_url($s['url'], PHP_URL_HOST);
        Http::setHostDelay($host, $delay);
        $res = Http::get($s['url']);
        if ($res['error']) {
            $add('error', 'Could not load the address: ' . $res['error']);
        } elseif ($blocker = detect_blocker($res)) {
            $add('error', $blocker);
            $robotsStatus = 'blocked';
        } elseif ($res['status'] >= 400) {
            $add('error', 'The site answered with HTTP ' . $res['status'] . '.');
        } else {
            $kind = feed_kind($res);
            $candidates = [];
            $best = null;
            if ($kind) {
                $add('ok', 'This address is itself a ' . strtoupper($kind) . ' feed.');
                $best = feed_score($res, $kind);
            } else {
                $api = detect_api($res['body'], $res['url']);
                if ($api) {
                    $add('info', "Found an API: $api. It is not used automatically. Only use it if the site's terms allow.");
                }
                $candidates = discover_feeds($res['body'], $res['url']);
                $add($candidates ? 'ok' : 'warning', $candidates ? 'The page advertises ' . count($candidates) . ' feed(s).' : 'The page does not advertise an RSS/Atom feed.');
                if (!$candidates) {
                    $u = parse_url($res['url']);
                    $root = $u['scheme'] . '://' . $u['host'];
                    foreach (['/feed/', '/rss', '/rss.xml', '/feed.xml', '/atom.xml'] as $guess) {
                        $candidates[] = $root . $guess;
                    }
                    $add('info', 'Trying the usual feed addresses.');
                }
            }
            // Look at each feed and keep the one with the most chapter releases
            // (sites often list a blog or comments feed first).
            foreach (array_slice($candidates, 0, 5) as $cand) {
                $r = robots_check($cand);
                if (!$r['allowed']) {
                    $add('warning', "Skipping $cand: " . $r['reason']);
                    continue;
                }
                $fr = Http::get($cand);
                if ($fr['error'] || $fr['status'] >= 400 || detect_blocker($fr)) {
                    continue;
                }
                $k = feed_kind($fr);
                if (!$k) {
                    continue;
                }
                $score = feed_score($fr, $k);
                $add('info', strtoupper($k) . " feed at {$score['url']}: {$score['items']} item(s), {$score['chapters']} chapter release(s).");
                if ($best === null || $score['chapters'] > $best['chapters']) {
                    $best = $score;
                }
            }
            if ($best !== null) {
                if ($best['error'] !== null) {
                    $add('error', 'The feed could not be read: ' . $best['error']);
                } elseif ($best['chapters'] === 0) {
                    $add('error', "The feed has {$best['items']} item(s) but none look like chapter releases (for example \"Series Name Chapter 12\"). "
                        . 'It is probably a blog or news feed. If the site has a separate chapter or series feed, paste that address instead.');
                } else {
                    $feedUrl = $best['url'];
                    $method = $best['kind'];
                    $add('ok', 'Authorized feed found: ' . strtoupper($method) . " at $feedUrl");
                    $add('ok', "The feed has {$best['items']} item(s); {$best['chapters']} look like chapter releases. For example: " . implode('; ', $best['examples']) . '.');
                }
            }
        }
    }

    if ($feedUrl === null && $robotsStatus === 'allowed' && !array_filter($lines, fn ($l) => $l[0] === 'error')) {
        $add('error', 'No authorized feed was found. This source can\'t be used automatically. Web pages are never scraped.');
    }

    $ok = $feedUrl !== null && $robotsStatus === 'allowed';
    $summary = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    db_run('UPDATE sources SET feed_url = ?, method = ?, robots_status = ?, crawl_delay = ?, check_summary = ?, last_checked_at = ?, updated_at = ?' . ($ok ? '' : ', enabled = 0') . ' WHERE id = ?',
        [$feedUrl, $method, $robotsStatus, $delay, $summary, now(), now(), $id]);
    log_event($ok ? 'info' : 'warning', 'Test ' . ($ok ? 'passed' : 'did not pass') . " for {$s['name']}", $id);
    return ['ok' => $ok, 'lines' => $lines];
}

/** @return array{url:string, kind:string, items:int, chapters:int, examples:list<string>, error:?string} */
function feed_score(array $res, string $kind): array
{
    $score = ['url' => $res['url'], 'kind' => $kind, 'items' => 0, 'chapters' => 0, 'examples' => [], 'error' => null];
    try {
        $parsed = parse_feed($res['body'], $kind, $res['url']);
    } catch (Throwable $e) {
        $score['error'] = $e->getMessage();
        return $score;
    }
    $score['items'] = count($parsed['items']);
    foreach ($parsed['items'] as $it) {
        $x = extract_chapter($it['title'], $parsed['title']);
        if ($x['number'] !== null) {
            $score['chapters']++;
            if (count($score['examples']) < 3) {
                $score['examples'][] = $x['series'] . ' – Chapter ' . format_chapter($x['number']);
            }
        }
    }
    return $score;
}

function format_chapter($n): string
{
    if ($n === null || $n === '') {
        return '';
    }
    $f = (float) $n;
    return floor($f) == $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}
