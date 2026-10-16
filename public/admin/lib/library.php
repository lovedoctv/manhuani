<?php
defined('MANHUANI') || exit;

/*
 * Your own titles, chapters and page images (the dashboard's "Library"), and the pages
 * you upload for crawled chapters whose source gave you hosting permission.
 *
 * Images are checked (a real JPG, PNG, WEBP or GIF under the size limit), given a
 * random name and stored in the website's data/media/ folder. Only published
 * chapters of visible titles are listed in the site's data files, so draft pages
 * are never linked from the website.
 */

const LIBRARY_IMAGE_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
const LIBRARY_STATUSES = ['ongoing' => 'Ongoing', 'completed' => 'Completed', 'hiatus' => 'On hiatus', 'cancelled' => 'Cancelled'];
const LIBRARY_TYPES = ['manhua' => 'Manhua', 'manhwa' => 'Manhwa', 'manga' => 'Manga'];

function media_dir(): string
{
    return site_dir() . '/data/media';
}

/** Address of an uploaded image, relative to the website root. */
function media_url(string $file): string
{
    return 'data/media/' . $file;
}

function media_name_ok(?string $file): bool
{
    return is_string($file) && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $file) === 1;
}

/** The files of a multiple file input, sorted by their names (001.jpg, 002.jpg, …). */
function uploaded_files(array $field): array
{
    $out = [];
    foreach ((array) ($field['name'] ?? []) as $i => $name) {
        $out[] = [
            'name' => (string) $name,
            'tmp_name' => (string) (((array) ($field['tmp_name'] ?? []))[$i] ?? ''),
            'error' => (int) (((array) ($field['error'] ?? []))[$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) (((array) ($field['size'] ?? []))[$i] ?? 0),
        ];
    }
    usort($out, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return array_values(array_filter($out, fn ($f) => $f['error'] !== UPLOAD_ERR_NO_FILE));
}

/**
 * Checks one uploaded image and saves it under a new random name.
 * @return array{0:?array,1:?string} [['file','width','height','bytes'], error message]
 */
function store_image(array $f, int $maxBytes): array
{
    $label = str_limit(basename($f['name']), 80);
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, "$label is larger than your server allows (" . ini_get('upload_max_filesize') . ').'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return [null, "$label did not upload. Try again."];
    }
    if ($f['size'] > $maxBytes) {
        return [null, "$label is over " . round($maxBytes / 1048576, 1) . ' MB.'];
    }
    $info = getimagesize($f['tmp_name']);
    $ext = $info ? (LIBRARY_IMAGE_TYPES[$info[2]] ?? null) : null;
    if ($ext === null || $info[0] < 1 || $info[1] < 1 || $info[0] > 20000 || $info[1] > 60000) {
        return [null, "$label is not a JPG, PNG, WEBP or GIF image."];
    }
    $dir = media_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [null, 'Could not create the folder for images (data/media).'];
    }
    if (!is_file("$dir/index.html")) {
        file_put_contents("$dir/index.html", '');
    }
    $file = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$file")) {
        return [null, "$label could not be saved."];
    }
    return [['file' => $file, 'width' => (int) $info[0], 'height' => (int) $info[1], 'bytes' => (int) $f['size']], null];
}

function delete_media(?string $file): void
{
    if (media_name_ok($file) && is_file(media_dir() . '/' . $file)) {
        unlink(media_dir() . '/' . $file);
    }
}

// ---------- Titles ----------

function library_title(int $id): ?array
{
    return db_one("SELECT * FROM titles WHERE id = ? AND origin = 'own'", [$id]);
}

