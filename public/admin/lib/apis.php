<?php
defined('MANHUANI') || exit;

/*
 * Official APIs that are published for programmatic access.
 *
 * MangaDex (https://api.mangadex.org/docs/)
 *   - Chapter releases for one title per source. Metadata and links only.
 *   - Terms: credit MangaDex and the scanlation groups, no ads or paid services
 *     on the site, honor removal requests. About 5 requests/second per IP is the
 *     hard limit; we send far fewer (one request per check, 3+ seconds apart).
 *
 * AniList (https://docs.anilist.co/)
 *   - Series information looked up by hand from the dashboard (never stored or
 *     collected in bulk). Free for non-commercial use. 90 requests/minute.
 *
 * Neither API is used to download chapter pages or cover images.
 */

const MANGADEX_UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

/** "https://mangadex.org/title/<uuid>/any-name" -> "<uuid>", or null for other addresses. */
function mangadex_id_from_url(string $url): ?string
{
    $p = parse_url($url);
    $host = strtolower($p['host'] ?? '');
    if (!in_array($host, ['mangadex.org', 'www.mangadex.org'], true)) {
        return null;
    }
    return preg_match('#^/title/(' . MANGADEX_UUID . ')(/|$)#i', $p['path'] ?? '', $m) ? strtolower($m[1]) : null;
}

function is_mangadex_url(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return in_array($host, ['mangadex.org', 'www.mangadex.org'], true);
}

function mangadex_api(): string
{
    return rtrim((string) config('mangadex_api'), '/');
}

function mangadex_language(): string
{
    $lang = strtolower((string) config('mangadex_language'));
    return preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $lang) ? $lang : 'en';
}

/** Address of a title's chapter feed: newest first, one language, with group names. */
function mangadex_feed_url(string $mangaId, int $limit): string
{
    $q = [
        'translatedLanguage%5B%5D=' . rawurlencode(mangadex_language()),
        'order%5BreadableAt%5D=desc',
        'limit=' . max(1, min(100, $limit)),
        'includes%5B%5D=scanlation_group',
        'contentRating%5B%5D=safe',
        'contentRating%5B%5D=suggestive',
        'contentRating%5B%5D=erotica',
    ];
    return mangadex_api() . '/manga/' . $mangaId . '/feed?' . implode('&', $q);
}

/**
 * One GET to the MangaDex API.
 * @return array{data:?array, status:int, error:?string, retry_at:?int}
 */
function mangadex_get(string $url): array
{
    $res = Http::get($url, ['Accept: application/json'], 0);
    return api_result($res, 'MangaDex');
}

/** Turns an API response into data or a readable error, including rate-limit waits. */
function api_result(array $res, string $name): array
{
    $out = ['data' => null, 'status' => $res['status'], 'error' => null, 'retry_at' => null];
    if ($res['error']) {
        $out['error'] = $res['error'];
        return $out;
    }
    if ($res['status'] === 429 || $res['status'] === 503) {
        // MangaDex sends X-RateLimit-Retry-After (a Unix time); others send Retry-After (seconds).
        $at = (int) ($res['headers']['x-ratelimit-retry-after'] ?? 0);
        $after = (int) ($res['headers']['retry-after'] ?? 0);
        $out['retry_at'] = max($at, time() + max($after, 60));
        $out['error'] = "$name asked us to slow down (HTTP {$res['status']}).";
        return $out;
    }
    $json = json_decode($res['body'], true);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $detail = is_array($json) ? ($json['errors'][0]['detail'] ?? $json['errors'][0]['message'] ?? '') : '';
        $out['error'] = "$name answered with HTTP {$res['status']}" . ($detail ? ": $detail" : '.')
            . ($res['status'] === 403 ? " $name may have paused access from this server for a while." : '');
        return $out;
    }
    if (!is_array($json)) {
        $out['error'] = "$name sent something that isn't valid JSON.";
        return $out;
    }
    $out['data'] = $json;
    return $out;
}

/**
 * The series title for a MangaDex id. The chapter feed doesn't include it, so it is
 * read from /manga/{id} once and remembered (the test refreshes it).
 */
