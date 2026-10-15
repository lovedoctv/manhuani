<?php
defined('MANHUANI') || exit;

/*
 * Built-in connectors: official APIs that are published for programs to use.
 * Each one has a run function (called by the cron job when it is due) and a test
 * function (the dashboard's Test button). They all save through lib/titles.php,
 * so the same title or chapter found by several of them is stored once.
 *
 *   MangaDex      new titles, chapters, links to read them on mangadex.org
 *   MangaUpdates  chapter releases by scanlation groups (no reading links)
 *   AniList       series details, official reading links, trending titles
 *   Bangumi       Chinese names and pages for manhua
 *   Anime News Network  series details and official websites
 *
 * Every request goes through Http (robots-free, but spaced 3+ seconds apart per
 * site, capped per run), api_call() adds caching, one retry and rate-limit handling.
 * A connector that fails is logged and backed off; the others keep running.
 */

final class ApiRateLimited extends RuntimeException
{
    public function __construct(string $message, public int $retryAt)
    {
        parent::__construct($message);
    }
}

function connector_defs(): array
{
    return [
        'mangadex' => [
            'label' => 'MangaDex',
            'does' => 'Finds new manhwa and manhua titles and their new English chapters, with links to read them on mangadex.org.',
            'terms' => 'Your site must not run ads or paid services. Credit MangaDex and the scanlation group (done automatically). Remove updates if a group asks. About 5 requests a second is the limit; this uses 1 to 3 per run.',
            'docs' => 'https://api.mangadex.org/docs/',
            'every' => 60,
            'run' => 'cx_mangadex_run',
            'test' => 'cx_mangadex_test',
        ],
        'mangaupdates' => [
            'label' => 'MangaUpdates',
            'does' => 'Reads today\'s chapter releases from scanlation groups and adds the manhwa and manhua ones. MangaUpdates has no reading links, so these chapters link to the series page on MangaUpdates.',
            'terms' => 'Credit MangaUpdates (done automatically), space requests out and cache answers (done automatically). Don\'t use the data to deceive users or spam.',
            'docs' => 'https://api.mangaupdates.com/',
            'every' => 30,
            'run' => 'cx_mangaupdates_run',
            'test' => 'cx_mangaupdates_test',
        ],
        'anilist' => [
            'label' => 'AniList',
            'does' => 'Fills in genres, status, year, rating and official reading sites for titles, and adds a few trending manhwa and manhua once a day.',
            'terms' => 'Free for non-commercial sites (or under $150 a month). Not to be used to store or mass-collect its data, so only small batches are fetched. 90 requests a minute is the limit.',
            'docs' => 'https://docs.anilist.co/',
            'every' => 120,
            'run' => 'cx_anilist_run',
            'test' => 'cx_anilist_test',
        ],
        'bangumi' => [
            'label' => 'Bangumi',
            'does' => 'Matches manhua by their Chinese names and links their Bangumi pages (bgm.tv).',
            'terms' => 'Public API for apps built on Bangumi. Requests must carry an identifying User-Agent (done automatically).',
            'docs' => 'https://github.com/bangumi/api',
            'every' => 180,
            'run' => 'cx_bangumi_run',
            'test' => 'cx_bangumi_test',
        ],
        'ann' => [
            'label' => 'Anime News Network',
            'does' => 'Fills in genres, year and official websites from the ANN Encyclopedia.',
            'terms' => 'Free API. List Anime News Network as the source and link the encyclopedia entry (done automatically). At most 1 request a second; cache answers for a week (done automatically).',
            'docs' => 'https://www.animenewsnetwork.com/encyclopedia/api.php',
            'every' => 180,
            'run' => 'cx_ann_run',
            'test' => 'cx_ann_test',
        ],
    ];
}

function connector_label(?string $name): string
{
    return $name === 'source' ? 'Your sources' : (connector_defs()[$name]['label'] ?? (string) $name);
}

function connector_row(string $name): array
{
    $row = db_one('SELECT * FROM connectors WHERE name = ?', [$name]);
    if (!$row) {
        db_insert_ignore('connectors', ['name' => $name, 'enabled' => 0, 'terms_accepted' => 0]);
        $row = db_one('SELECT * FROM connectors WHERE name = ?', [$name]);
    }
    return $row;
}

function connectors_all(): array
{
    $out = [];
    foreach (connector_defs() as $name => $def) {
        $out[$name] = connector_row($name) + ['def' => $def];
    }
    return $out;
}

function connector_interval(string $name): int
{
    $custom = (array) config('connector_intervals');
    return max((int) config('min_interval_minutes'), (int) ($custom[$name] ?? connector_defs()[$name]['every']));
}