function library_titles(string $q = ''): array
{
    $where = "t.origin = 'own'" . ($q !== '' ? ' AND t.title LIKE ?' : '');
    return db_all("SELECT t.*, (SELECT COUNT(*) FROM chapters c WHERE c.title_id = t.id) AS chapter_count,
        (SELECT COUNT(*) FROM chapters c WHERE c.title_id = t.id AND c.status = 'published') AS published_count
        FROM titles t WHERE $where ORDER BY t.updated_at DESC", $q !== '' ? ['%' . $q . '%'] : []);
}

/** Splits "Action, Martial arts" into ['action', 'martial-arts']. */
function library_list(string $s, int $max, bool $slug): array
{
    $items = array_filter(array_map(fn ($x) => $slug ? slugify($x) : str_limit($x, 255), preg_split('/[,\n]+/', $s) ?: []), fn ($x) => $x !== '');
    return array_slice(array_values(array_unique($items)), 0, $max);
}

/**
 * Creates or updates one of your titles.
 * @return array{0:?int,1:?string} [title id, error message]
 */
function library_title_save(?int $id, array $in, array $cover): array
{
    $name = str_limit((string) ($in['title'] ?? ''), 255);
    if ($name === '') {
        return [null, 'Enter the title.'];
    }
    $old = $id ? library_title($id) : null;
    if ($id && !$old) {
        return [null, 'Title not found.'];
    }
    $type = array_key_exists($in['type'] ?? '', LIBRARY_TYPES) ? $in['type'] : null;
    $status = array_key_exists($in['status'] ?? '', LIBRARY_STATUSES) ? $in['status'] : null;
    $year = (int) ($in['year'] ?? 0);
    $alt = library_list((string) ($in['alt_titles'] ?? ''), 15, false);
    $fields = [
        'title' => $name,
        'alt_titles' => json_encode($alt, JSON_UNESCAPED_UNICODE),
        'type' => $type,
        'status' => $status,
        'year' => $year >= 1900 && $year <= 2100 ? $year : null,
        'genres' => json_encode(library_list((string) ($in['genres'] ?? ''), 12, true)),
        'author' => str_limit((string) ($in['author'] ?? ''), 255) ?: null,
        'artist' => str_limit((string) ($in['artist'] ?? ''), 255) ?: null,
        'description' => trim(mb_substr(str_replace("\r", '', (string) ($in['description'] ?? '')), 0, 5000)) ?: null,
        'hidden' => ($in['visibility'] ?? '') === 'published' ? 0 : 1,
        'updated_at' => now(),
    ];

    $newCover = null;
    if (($cover['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$img, $err] = store_image($cover, (int) config('max_cover_bytes'));
        if ($err) {
            return [null, "Cover: $err"];
        }
        $newCover = $img['file'];
    }

    if ($old) {
        if ($newCover !== null || !empty($in['remove_cover'])) {
            delete_media($old['cover']);
            $fields['cover'] = $newCover;
        }
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($fields)));
        db_run("UPDATE titles SET $sets WHERE id = ?", [...array_values($fields), $id]);
    } else {
        $base = substr(slugify($name) ?: 'title', 0, 180);
        $taken = site_series_index();
        $slug = $base;
        for ($i = 2; isset($taken[$slug]) || db_one('SELECT 1 FROM titles WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        $fields += ['slug' => $slug, 'origin' => 'own', 'cover' => $newCover, 'links' => '[]', 'created_at' => now()];
        db_run('INSERT INTO titles (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')', array_values($fields));
        $id = (int) db()->lastInsertId();
        log_info("Library: title added: $name");
    }
    // Your titles are kept apart from the catalogue: no name keys, so connectors and
    // feeds never merge into them or change what you typed.
    return [$id, null];
}

function library_title_delete(int $id): void
{
    $t = library_title($id);
    if (!$t) {
        return;
    }
    foreach (db_all('SELECT id FROM chapters WHERE title_id = ?', [$id]) as $c) {
        library_chapter_delete((int) $c['id']);
    }
    delete_media($t['cover']);
    db_run('UPDATE updates SET title_id = NULL WHERE title_id = ?', [$id]);
    db_run('DELETE FROM title_keys WHERE title_id = ?', [$id]);
    db_run('DELETE FROM title_sources WHERE title_id = ?', [$id]);
    db_run('DELETE FROM titles WHERE id = ?', [$id]);
    log_info("Library: title deleted: {$t['title']}");
}

/** Keeps a title's "latest chapter" in step with its published chapters. */
function library_refresh_latest(int $titleId): void
{
    $own = db_one("SELECT number, published_at FROM chapters WHERE title_id = ? AND status = 'published' ORDER BY number DESC LIMIT 1", [$titleId]);
    $feed = db_one('SELECT chapter_number AS number, COALESCE(release_date, created_at) AS published_at FROM updates WHERE title_id = ? AND hidden = 0 ORDER BY chapter_number DESC LIMIT 1', [$titleId]);
    $last = $own && (!$feed || (float) $own['number'] >= (float) $feed['number']) ? $own : $feed;
    db_run('UPDATE titles SET latest_chapter = ?, latest_chapter_at = ?, updated_at = ? WHERE id = ?', [$last['number'] ?? null, $last['published_at'] ?? null, now(), $titleId]);
}

// ---------- Chapters ----------

function library_chapter(int $id): ?array
{
    return db_one("SELECT c.*, t.title AS series_title, t.slug AS series_slug, t.hidden AS title_hidden FROM chapters c JOIN titles t ON t.id = c.title_id WHERE c.id = ? AND t.origin = 'own'", [$id]);
}

function library_chapters(int $titleId): array
{
    return db_all('SELECT c.*, (SELECT COUNT(*) FROM chapter_pages p WHERE p.chapter_id = c.id) AS page_count FROM chapters c WHERE c.title_id = ? ORDER BY c.number DESC', [$titleId]);
}

/** @return array{0:?int,1:?string} [chapter id, error message] */
function library_chapter_save(?int $id, int $titleId, array $in): array
{
    $num = trim((string) ($in['number'] ?? ''));
    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $num)) {
        return [null, 'Enter a chapter number such as 1, 12 or 12.5.'];
    }
    $label = str_limit((string) ($in['label'] ?? ''), 255) ?: null;
    $dupe = db_one('SELECT id FROM chapters WHERE title_id = ? AND number = ? AND id <> ?', [$titleId, (float) $num, $id ?? 0]);
    if ($dupe) {
        return [null, 'This title already has a chapter ' . format_chapter((float) $num) . '.'];
    }
    if ($id) {
        db_run('UPDATE chapters SET number = ?, label = ?, updated_at = ? WHERE id = ?', [(float) $num, $label, now(), $id]);
    } else {
        db_run("INSERT INTO chapters (title_id, number, label, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', ?, ?)", [$titleId, (float) $num, $label, now(), now()]);
        $id = (int) db()->lastInsertId();
    }
    library_refresh_latest($titleId);
    return [$id, null];
}

/** @return ?string error message */
function library_chapter_publish(array $c, bool $publish): ?string
{
    if ($publish && !db_one('SELECT 1 FROM chapter_pages WHERE chapter_id = ?', [$c['id']])) {
        return 'Upload at least one page before publishing.';
    }
    db_run('UPDATE chapters SET status = ?, published_at = ?, updated_at = ? WHERE id = ?',
        [$publish ? 'published' : 'draft', $publish ? ($c['published_at'] ?? now()) : $c['published_at'], now(), $c['id']]);
    library_refresh_latest((int) $c['title_id']);
    log_info('Library: ' . $c['series_title'] . ' chapter ' . format_chapter((float) $c['number']) . ($publish ? ' published' : ' unpublished'));
    return null;
}

function library_chapter_delete(int $id): void
{
    foreach (library_pages($id) as $p) {
        delete_media($p['file']);
    }
    db_run('DELETE FROM chapter_pages WHERE chapter_id = ?', [$id]);
    $c = db_one('SELECT title_id FROM chapters WHERE id = ?', [$id]);
    db_run('DELETE FROM chapters WHERE id = ?', [$id]);
    if ($c) {
        library_refresh_latest((int) $c['title_id']);
    }
}

// ---------- Pages ----------

function library_pages(int $chapterId): array
{
    return db_all('SELECT * FROM chapter_pages WHERE chapter_id = ? ORDER BY position, id', [$chapterId]);
}

/** Numbers the pages 1, 2, 3… in the given order (default: their current order). */
function library_renumber(int $chapterId, ?array $ids = null): void
{
    $ids ??= array_column(library_pages($chapterId), 'id');
    foreach (array_values($ids) as $i => $pid) {
        db_run('UPDATE chapter_pages SET position = ? WHERE id = ? AND chapter_id = ?', [$i + 1, (int) $pid, $chapterId]);
    }
    db_run('UPDATE chapters SET updated_at = ? WHERE id = ?', [now(), $chapterId]);
}

/** Adds uploaded pages after the existing ones. @return array{0:int,1:string[]} */
function library_add_pages(array $c, array $field): array
{
    $files = uploaded_files($field);
    if (!$files) {
        return [0, ['Choose one or more images first.']];
    }
    $count = count(library_pages((int) $c['id']));
    $max = (int) config('max_pages_per_chapter');
    $added = 0;
    $problems = [];
    foreach ($files as $f) {
        if ($count >= $max) {
            $problems[] = "A chapter can have at most $max pages, so the rest were skipped.";
            break;
        }
        [$img, $err] = store_image($f, (int) config('max_page_bytes'));
        if ($err) {
            $problems[] = $err;
            continue;
        }
        $count++;
        db_run('INSERT INTO chapter_pages (chapter_id, position, file, width, height, bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$c['id'], $count, $img['file'], $img['width'], $img['height'], $img['bytes'], now()]);
        $added++;
    }
    library_renumber((int) $c['id']);
    return [$added, $problems];
}

/** Saves the order typed into the page number boxes. */
function library_reorder(int $chapterId, array $wanted): void
{
    $pages = library_pages($chapterId);
    $rank = [];
    foreach ($pages as $i => $p) {
        $n = $wanted[$p['id']] ?? '';
        // Pages keep their place unless given a new number; ties keep the old order.
        $rank[$p['id']] = [is_numeric($n) ? (float) $n : $i + 1, $i];
    }
    uasort($rank, fn ($a, $b) => $a <=> $b);
    library_renumber($chapterId, array_keys($rank));
}

function library_move_page(int $chapterId, int $pageId, int $step): void
{
    $ids = array_map('intval', array_column(library_pages($chapterId), 'id'));
    $i = array_search($pageId, $ids, true);
    $j = $i === false ? false : $i + $step;
    if ($j === false || $j < 0 || $j >= count($ids)) {
        return;
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    library_renumber($chapterId, $ids);
}

/** @return ?string error message */
function library_replace_page(int $chapterId, int $pageId, array $upload): ?string
{
    $p = db_one('SELECT * FROM chapter_pages WHERE id = ? AND chapter_id = ?', [$pageId, $chapterId]);
    if (!$p) {
        return 'Page not found.';
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Choose the new image first.';
    }
    [$img, $err] = store_image($upload, (int) config('max_page_bytes'));
    if ($err) {
        return $err;
    }
    db_run('UPDATE chapter_pages SET file = ?, width = ?, height = ?, bytes = ? WHERE id = ?', [$img['file'], $img['width'], $img['height'], $img['bytes'], $pageId]);
    delete_media($p['file']);
    db_run('UPDATE chapters SET updated_at = ? WHERE id = ?', [now(), $chapterId]);
    return null;
}

function library_delete_pages(int $chapterId, array $pageIds): int
{
    $n = 0;
    foreach ($pageIds as $pid) {
        $p = db_one('SELECT * FROM chapter_pages WHERE id = ? AND chapter_id = ?', [(int) $pid, $chapterId]);
        if ($p) {
            delete_media($p['file']);
            db_run('DELETE FROM chapter_pages WHERE id = ?', [$p['id']]);
            $n++;
        }
    }
    library_renumber($chapterId);
    // A published chapter with no pages left goes back to draft.
    if ($n && !library_pages($chapterId)) {
        db_run("UPDATE chapters SET status = 'draft' WHERE id = ?", [$chapterId]);
    }
    return $n;
}

// ---------- What the website shows ----------

/** Published chapters of a title in the shape of the site's chapter JSON, newest first. */
function library_public_chapters(array $t): array
{
    $out = [];
    foreach (db_all("SELECT * FROM chapters WHERE title_id = ? AND status = 'published' ORDER BY number DESC", [$t['id']]) as $c) {
        $rows = library_pages((int) $c['id']);
        $pages = array_map(fn ($p) => media_url($p['file']), $rows);
        if (!$pages) {
            continue;
        }
        $out[] = [
            'number' => (float) $c['number'],
            'title' => $c['label'],
            'date' => gmdate('c', ts($c['published_at'] ?? $c['created_at'])),
            'sourceUrl' => null,
            'readable' => true,
            'source' => null,
            'credit' => null,
            'titleSlug' => $t['slug'],
            'pages' => $pages,
            // Width and height of each page, so the reader keeps its place while images load.
            'sizes' => array_map(fn ($p) => [(int) $p['width'], (int) $p['height']], $rows),
        ];
    }
    return $out;
}

/** Newest published chapters of visible titles, for Latest Updates. */
function library_latest_updates(int $limit): array
{
    $rows = db_all("SELECT c.*, t.title AS series_title, t.slug, t.cover FROM chapters c JOIN titles t ON t.id = c.title_id
        WHERE c.status = 'published' AND t.origin = 'own' AND t.hidden = 0 AND EXISTS (SELECT 1 FROM chapter_pages p WHERE p.chapter_id = c.id)
        ORDER BY c.published_at DESC LIMIT $limit");
    return array_map(fn ($c) => [
        'series' => $c['series_title'],
        'slug' => $c['slug'],
        'onSite' => false,
        'titlePage' => true,
        'titleSlug' => $c['slug'],
        'hosted' => true,
        'cover' => media_name_ok($c['cover']) ? media_url($c['cover']) : null,
        'chapter' => (float) $c['number'],
        'title' => $c['label'],
        'date' => gmdate('c', ts($c['published_at'] ?? $c['created_at'])),
        'sourceUrl' => null,
        'readable' => true,
        'source' => null,
        'credit' => null,
    ], $rows);
}

// ---------- Pages for crawled chapters (sources with hosting permission) ----------
// Stored like your own pages: checked, given a random name and saved in data/media/.
// updates.pages lists the files in reading order. They are listed on the website only
// while the chapter's source has hosting permission and the chapter and title are visible.

/** File names of a crawled chapter's uploaded pages, in reading order. */
function update_pages(array $u): array
{
    $list = json_decode((string) ($u['pages'] ?? ''), true);
    return is_array($list) ? array_values(array_filter($list, 'media_name_ok')) : [];
}

/** A crawled chapter with its source's name and hosting permission, or null. */
function chapter_for_pages(int $updateId): ?array
{
    return db_one('SELECT u.*, s.name AS source_name, s.hosting_allowed, s.hosting_note FROM updates u LEFT JOIN sources s ON s.id = u.source_id WHERE u.id = ?', [$updateId]);
}

/**
 * Adds uploaded images after the chapter's existing pages, ordered by their file names.
 * @return array{0:int,1:string[]} [pages added, problems]
 */
function pages_upload(array $u, array $field): array
{
    if (empty($u['hosting_allowed'])) {
        return [0, ['This chapter\'s source has no hosting permission. Edit the source first.']];
    }
    $files = uploaded_files($field);
    if (!$files) {
        return [0, ['Choose one or more images first.']];
    }
    $names = update_pages($u);
    $max = (int) config('max_pages_per_chapter');
    $added = 0;
    $problems = [];
    foreach ($files as $f) {
        if (count($names) >= $max) {
            $problems[] = "A chapter can have at most $max pages, so the rest were skipped.";
            break;
        }
        [$img, $err] = store_image($f, (int) config('max_page_bytes'));
        if ($err) {
            $problems[] = $err;
            continue;
        }
        $names[] = $img['file'];
        $added++;
    }
    db_run('UPDATE updates SET pages = ? WHERE id = ?', [$names ? json_encode($names) : null, (int) $u['id']]);
    if ($added) {
        log_info("Added $added page(s) to {$u['series_title']} chapter " . format_chapter((float) $u['chapter_number']), (int) $u['source_id']);
    }
    return [$added, $problems];
}

/** Deletes a crawled chapter's uploaded pages. */
function pages_clear(int $updateId): void
{
    $u = db_one('SELECT pages FROM updates WHERE id = ?', [$updateId]);
    foreach ($u ? update_pages($u) : [] as $file) {
        delete_media($file);
    }
    db_run('UPDATE updates SET pages = NULL WHERE id = ?', [$updateId]);
}

/**
 * The pages the website may show: chapters whose source has hosting permission and
 * that are not hidden. @return array<int,string[]> update id => page addresses
 */
function pages_publish(): array
{
    $rows = db_all('SELECT u.id, u.pages FROM updates u JOIN sources s ON s.id = u.source_id LEFT JOIN titles t ON t.id = u.title_id
        WHERE u.pages IS NOT NULL AND u.hidden = 0 AND s.hosting_allowed = 1 AND (t.id IS NULL OR t.hidden = 0)');
    $out = [];
    foreach ($rows as $r) {
        if ($files = update_pages($r)) {
            $out[(int) $r['id']] = array_map('media_url', $files);
        }
    }
    return $out;
}

/** The hosted pages found by the last pages_publish() run. */
function hosted_pages(?array $set = null): array
{
    static $map = [];
    if ($set !== null) {
        $map = $set;
    }
    return $map;
}