function mangadex_series_title(string $mangaId, bool $refresh = false): array
{
    $key = 'mangadex_title:' . $mangaId;
    $known = $refresh ? null : meta_get($key);
    if ($known !== null && $known !== '') {
        return ['title' => $known, 'attrs' => null, 'error' => null, 'status' => 200, 'retry_at' => null];
    }
    $r = mangadex_get(mangadex_api() . '/manga/' . $mangaId);
    $attrs = $r['data']['data']['attributes'] ?? [];
    $title = $r['error'] ? '' : mangadex_title(is_array($attrs) ? $attrs : []);
    if ($title !== '') {
        meta_set($key, $title);
    } elseif (!$r['error']) {
        $r['error'] = 'MangaDex returned this title without a name.';
    }
    return ['title' => $title, 'attrs' => $attrs, 'error' => $r['error'], 'status' => $r['status'], 'retry_at' => $r['retry_at']];
}

/** Picks the English title (or the first one) from a MangaDex title map. */
function mangadex_title(array $attrs): string
{
    $titles = is_array($attrs['title'] ?? null) ? $attrs['title'] : [];
    $pick = $titles['en'] ?? null;
    if (!$pick) {
        foreach ($attrs['altTitles'] ?? [] as $alt) {
            if (is_array($alt) && !empty($alt['en'])) {
                $pick = $alt['en'];
                break;
            }
        }
    }
    $pick = $pick ?: (reset($titles) ?: '');
    return str_limit((string) $pick, 255);
}

/**
 * Turns MangaDex chapter objects into updates for the series $series. Chapters
 * without a number (oneshots) and unavailable chapters are skipped.
 * @return list<array{series:string, number:float, label:?string, date:?string, url:string, credit:?string}>
 */
function mangadex_chapters(array $data, string $series): array
{
    $out = [];
    foreach ($data['data'] ?? [] as $ch) {
        if (!is_array($ch) || ($ch['type'] ?? '') !== 'chapter' || !preg_match('/^' . MANGADEX_UUID . '$/i', (string) ($ch['id'] ?? ''))) {
            continue;
        }
        $a = $ch['attributes'] ?? [];
        $num = $a['chapter'] ?? null;
        if ($num === null || !is_numeric($num) || !empty($a['isUnavailable'])) {
            continue;
        }
        $groups = [];
        foreach ($ch['relationships'] ?? [] as $rel) {
            if (($rel['type'] ?? '') === 'scanlation_group' && !empty($rel['attributes']['name'])) {
                $groups[] = (string) $rel['attributes']['name'];
            }
        }
        if ($series === '') {
            continue;
        }
        $label = trim((string) ($a['title'] ?? ''));
        $out[] = [
            'series' => $series,
            'number' => (float) $num,
            'label' => $label !== '' ? str_limit($label, 255) : null,
            'date' => feed_date($a['readableAt'] ?? $a['publishAt'] ?? null),
            'url' => 'https://mangadex.org/chapter/' . strtolower($ch['id']),
            'credit' => $groups ? str_limit(implode(', ', array_unique($groups)), 255) : null,
        ];
    }
    return $out;
}

