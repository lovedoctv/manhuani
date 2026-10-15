<?php
defined('MANHUANI') || exit;

/*
 * The titles catalogue. Every connector saves what it finds through title_save(),
 * which matches it to an existing title before creating a new one:
 *   1. the same id on the same site (title_sources),
 *   2. a cross-site id (MangaDex lists a title's AniList and MangaUpdates ids),
 *   3. the same normalised name or alternative name (title_keys).
 * Chapters go through title_add_chapter(), keyed by title and chapter number, so
 * a chapter reported by several sources is stored once.
 */

/** "Solo Leveling!" -> "solo-leveling"; names without latin letters keep their own script. */
function title_key(string $name): ?string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        return null;
    }
    $slug = slugify($name);
    if (strlen($slug) >= 2 && !preg_match('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}]/u', $name)) {
        return $slug;
    }
    if (preg_match('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}]/u', $name)) {
        return 'n:' . mb_substr(mb_strtolower(preg_replace('/[\s\p{P}]+/u', '', $name) ?? ''), 0, 120);
    }
    return null;
}

function title_id_key(string $connector, string $id): string
{
    return 'id:' . $connector . ':' . strtolower($id);
}

/** Finds the title a set of names belongs to, or null. */
function title_find_by_names(array $names): ?int
{
    foreach ($names as $n) {
        $k = title_key((string) $n);
        if ($k !== null && ($row = db_one('SELECT title_id FROM title_keys WHERE k = ?', [$k]))) {
            return (int) $row['title_id'];
        }
    }
    return null;
}

function title_get(int $id): ?array
{
    return db_one('SELECT * FROM titles WHERE id = ?', [$id]);
}

/**
 * Saves what a connector knows about a title and returns [title id, created?].
 * Returns [0, false] when nothing matches and $t['create'] is false.
 *
 * $t keys: connector, external_id, url, title, alt_titles[], type, status, year,
 *          genres[], rating (0-5), links[] ({name,url,kind}), cross_ids{connector: id},
 *          data{}, create (bool)
 */