/** @return ?string error */
function connector_set_enabled(string $name, bool $on, bool $termsAccepted): ?string
{
    if (!isset(connector_defs()[$name])) {
        return 'Unknown connector.';
    }
    connector_row($name);
    if ($on && !$termsAccepted) {
        return 'Tick the box to confirm you accept ' . connector_label($name) . '\'s terms first.';
    }
    db_run('UPDATE connectors SET enabled = ?, fail_count = 0, next_run_at = ? WHERE name = ?', [$on ? 1 : 0, now(), $name]);
    if ($termsAccepted) {
        db_run('UPDATE connectors SET terms_accepted = 1 WHERE name = ?', [$name]);
    }
    log_info(connector_label($name) . ($on ? ' enabled' : ' disabled'));
    return null;
}

/**
 * Runs every enabled connector that is due (or all of them with $force).
 * @return array{checked:int, new:int, titles:int, errors:int}
 */
function connectors_run(bool $force = false, ?string $only = null): array
{
    $stats = ['checked' => 0, 'new' => 0, 'titles' => 0, 'errors' => 0];
    foreach (connector_defs() as $name => $def) {
        if ($only !== null && $only !== $name) {
            continue;
        }
        $row = connector_row($name);
        if (!$row['enabled'] || !$row['terms_accepted']) {
            continue;
        }
        if (!$force && $row['next_run_at'] && $row['next_run_at'] > now()) {
            continue;
        }
        $stats['checked']++;
        Http::resetBudget((int) config('connector_max_requests'));
        $state = json_decode((string) $row['state'], true) ?: [];
        try {
            $r = ($def['run'])($state) + ['new' => 0, 'titles' => 0, 'linked' => 0];
            $r['requests'] = Http::requestCount();
            db_run('UPDATE connectors SET state = ?, last_run_at = ?, last_success_at = ?, last_error = NULL, last_stats = ?, fail_count = 0, next_run_at = ? WHERE name = ?', [
                json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now(), now(), json_encode($r),
                gmdate('Y-m-d H:i:s', time() + 60 * connector_interval($name)), $name,
            ]);
            $stats['new'] += $r['new'];
            $stats['titles'] += $r['titles'];
            if ($r['new'] || $r['titles'] || $r['linked']) {
                log_info("{$def['label']}: {$r['titles']} new title(s), {$r['new']} new chapter(s), {$r['linked']} title(s) matched.");
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            // Keep progress made before the failure (cursors only move past saved work).
            db_run('UPDATE connectors SET state = ? WHERE name = ?', [json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $name]);
            connector_failed($name, $row, $e);
        }
    }
    Http::resetBudget();
    return $stats;
}

function connector_failed(string $name, array $row, Throwable $e): void
{
    $label = connector_label($name);
    $now = time();
    if ($e instanceof ApiRateLimited) {
        $next = min(max($e->retryAt, $now + 600), $now + 86400);
        db_run('UPDATE connectors SET last_run_at = ?, last_error = ?, next_run_at = ? WHERE name = ?', [now(), $e->getMessage(), gmdate('Y-m-d H:i:s', $next), $name]);
        log_warn("$label: " . $e->getMessage() . ' Next run in ' . round(($next - $now) / 60) . ' min.');
        return;
    }
    $fails = (int) $row['fail_count'] + 1;
    $minutes = min(1440, connector_interval($name) * (2 ** min($fails - 1, 5)));
    $disable = $fails >= (int) config('disable_after_failures');
    db_run('UPDATE connectors SET last_run_at = ?, last_error = ?, fail_count = ?, next_run_at = ?, enabled = ? WHERE name = ?', [
        now(), str_limit($e->getMessage(), 1000), $fails, gmdate('Y-m-d H:i:s', $now + 60 * $minutes), $disable ? 0 : 1, $name,
    ]);
    log_error("$label: " . $e->getMessage() . ($disable ? " (switched off after $fails failures in a row)" : " (retry in $minutes min)"));
}

/** Test button: one small request, a readable report, nothing saved. */
function connector_test(string $name): array
{
    $def = connector_defs()[$name] ?? null;
    if (!$def) {
        return ['ok' => false, 'lines' => [['error', 'Unknown connector.']]];
    }
    Http::resetBudget((int) config('connector_max_requests'));
    try {
        $lines = ($def['test'])();
        $ok = !array_filter($lines, fn ($l) => $l[0] === 'error');
    } catch (Throwable $e) {
        $lines = [['error', $e->getMessage()]];
        $ok = false;
    }
    Http::resetBudget();
    log_event($ok ? 'info' : 'warning', "Test of {$def['label']} " . ($ok ? 'passed' : 'did not pass'));
    return ['ok' => $ok, 'lines' => $lines];
}

/**
 * One API request with caching, one retry for network and server errors, and
 * rate-limit handling. Returns decoded JSON, or the raw body with $raw.
 * A 404 returns null (nothing found).
 */
function api_call(string $label, string $url, ?array $postJson = null, int $cacheSeconds = 0, bool $raw = false)
{
    $key = sha1($url . '|' . ($postJson === null ? '' : json_encode($postJson)));
    if ($cacheSeconds > 0 && ($c = db_one('SELECT body FROM api_cache WHERE k = ? AND expires_at > ?', [$key, now()]))) {
        return $raw ? $c['body'] : json_decode($c['body'], true);
    }
    for ($attempt = 1; ; $attempt++) {
        $res = $postJson === null
            ? Http::get($url, [$raw ? 'Accept: application/xml, text/xml, */*;q=0.5' : 'Accept: application/json'], 1)
            : Http::postJson($url, $postJson);
        if ($res['status'] === 404) {
            return null;
        }
        if ($raw && $res['error'] === null && $res['status'] >= 200 && $res['status'] < 300) {
            $out = $res['body'];
            break;
        }
        $r = api_result($res, $label);
        if ($r['retry_at'] !== null) {
            throw new ApiRateLimited($r['error'], $r['retry_at']);
        }
        if ($r['error'] === null) {
            $out = $r['data'];
            break;
        }
        $transient = $res['status'] === 0 || $res['status'] >= 500;
        if ($attempt >= 2 || !$transient || str_contains($r['error'], 'Request limit')) {
            throw new RuntimeException($r['error']);
        }
        sleep(2);
    }
    if ($cacheSeconds > 0) {
        $body = $raw ? $out : json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($body) < 500000) {
            db_run('DELETE FROM api_cache WHERE k = ?', [$key]);
            db_run('INSERT INTO api_cache (k, body, expires_at) VALUES (?, ?, ?)', [$key, $body, gmdate('Y-m-d H:i:s', time() + $cacheSeconds)]);
        }
    }
    return $out;
}