/** "Test" for a MangaDex source. Saves what it finds; never enables the source. */
function mangadex_test(array $s): array
{
    $lines = [];
    $add = function (string $level, string $text) use (&$lines) { $lines[] = [$level, $text]; };
    $id = mangadex_id_from_url($s['url']);
    $feedUrl = null;
    if ($id === null) {
        $add('error', 'This is a MangaDex address, but not a title page. Open the series on mangadex.org and paste its address, which looks like https://mangadex.org/title/…');
    } else {
        $add('ok', 'MangaDex has an official API for programmatic access. robots.txt is for web pages, so the API\'s own rules apply instead.');
        $manga = mangadex_series_title($id, true);
        if ($manga['error']) {
            $add('error', $manga['status'] === 404 ? 'MangaDex has no title with that address.' : $manga['error']);
        } else {
            $attrs = $manga['attrs'];
            $title = $manga['title'];
            $add('ok', 'Title found: ' . $title . (isset($attrs['status']) ? ' (' . $attrs['status'] . ')' : '') . '.');
            $feed = mangadex_get(mangadex_feed_url($id, 20));
            if ($feed['error']) {
                $add('error', $feed['error']);
            } else {
                $chapters = mangadex_chapters($feed['data'], $title);
                $total = (int) ($feed['data']['total'] ?? count($chapters));
                if (!$chapters) {
                    $add('error', 'MangaDex lists no numbered chapters for this title in language "' . mangadex_language() . '". '
                        . 'Some titles only link to official publishers. Change mangadex_language in config.php if you follow another language.');
                } else {
                    $feedUrl = mangadex_api() . '/manga/' . $id . '/feed';
                    $ex = array_map(fn ($c) => $c['series'] . ' – Chapter ' . format_chapter($c['number']) . ($c['credit'] ? ' by ' . $c['credit'] : ''), array_slice($chapters, 0, 3));
                    $add('ok', "MangaDex API ready: $total chapter(s) in \"" . mangadex_language() . '". For example: ' . implode('; ', $ex) . '.');
                    $add('info', 'Each update is credited to MangaDex and its scanlation group on your site, and links to the chapter on mangadex.org. No pages or images are copied.');
                    $add('warning', 'MangaDex\'s terms: your site must not run ads or paid services while it uses their API. Hide or delete updates if a group asks you to.');
                }
            }
        }
    }
    $ok = $feedUrl !== null;
    db_run('UPDATE sources SET feed_url = ?, method = ?, robots_status = ?, crawl_delay = 0, check_summary = ?, last_checked_at = ?, updated_at = ?' . ($ok ? '' : ', enabled = 0') . ' WHERE id = ?',
        [$feedUrl, $ok ? 'mangadex' : null, $ok ? 'api' : 'unknown', json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now(), now(), $s['id']]);
    log_event($ok ? 'info' : 'warning', 'Test ' . ($ok ? 'passed' : 'did not pass') . " for {$s['name']}", (int) $s['id']);
    return ['ok' => $ok, 'lines' => $lines];
}

/** Scheduled check of a MangaDex source. @return int number of new updates */
function mangadex_crawl(array $s): int
{
    $id = mangadex_id_from_url($s['url']);
    if ($id === null) {
        throw new RuntimeException('The address is no longer a MangaDex title page. Test the source again.');
    }
    $t = mangadex_series_title($id);
    $r = $t['error'] ? $t : mangadex_get(mangadex_feed_url($id, (int) config('max_items_per_feed')));
    if ($r['retry_at'] !== null) {
        $next = min(max($r['retry_at'], time() + 3600), time() + 86400);
        db_run('UPDATE sources SET last_checked_at = ?, next_check_at = ?, last_error = ? WHERE id = ?',
            [now(), gmdate('Y-m-d H:i:s', $next), $r['error'], $s['id']]);
        log_warn("{$s['name']}: {$r['error']} Next check in " . round(($next - time()) / 60) . ' min.', (int) $s['id']);
        return 0;
    }
    if ($r['error']) {
        throw new RuntimeException($r['error']);
    }
    $new = 0;
    foreach (mangadex_chapters($r['data'], $t['title']) as $c) {
        $slug = slugify($c['series']);
        if ($slug === '') {
            continue;
        }
        $inserted = db_insert_ignore('updates', [
            'source_id' => (int) $s['id'],
            'series_title' => $c['series'],
            'series_slug' => $slug,
            'chapter_number' => $c['number'],
            'chapter_label' => $c['label'],
            'release_date' => $c['date'],
            'source_url' => $c['url'],
            'credit' => $c['credit'],
            'dedupe_key' => sha1($slug . '|' . format_chapter($c['number'])),
            'created_at' => now(),
        ]);
        if ($inserted) {
            $new++;
        }
    }
    source_succeeded($s, null, null);
    if ($new) {
        log_info("{$s['name']}: $new new update(s).", (int) $s['id']);
    }
    return $new;
}

// ---------------------------------------------------------------- AniList

const ANILIST_QUERY = 'query ($search: String) { Page(perPage: 5) { media(search: $search, type: MANGA, sort: SEARCH_MATCH, isAdult: false) {
  id siteUrl countryOfOrigin status averageScore genres synonyms
  title { romaji english native }
  startDate { year }
  description(asHtml: false)
  staff(perPage: 6, sort: RELEVANCE) { edges { role node { name { full } } } }
} } }';

/**
 * Searches AniList for a series. On-demand only: results are shown once and
 * never stored, as AniList's terms forbid using it as a data store.
 * @return array{results:list<array>, error:?string}
 */