function title_save(array $t): array
{
    $connector = (string) $t['connector'];
    $extId = strtolower((string) $t['external_id']);
    $names = array_values(array_filter(array_map(fn ($n) => str_limit((string) $n, 255), array_merge([$t['title'] ?? ''], $t['alt_titles'] ?? [])), fn ($n) => $n !== ''));
    if (!$names) {
        return [0, false];
    }

    $titleId = null;
    if ($row = db_one('SELECT title_id FROM title_sources WHERE connector = ? AND external_id = ?', [$connector, $extId])) {
        $titleId = (int) $row['title_id'];
    }
    if ($titleId === null) {
        foreach ($t['cross_ids'] ?? [] as $c => $cid) {
            if ($cid !== null && $cid !== '' && ($row = db_one('SELECT title_id FROM title_keys WHERE k = ?', [title_id_key($c, (string) $cid)]))) {
                $titleId = (int) $row['title_id'];
                break;
            }
        }
    }
    if ($titleId === null) {
        $titleId = title_find_by_names($names);
    }
    if ($titleId !== null && !title_get($titleId)) {
        $titleId = null; // stale key
    }

    $created = false;
    if ($titleId === null) {
        if (empty($t['create'])) {
            return [0, false];
        }
        $base = slugify($names[0]) ?: slugify((string) ($t['alt_titles'][0] ?? '')) ?: $connector . '-' . substr(sha1($extId), 0, 8);
        $base = substr($base, 0, 180);
        $slug = $base;
        for ($i = 2; db_one('SELECT 1 FROM titles WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        db_run('INSERT INTO titles (slug, title, alt_titles, type, status, year, genres, rating, links, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $slug, $names[0], json_encode(array_slice(array_values(array_diff(array_unique($names), [$names[0]])), 0, 15), JSON_UNESCAPED_UNICODE),
            $t['type'] ?? null, $t['status'] ?? null, !empty($t['year']) ? (int) $t['year'] : null,
            json_encode(array_slice(array_values(array_unique($t['genres'] ?? [])), 0, 12)), isset($t['rating']) ? round((float) $t['rating'], 1) : null,
            json_encode(title_clean_links($t['links'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now(), now(),
        ]);
        $titleId = (int) db()->lastInsertId();
        $created = true;
        db_insert_ignore('title_keys', ['k' => $slug, 'title_id' => $titleId]);
    } else {
        title_merge($titleId, $t, $names);
    }

    // Remember every name and id so later finds match this title.
    foreach ($names as $n) {
        if (($k = title_key($n)) !== null) {
            db_insert_ignore('title_keys', ['k' => $k, 'title_id' => $titleId]);
        }
    }
    db_insert_ignore('title_keys', ['k' => title_id_key($connector, $extId), 'title_id' => $titleId]);
    foreach ($t['cross_ids'] ?? [] as $c => $cid) {
        if ($cid !== null && $cid !== '') {
            db_insert_ignore('title_keys', ['k' => title_id_key($c, (string) $cid), 'title_id' => $titleId]);
        }
    }

    $data = json_encode($t['data'] ?? new stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (db_one('SELECT 1 FROM title_sources WHERE connector = ? AND external_id = ?', [$connector, $extId])) {
        db_run('UPDATE title_sources SET title_id = ?, url = ?, data = ?, fetched_at = ? WHERE connector = ? AND external_id = ?',
            [$titleId, $t['url'] ?? null, $data, now(), $connector, $extId]);
    } else {
        db_run('INSERT INTO title_sources (title_id, connector, external_id, url, data, fetched_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$titleId, $connector, $extId, $t['url'] ?? null, $data, now()]);
    }
    return [$titleId, $created];
}

/** Fills in what a title is missing; never overwrites its name or slug. */
function title_merge(int $id, array $t, array $names): void
{
    $cur = title_get($id);
    $alt = json_decode((string) $cur['alt_titles'], true) ?: [];
    foreach ($names as $n) {
        if ($n !== $cur['title'] && !in_array($n, $alt, true)) {
            $alt[] = $n;
        }
    }
    $genres = array_values(array_unique(array_merge(json_decode((string) $cur['genres'], true) ?: [], $t['genres'] ?? [])));
    $links = title_clean_links(array_merge(json_decode((string) $cur['links'], true) ?: [], $t['links'] ?? []));
    db_run('UPDATE titles SET alt_titles = ?, type = COALESCE(type, ?), status = COALESCE(?, status), year = COALESCE(year, ?), genres = ?, rating = COALESCE(?, rating), links = ?, updated_at = ? WHERE id = ?', [
        json_encode(array_slice($alt, 0, 15), JSON_UNESCAPED_UNICODE), $t['type'] ?? null, $t['status'] ?? null,
        !empty($t['year']) ? (int) $t['year'] : null, json_encode(array_slice($genres, 0, 12)),
        isset($t['rating']) ? round((float) $t['rating'], 1) : null,
        json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now(), $id,
    ]);
}

/** Keeps http(s) links only, one per address. kind: read (official reading page) or info. */
function title_clean_links(array $links): array
{
    $out = [];
    foreach ($links as $l) {
        $url = (string) ($l['url'] ?? '');
        if (!preg_match('#^https?://[^\s"<>]+$#i', $url) || isset($out[$url])) {
            continue;
        }
        $out[$url] = ['name' => str_limit((string) ($l['name'] ?? parse_url($url, PHP_URL_HOST)), 60), 'url' => str_limit($url, 500), 'kind' => ($l['kind'] ?? '') === 'read' ? 'read' : 'info'];
    }
    return array_slice(array_values($out), 0, 20);
}

/**
 * Stores one chapter of a title. If the chapter is already known only from a source
 * without a reading link (MangaUpdates), a readable link replaces it.
 * @return bool true when it is new
 */
function title_add_chapter(int $titleId, string $connector, float $number, ?string $label, ?string $date, string $url, ?string $credit, bool $readable, int $sourceId = 0): bool
{
    $t = title_get($titleId);
    if (!$t || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    $dedupe = sha1($t['slug'] . '|' . format_chapter($number));
    $inserted = db_insert_ignore('updates', [
        'source_id' => $sourceId,
        'series_title' => $t['title'],
        'series_slug' => $t['slug'],
        'chapter_number' => $number,
        'chapter_label' => $label,
        'release_date' => $date,
        'source_url' => str_limit($url, 500),
        'credit' => $credit,
        'title_id' => $titleId,
        'connector' => $connector === '' ? null : $connector,
        'readable' => $readable ? 1 : 0,
        'dedupe_key' => $dedupe,
        'created_at' => now(),
    ]);
    if (!$inserted && $readable) {
        $upgraded = db_run('UPDATE updates SET source_url = ?, credit = ?, connector = ?, source_id = ?, readable = 1, chapter_label = COALESCE(chapter_label, ?), release_date = COALESCE(?, release_date) WHERE dedupe_key = ? AND readable = 0',
            [str_limit($url, 500), $credit, $connector === '' ? null : $connector, $sourceId, $label, $date, $dedupe]);
        if ($upgraded > 0) {
            meta_set('needs_publish', '1'); // a chapter got a reading link
        }
    }
    if ($inserted && ($t['latest_chapter'] === null || $number >= (float) $t['latest_chapter'])) {
        db_run('UPDATE titles SET latest_chapter = ?, latest_chapter_at = ?, updated_at = ? WHERE id = ?', [$number, $date ?? now(), now(), $titleId]);
    }
    return $inserted;
}

/** Content types the site wants, e.g. ['manhua', 'manhwa']. */
function wanted_type(?string $type): bool
{
    $want = (array) config('discover_types');
    return $type !== null && in_array($type, $want, true);
}

/** Genres that mark adult-only titles, which are never added to the site. */
function adult_genre(array $genres): bool
{
    foreach ($genres as $g) {
        if (in_array(slugify((string) $g), ['adult', 'hentai', 'smut', 'pornographic', 'erotica'], true)) {
            return true;
        }
    }
    return false;
}