function type_from_language(?string $lang): ?string
{
    $lang = strtolower((string) $lang);
    return match (true) {
        $lang === 'ko', $lang === 'kr' => 'manhwa',
        in_array($lang, ['zh', 'zh-hk', 'zh-tw', 'cn', 'tw', 'hk'], true) => 'manhua',
        $lang === 'ja', $lang === 'jp' => 'manga',
        default => null,
    };
}

/** Number shaped chapter labels only ("12", "12.5"); "1-3" or "Extra" are skipped. */
function chapter_number($v): ?float
{
    $v = trim((string) $v);
    return preg_match('/^\d{1,5}(\.\d{1,2})?$/', $v) ? (float) $v : null;
}

/** Titles that still need a lookup on $connector: no row there yet, not tried recently. */
function titles_needing(string $connector, array &$state, int $limit, ?string $type = null): array
{
    $tried = $state['tried'] ?? [];
    $rows = db_all('SELECT t.* FROM titles t WHERE t.hidden = 0 AND NOT EXISTS (SELECT 1 FROM title_sources s WHERE s.title_id = t.id AND s.connector = ?)'
        . ($type ? ' AND t.type = ?' : '') . ' ORDER BY t.updated_at DESC LIMIT 200', $type ? [$connector, $type] : [$connector]);
    $out = [];
    foreach ($rows as $r) {
        if (isset($tried[$r['id']]) && $tried[$r['id']] > time() - 30 * 86400) {
            continue; // looked for it in the last 30 days without a match
        }
        $out[] = $r;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function mark_tried(array &$state, int $titleId): void
{
    $state['tried'][$titleId] = time();
    if (count($state['tried']) > 3000) {
        asort($state['tried']);
        $state['tried'] = array_slice($state['tried'], -2000, null, true);
    }
}

/** Every name a title is known by. */
function title_names(array $t): array
{
    return array_values(array_unique(array_merge([$t['title']], json_decode((string) $t['alt_titles'], true) ?: [])));
}

function names_match(array $a, array $b): bool
{
    $ka = array_filter(array_map('title_key', $a));
    foreach ($b as $n) {
        if (($k = title_key((string) $n)) !== null && in_array($k, $ka, true)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------- MangaDex

function cx_mangadex_url(int $offset, int $limit): string
{
    $q = ['translatedLanguage%5B%5D=' . rawurlencode(mangadex_language()), 'order%5BreadableAt%5D=desc', "limit=$limit", "offset=$offset",
        'includes%5B%5D=manga', 'includes%5B%5D=scanlation_group', 'contentRating%5B%5D=safe', 'contentRating%5B%5D=suggestive'];
    foreach ((array) config('mangadex_original_languages') as $lang) {
        $q[] = 'originalLanguage%5B%5D=' . rawurlencode((string) $lang);
    }
    return mangadex_api() . '/chapter?' . implode('&', $q);
}

/** A MangaDex manga object (with attributes) as title_save() input. */
function cx_mangadex_title(array $m): ?array
{
    $a = $m['attributes'] ?? null;
    if (!is_array($a) || !preg_match('/^' . MANGADEX_UUID . '$/i', (string) ($m['id'] ?? ''))) {
        return null;
    }
    $alt = [];
    foreach ($a['altTitles'] ?? [] as $x) {
        foreach ((array) $x as $lang => $v) {
            $alt[] = (string) $v;
        }
    }
    foreach ((array) ($a['title'] ?? []) as $v) {
        $alt[] = (string) $v;
    }
    $genres = [];
    foreach ($a['tags'] ?? [] as $tag) {
        if (in_array($tag['attributes']['group'] ?? '', ['genre', 'theme'], true) && !empty($tag['attributes']['name']['en'])) {
            $genres[] = slugify($tag['attributes']['name']['en']);
        }
    }
    $links = [['name' => 'MangaDex', 'url' => 'https://mangadex.org/title/' . $m['id'], 'kind' => 'read']];
    $l = (array) ($a['links'] ?? []);
    if (!empty($l['engtl']) && preg_match('#^https?://#', (string) $l['engtl'])) {
        $host = preg_replace('/^www\./', '', (string) parse_url($l['engtl'], PHP_URL_HOST));
        $links[] = ['name' => ucfirst(explode('.', $host)[0]) . ' (official)', 'url' => $l['engtl'], 'kind' => 'read'];
    }
    $status = ['ongoing' => 'ongoing', 'completed' => 'completed', 'hiatus' => 'hiatus', 'cancelled' => 'cancelled'][$a['status'] ?? ''] ?? null;
    return [
        'connector' => 'mangadex', 'external_id' => $m['id'], 'url' => 'https://mangadex.org/title/' . $m['id'],
        'title' => mangadex_title($a), 'alt_titles' => $alt, 'type' => type_from_language($a['originalLanguage'] ?? null),
        'status' => $status, 'year' => $a['year'] ?? null, 'genres' => $genres, 'links' => $links,
        'cross_ids' => ['anilist' => $l['al'] ?? null, 'mangaupdates' => $l['mu'] ?? null],
        'data' => ['contentRating' => $a['contentRating'] ?? null],
        'content_rating' => $a['contentRating'] ?? null,
    ];
}

function cx_mangadex_run(array &$state): array
{
    $since = (string) ($state['since'] ?? gmdate('Y-m-d\TH:i:s', time() - 86400)); // first run: the last day
    $newest = $since;
    $out = ['new' => 0, 'titles' => 0, 'linked' => 0];
    $pages = max(1, (int) config('mangadex_discover_pages'));
    for ($p = 0; $p < $pages; $p++) {
        $data = api_call('MangaDex', cx_mangadex_url($p * 100, 100));
        $chapters = $data['data'] ?? [];
        $older = false;
        foreach ($chapters as $ch) {
            $a = $ch['attributes'] ?? [];
            $at = gmdate('Y-m-d\TH:i:s', strtotime((string) ($a['readableAt'] ?? $a['publishAt'] ?? 'now')) ?: time());
            if ($at <= $since) {
                $older = true;
                continue;
            }
            $newest = max($newest, $at);
            $num = chapter_number($a['chapter'] ?? null);
            $manga = null;
            $groups = [];
            foreach ($ch['relationships'] ?? [] as $rel) {
                if (($rel['type'] ?? '') === 'manga') {
                    $manga = $rel;
                } elseif (($rel['type'] ?? '') === 'scanlation_group' && !empty($rel['attributes']['name'])) {
                    $groups[] = (string) $rel['attributes']['name'];
                }
            }
            $info = $manga ? cx_mangadex_title($manga) : null;
            if (!$info || $num === null || !empty($a['isUnavailable']) || !wanted_type($info['type']) || adult_genre($info['genres'])
                || !in_array($info['content_rating'], ['safe', 'suggestive'], true)) {
                continue;
            }
            [$tid, $created] = title_save($info + ['create' => true]);
            if (!$tid) {
                continue;
            }
            $out['titles'] += $created ? 1 : 0;
            $label = trim((string) ($a['title'] ?? ''));
            if (title_add_chapter($tid, 'mangadex', $num, $label !== '' ? str_limit($label, 255) : null, feed_date($a['readableAt'] ?? null),
                'https://mangadex.org/chapter/' . strtolower((string) $ch['id']), $groups ? str_limit(implode(', ', array_unique($groups)), 255) : null, true)) {
                $out['new']++;
            }
        }
        if ($older || count($chapters) < 100) {
            break;
        }
    }
    $state['since'] = $newest;
    return $out;
}

function cx_mangadex_test(): array
{
    $data = api_call('MangaDex', cx_mangadex_url(0, 10));
    $lines = [['ok', 'MangaDex answered. ' . (int) ($data['total'] ?? 0) . ' English chapters of ' . implode(', ', (array) config('mangadex_original_languages')) . ' titles are listed.']];
    $ex = [];
    foreach ($data['data'] ?? [] as $ch) {
        foreach ($ch['relationships'] ?? [] as $rel) {
            if (($rel['type'] ?? '') === 'manga' && ($info = cx_mangadex_title($rel))) {
                $ex[] = $info['title'] . ' – Chapter ' . ($ch['attributes']['chapter'] ?? '?') . ' (' . ($info['type'] ?? '?') . ')';
            }
        }
    }
    $lines[] = $ex ? ['ok', 'Newest: ' . implode('; ', array_slice($ex, 0, 4)) . '.'] : ['error', 'The answer had no chapters with title details.'];
    return $lines;
}

// ---------------------------------------------------------------- MangaUpdates

function mu_base36($id): string
{
    $n = (string) $id;
    if (!ctype_digit($n)) {
        return strtolower($n);
    }
    $digits = '0123456789abcdefghijklmnopqrstuvwxyz';
    $out = '';
    $v = (int) $n;
    do {
        $out = $digits[$v % 36] . $out;
        $v = intdiv($v, 36);
    } while ($v > 0);
    return $out;
}

/** A MangaUpdates series record as title_save() input. */
function cx_mu_title(array $s): ?array
{
    if (empty($s['series_id']) || empty($s['title'])) {
        return null;
    }
    $genres = [];
    foreach ($s['genres'] ?? [] as $g) {
        $genres[] = slugify(is_array($g) ? (string) ($g['genre'] ?? '') : (string) $g);
    }
    $alt = [];
    foreach ($s['associated'] ?? [] as $x) {
        $alt[] = is_array($x) ? (string) ($x['title'] ?? '') : (string) $x;
    }
    $type = ['manhwa' => 'manhwa', 'manhua' => 'manhua', 'manga' => 'manga'][strtolower((string) ($s['type'] ?? ''))] ?? null;
    $url = preg_match('#^https://www\.mangaupdates\.com/#', (string) ($s['url'] ?? '')) ? $s['url'] : 'https://www.mangaupdates.com/series/' . mu_base36($s['series_id']);
    return [
        'connector' => 'mangaupdates', 'external_id' => mu_base36($s['series_id']), 'url' => $url,
        'title' => html_entity_decode((string) $s['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'alt_titles' => array_map(fn ($x) => html_entity_decode($x, ENT_QUOTES | ENT_HTML5, 'UTF-8'), array_filter($alt)),
        'type' => $type, 'status' => isset($s['completed']) ? ($s['completed'] ? 'completed' : 'ongoing') : null,
        'year' => is_numeric($s['year'] ?? null) ? (int) $s['year'] : null, 'genres' => array_filter($genres),
        'rating' => is_numeric($s['bayesian_rating'] ?? null) && $s['bayesian_rating'] > 0 ? round($s['bayesian_rating'] / 2, 1) : null,
        'links' => [['name' => 'MangaUpdates', 'url' => $url, 'kind' => 'info']],
        'data' => ['latest_chapter' => $s['latest_chapter'] ?? null],
    ];
}

function cx_mangaupdates_run(array &$state): array
{
    $api = rtrim((string) config('mangaupdates_api'), '/');
    $cursor = (int) ($state['cursor'] ?? time() - 6 * 3600); // first run: the last 6 hours
    $today = gmdate('Y-m-d');
    $pages = ($state['page2_day'] ?? '') === $today ? [1] : [1, 2];
    $records = [];
    foreach ($pages as $page) {
        $data = api_call('MangaUpdates', "$api/releases/days?include_metadata=true&page=$page");
        foreach ($data['results'] ?? [] as $r) {
            if (isset($r['record']['time_added']['timestamp'])) {
                $records[] = $r;
            }
        }
    }
    $state['page2_day'] = $today;
    usort($records, fn ($a, $b) => $a['record']['time_added']['timestamp'] <=> $b['record']['time_added']['timestamp']);

    $out = ['new' => 0, 'titles' => 0, 'linked' => 0];
    $lookups = 0;
    $max = (int) config('mangaupdates_lookups_per_run');
    foreach ($records as $r) {
        $rec = $r['record'];
        $ts = (int) $rec['time_added']['timestamp'];
        if ($ts <= $cursor) {
            continue;
        }
        $sid = $r['metadata']['series']['series_id'] ?? null;
        $num = chapter_number($rec['chapter'] ?? null);
        if (!$sid || $num === null) {
            $cursor = $ts;
            continue;
        }
        $key = mu_base36($sid);
        $known = db_one("SELECT title_id FROM title_sources WHERE connector = 'mangaupdates' AND external_id = ?", [$key]);
        if (!$known) {
            $cacheKey = sha1("$api/series/$sid|");
            $cached = db_one('SELECT 1 FROM api_cache WHERE k = ? AND expires_at > ?', [$cacheKey, now()]);
            if (!$cached && $lookups >= $max) {
                break; // finish next run; the cursor stays before this release
            }
            $lookups += $cached ? 0 : 1;
            $series = api_call('MangaUpdates', "$api/series/$sid", null, 30 * 86400);
            $info = is_array($series) ? cx_mu_title($series) : null;
            if (!$info || !wanted_type($info['type']) || adult_genre($info['genres'])) {
                $cursor = $ts;
                continue;
            }
            [$tid, $created] = title_save($info + ['create' => true]);
            $out['titles'] += $created ? 1 : 0;
        } else {
            $tid = (int) $known['title_id'];
        }
        if ($tid) {
            $t = title_get($tid);
            $relName = trim(html_entity_decode((string) ($rec['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($relName !== '' && ($k = title_key($relName)) !== null) {
                db_insert_ignore('title_keys', ['k' => $k, 'title_id' => $tid]); // the name groups use
            }
            $groups = array_filter(array_map(fn ($g) => html_entity_decode((string) ($g['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $rec['groups'] ?? []));
            $src = db_one("SELECT url FROM title_sources WHERE connector = 'mangaupdates' AND title_id = ?", [$tid]);
            if ($t && title_add_chapter($tid, 'mangaupdates', $num, null, feed_date($rec['release_date'] ?? null) ?? gmdate('Y-m-d H:i:s', $ts),
                (string) ($src['url'] ?? 'https://www.mangaupdates.com/series/' . $key), $groups ? str_limit(implode(', ', $groups), 255) : null, false)) {
                $out['new']++;
            }
        }
        $cursor = $ts;
    }
    $state['cursor'] = $cursor;
    return $out;
}

function cx_mangaupdates_test(): array
{
    $api = rtrim((string) config('mangaupdates_api'), '/');
    $data = api_call('MangaUpdates', "$api/releases/days?include_metadata=true&page=1");
    $res = $data['results'] ?? [];
    if (!$res) {
        return [['error', 'MangaUpdates answered but listed no releases today.']];
    }
    $ex = array_map(fn ($r) => ($r['metadata']['series']['title'] ?? $r['record']['title'] ?? '?') . ' – Chapter ' . ($r['record']['chapter'] ?? '?'), array_slice($res, 0, 3));
    $lines = [['ok', 'MangaUpdates answered: ' . count($res) . ' release(s) today. For example: ' . implode('; ', $ex) . '.']];
    $sid = $res[0]['metadata']['series']['series_id'] ?? null;
    if ($sid && ($s = api_call('MangaUpdates', "$api/series/$sid", null, 30 * 86400)) && ($info = cx_mu_title($s))) {
        $lines[] = ['ok', "Series details work: {$info['title']} is " . ($info['type'] ?? 'an unknown type') . '. Only ' . implode(' and ', (array) config('discover_types')) . ' are added.'];
    }
    $lines[] = ['info', 'MangaUpdates has no reading links. Its chapters link to the series page, and are replaced by a readable link when another source has the same chapter.'];
    return $lines;
}

// ---------------------------------------------------------------- AniList

const ANILIST_FIELDS = 'id siteUrl countryOfOrigin status averageScore genres synonyms isAdult
  title { romaji english native } startDate { year } externalLinks { site url type language }';

const ANILIST_READ_SITES = ['webtoon', 'webtoons', 'line webtoon', 'tapas', 'tappytoon', 'lezhin', 'manta', 'pocket comics', 'bilibili comics', 'bilibili manga',
    'comikey', 'inkr', 'kakaopage', 'kakao webtoon', 'naver webtoon', 'naver series', 'manga plus', 'azuki', 'k manga', 'kodansha us', 'yen press', 'tencent comics', 'kuaikan', 'webnovel', 'mangamo'];

function cx_anilist_title(array $m): ?array
{
    if (empty($m['id']) || !preg_match('#^https://anilist\.co/#', (string) ($m['siteUrl'] ?? '')) || !empty($m['isAdult'])) {
        return null;
    }
    $t = $m['title'] ?? [];
    $names = array_values(array_filter([$t['english'] ?? null, $t['romaji'] ?? null, $t['native'] ?? null]));
    if (!$names) {
        return null;
    }
    $links = [['name' => 'AniList', 'url' => $m['siteUrl'], 'kind' => 'info']];
    foreach ($m['externalLinks'] ?? [] as $l) {
        $site = strtolower((string) ($l['site'] ?? ''));
        $lang = strtolower((string) ($l['language'] ?? ''));
        if (in_array($site, ANILIST_READ_SITES, true) && in_array($lang, ['', 'english'], true)) {
            $links[] = ['name' => (string) $l['site'] . ' (official)', 'url' => (string) $l['url'], 'kind' => 'read'];
        }
    }
    return [
        'connector' => 'anilist', 'external_id' => (string) $m['id'], 'url' => $m['siteUrl'],
        'title' => $names[0], 'alt_titles' => array_merge(array_slice($names, 1), $m['synonyms'] ?? []),
        'type' => ['CN' => 'manhua', 'TW' => 'manhua', 'HK' => 'manhua', 'KR' => 'manhwa', 'JP' => 'manga'][$m['countryOfOrigin'] ?? ''] ?? null,
        'status' => ['FINISHED' => 'completed', 'RELEASING' => 'ongoing', 'HIATUS' => 'hiatus', 'CANCELLED' => 'cancelled'][$m['status'] ?? ''] ?? null,
        'year' => $m['startDate']['year'] ?? null, 'genres' => array_map('slugify', $m['genres'] ?? []),
        'rating' => is_numeric($m['averageScore'] ?? null) ? round($m['averageScore'] / 20, 1) : null,
        'links' => $links, 'data' => [],
    ];
}

function cx_anilist_query(string $query, array $vars, int $cacheSeconds = 7 * 86400): array
{
    $wait = (int) (meta_get('anilist_wait_until') ?? 0);
    if ($wait > time()) {
        throw new ApiRateLimited('AniList asked us to slow down.', $wait);
    }
    try {
        $r = api_call('AniList', rtrim((string) config('anilist_api'), '/') . '/', ['query' => $query, 'variables' => $vars], $cacheSeconds);
    } catch (ApiRateLimited $e) {
        meta_set('anilist_wait_until', (string) $e->retryAt);
        throw $e;
    }
    if (!empty($r['errors'])) {
        throw new RuntimeException('AniList: ' . ($r['errors'][0]['message'] ?? 'unknown error'));
    }
    $media = $r['data']['Page']['media'] ?? [];
    return is_array($media) ? $media : [];
}

function cx_anilist_run(array &$state): array
{
    $out = ['new' => 0, 'titles' => 0, 'linked' => 0];
    // 1. Titles whose AniList id is already known (MangaDex lists it): one batch request.
    $ids = [];
    foreach (db_all("SELECT k, title_id FROM title_keys WHERE k LIKE 'id:anilist:%' LIMIT 500") as $row) {
        $id = substr($row['k'], 11);
        if (ctype_digit($id) && !db_one("SELECT 1 FROM title_sources WHERE connector = 'anilist' AND external_id = ?", [$id]) && !isset($state['tried']['a' . $id])) {
            $ids[] = (int) $id;
        }
        if (count($ids) >= 25) {
            break;
        }
    }
    if ($ids) {
        foreach (cx_anilist_query('query ($ids: [Int]) { Page(perPage: 25) { media(id_in: $ids, type: MANGA) { ' . ANILIST_FIELDS . ' } } }', ['ids' => $ids]) as $m) {
            if ($info = cx_anilist_title($m)) {
                [$tid] = title_save($info);
                $out['linked'] += $tid ? 1 : 0;
            }
        }
        foreach ($ids as $id) {
            $state['tried']['a' . $id] = time();
        }
    }
    // 2. A few titles without an AniList id: search by name, keep exact name matches only.
    foreach (titles_needing('anilist', $state, (int) config('enrich_per_run')) as $t) {
        mark_tried($state, (int) $t['id']);
        foreach (cx_anilist_query('query ($q: String) { Page(perPage: 5) { media(search: $q, type: MANGA) { ' . ANILIST_FIELDS . ' } } }', ['q' => $t['title']]) as $m) {
            $info = cx_anilist_title($m);
            if ($info && names_match(title_names($t), array_merge([$info['title']], $info['alt_titles']))) {
                [$tid] = title_save($info);
                $out['linked'] += $tid ? 1 : 0;
                break;
            }
        }
    }
    // 3. Once a day: trending manhwa and manhua.
    if (($state['trending_day'] ?? '') !== gmdate('Y-m-d')) {
        foreach (['KR' => 'manhwa', 'CN' => 'manhua'] as $country => $type) {
            if (!wanted_type($type)) {
                continue;
            }
            foreach (cx_anilist_query('query ($c: CountryCode) { Page(perPage: 15) { media(type: MANGA, countryOfOrigin: $c, isAdult: false, sort: TRENDING_DESC) { ' . ANILIST_FIELDS . ' } } }', ['c' => $country], 6 * 3600) as $m) {
                if (($info = cx_anilist_title($m)) && !adult_genre($info['genres'])) {
                    [$tid, $created] = title_save($info + ['create' => true]);
                    $out['titles'] += $created ? 1 : 0;
                }
            }
        }
        $state['trending_day'] = gmdate('Y-m-d');
    }
    return $out;
}

function cx_anilist_test(): array
{
    $media = cx_anilist_query('query ($c: CountryCode) { Page(perPage: 3) { media(type: MANGA, countryOfOrigin: $c, isAdult: false, sort: TRENDING_DESC) { ' . ANILIST_FIELDS . ' } } }', ['c' => 'KR'], 0);
    $ex = array_filter(array_map(fn ($m) => ($i = cx_anilist_title($m)) ? $i['title'] . ' (' . count(array_filter($i['links'], fn ($l) => $l['kind'] === 'read')) . ' official reading link(s))' : null, $media));
    return $ex ? [['ok', 'AniList answered. Trending manhwa: ' . implode('; ', $ex) . '.'], ['info', 'AniList has series details only, no chapters.']]
        : [['error', 'AniList answered without any titles.']];
}

// ---------------------------------------------------------------- Bangumi

function cx_bangumi_search(string $name): array
{
    $api = rtrim((string) config('bangumi_api'), '/');
    $r = api_call('Bangumi', "$api/search/subject/" . rawurlencode($name) . '?type=1&responseGroup=small&max_results=10', null, 7 * 86400);
    return is_array($r['list'] ?? null) ? $r['list'] : [];
}

function cx_bangumi_run(array &$state): array
{
    $out = ['new' => 0, 'titles' => 0, 'linked' => 0];
    foreach (titles_needing('bangumi', $state, (int) config('enrich_per_run'), 'manhua') as $t) {
        mark_tried($state, (int) $t['id']);
        $cjk = array_values(array_filter(title_names($t), fn ($n) => preg_match('/\p{Han}/u', $n)));
        if (!$cjk) {
            continue; // Bangumi is searched by Chinese name
        }
        foreach (cx_bangumi_search($cjk[0]) as $s) {
            $names = array_filter([(string) ($s['name'] ?? ''), (string) ($s['name_cn'] ?? '')]);
            if (empty($s['id']) || !names_match($cjk, $names)) {
                continue;
            }
            [$tid] = title_save([
                'connector' => 'bangumi', 'external_id' => (string) (int) $s['id'], 'url' => 'https://bgm.tv/subject/' . (int) $s['id'],
                'title' => $t['title'], 'alt_titles' => $names, 'cross_ids' => [], 'genres' => [],
                'links' => [['name' => 'Bangumi', 'url' => 'https://bgm.tv/subject/' . (int) $s['id'], 'kind' => 'info']],
                'data' => ['name' => $s['name'] ?? null, 'name_cn' => $s['name_cn'] ?? null],
            ]);
            $out['linked'] += $tid ? 1 : 0;
            break;
        }
    }
    return $out;
}

function cx_bangumi_test(): array
{
    $list = cx_bangumi_search('斗罗大陆');
    if (!$list) {
        return [['error', 'Bangumi answered but found nothing for 斗罗大陆 (Soul Land).']];
    }
    return [['ok', 'Bangumi answered: ' . implode('; ', array_map(fn ($s) => ($s['name_cn'] ?: $s['name']) . ' (bgm.tv/subject/' . (int) $s['id'] . ')', array_slice($list, 0, 3))) . '.'],
        ['info', 'Bangumi only matches manhua that have a Chinese name, which MangaDex and AniList usually provide.']];
}

// ---------------------------------------------------------------- Anime News Network

/** Parses an ANN api.xml answer into [{id, names[], genres[], year, links[]}]. */
function cx_ann_parse(string $xml): array
{
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($doc === false) {
        throw new RuntimeException('Anime News Network sent something that isn\'t valid XML.');
    }
    $out = [];
    foreach ($doc->manga as $m) {
        $id = (int) $m['id'];
        if ($id <= 0) {
            continue;
        }
        $e = ['id' => $id, 'names' => [(string) $m['name']], 'genres' => [], 'year' => null, 'links' => []];
        foreach ($m->info as $info) {
            $type = (string) $info['type'];
            $val = trim((string) $info);
            if ($type === 'Main title' || $type === 'Alternative title') {
                $e['names'][] = $val;
            } elseif ($type === 'Genres' || $type === 'Themes') {
                $e['genres'][] = slugify($val);
            } elseif ($type === 'Vintage' && $e['year'] === null && preg_match('/\b(19|20)\d\d\b/', $val, $y)) {
                $e['year'] = (int) $y[0];
            } elseif ($type === 'Official website' && preg_match('#^https?://#', (string) $info['href'])) {
                $e['links'][] = ['name' => $val !== '' ? str_limit($val, 50) : 'Official website', 'url' => (string) $info['href'], 'kind' => 'info'];
            }
        }
        $e['names'] = array_values(array_unique(array_filter($e['names'])));
        $out[] = $e;
    }
    return $out;
}

function cx_ann_lookup(string $name): array
{
    $api = rtrim((string) config('ann_api'), '/');
    $xml = api_call('Anime News Network', "$api/api.xml?manga=~" . rawurlencode($name), null, 7 * 86400, true);
    return $xml ? cx_ann_parse($xml) : [];
}

function cx_ann_run(array &$state): array
{
    $out = ['new' => 0, 'titles' => 0, 'linked' => 0];
    foreach (titles_needing('ann', $state, (int) config('enrich_per_run')) as $t) {
        mark_tried($state, (int) $t['id']);
        foreach (cx_ann_lookup($t['title']) as $e) {
            if (!names_match(title_names($t), $e['names'])) {
                continue;
            }
            $url = 'https://www.animenewsnetwork.com/encyclopedia/manga.php?id=' . $e['id'];
            [$tid] = title_save([
                'connector' => 'ann', 'external_id' => (string) $e['id'], 'url' => $url,
                'title' => $t['title'], 'alt_titles' => $e['names'], 'year' => $e['year'], 'genres' => $e['genres'],
                'links' => array_merge([['name' => 'Anime News Network', 'url' => $url, 'kind' => 'info']], $e['links']),
                'data' => [],
            ]);
            $out['linked'] += $tid ? 1 : 0;
            break;
        }
    }
    return $out;
}

function cx_ann_test(): array
{
    $found = cx_ann_lookup('Solo Leveling');
    if (!$found) {
        return [['error', 'Anime News Network answered but found nothing for "Solo Leveling".']];
    }
    $e = $found[0];
    return [['ok', 'Anime News Network answered: ' . $e['names'][0] . ' (' . ($e['year'] ?? 'year unknown') . ', ' . count($e['genres']) . ' genre(s), ' . count($e['links']) . ' official link(s)).'],
        ['info', 'ANN has series details only, no chapters.']];
}