function anilist_search(string $search): array
{
    $search = str_limit($search, 100);
    if (mb_strlen($search) < 2) {
        return ['results' => [], 'error' => 'Type at least 2 letters.'];
    }
    $wait = (int) (meta_get('anilist_wait_until') ?? 0);
    if ($wait > time()) {
        return ['results' => [], 'error' => 'AniList asked us to slow down. Try again in ' . max(1, (int) ceil(($wait - time()) / 60)) . ' min.'];
    }
    $url = rtrim((string) config('anilist_api'), '/') . '/';
    $r = api_result(Http::postJson($url, ['query' => ANILIST_QUERY, 'variables' => ['search' => $search]]), 'AniList');
    if ($r['retry_at'] !== null) {
        meta_set('anilist_wait_until', (string) $r['retry_at']);
        log_warn('AniList: ' . $r['error']);
    }
    if ($r['error']) {
        return ['results' => [], 'error' => $r['error']];
    }
    if (!empty($r['data']['errors'])) {
        return ['results' => [], 'error' => 'AniList: ' . ($r['data']['errors'][0]['message'] ?? 'unknown error')];
    }
    $media = $r['data']['data']['Page']['media'] ?? [];
    return ['results' => array_values(array_filter(array_map('anilist_to_series', is_array($media) ? $media : []))), 'error' => null];
}

/** Maps one AniList result to the shape of a src/content/series/*.json file. */
function anilist_to_series($m): ?array
{
    if (!is_array($m) || empty($m['siteUrl']) || !preg_match('#^https://anilist\.co/#', (string) $m['siteUrl'])) {
        return null;
    }
    $t = $m['title'] ?? [];
    $title = '';
    foreach (['english', 'romaji', 'native'] as $k) {
        if (!empty($t[$k])) {
            $title = trim((string) $t[$k]);
            break;
        }
    }
    if ($title === '') {
        return null;
    }
    $alt = array_values(array_unique(array_filter(array_map('strval', array_merge([$t['romaji'] ?? '', $t['native'] ?? ''], $m['synonyms'] ?? [])),
        fn ($x) => $x !== '' && $x !== $title)));
    $author = $artist = null;
    foreach ($m['staff']['edges'] ?? [] as $e) {
        $role = strtolower((string) ($e['role'] ?? ''));
        $name = (string) ($e['node']['name']['full'] ?? '');
        if ($name === '') {
            continue;
        }
        if ($author === null && (str_contains($role, 'story') || str_contains($role, 'original'))) {
            $author = $name;
        }
        if ($artist === null && str_contains($role, 'art')) {
            $artist = $name;
        }
    }
    $synopsis = (string) ($m['description'] ?? '');
    $synopsis = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $synopsis) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $synopsis = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $synopsis)) ?? '');

    $series = [
        'title' => $title,
        'altTitles' => array_slice($alt, 0, 6),
        'type' => ['CN' => 'manhua', 'TW' => 'manhua', 'KR' => 'manhwa'][$m['countryOfOrigin'] ?? ''] ?? 'manga',
        'genres' => array_values(array_filter(array_map('slugify', $m['genres'] ?? []))),
        'status' => ['FINISHED' => 'completed', 'HIATUS' => 'hiatus', 'CANCELLED' => 'cancelled'][$m['status'] ?? ''] ?? 'ongoing',
        'releaseYear' => (int) ($m['startDate']['year'] ?? 0) ?: (int) gmdate('Y'),
        'featured' => false,
        'synopsis' => $synopsis !== '' ? $synopsis : 'Synopsis coming soon.',
        'source' => ['name' => 'AniList', 'url' => (string) $m['siteUrl']],
        'chapters' => [],
    ];
    if ($author !== null) $series['author'] = $author;
    if ($artist !== null) $series['artist'] = $artist;
    if (isset($m['averageScore']) && is_numeric($m['averageScore'])) {
        $series['rating'] = round(((float) $m['averageScore']) / 20, 1);
    }
    return ['slug' => slugify($title), 'series' => $series];
}

/** Pretty JSON for pasting into src/content/series/<slug>.json. */
function series_json(array $series): string
{
    $order = ['title', 'altTitles', 'type', 'genres', 'author', 'artist', 'status', 'releaseYear', 'rating', 'featured', 'synopsis', 'source', 'chapters'];
    $sorted = [];
    foreach ($order as $k) {
        if (array_key_exists($k, $series)) {
            $sorted[$k] = $series[$k];
        }
    }
    return json_encode($sorted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
