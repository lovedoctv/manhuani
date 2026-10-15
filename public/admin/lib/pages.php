<?php
defined('MANHUANI') || exit;

/*
 * Chapter pages you are allowed to host. You upload them on the dashboard; the crawler
 * never downloads pages or images from other sites.
 *
 * The uploaded files are kept in the private folder (pages/<update id>/). Publishing
 * copies them to the website (data/pages/<update id>/) only while the chapter's source
 * has hosting permission ticked, and removes the website copies when it doesn't.
 */

const PAGE_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
const PAGE_MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

function pages_private_dir(int $updateId): string
{
    return config('private_dir') . '/pages/' . $updateId;
}

function pages_public_dir(): string
{
    return site_dir() . '/data/pages';
}

/** File names of a chapter's uploaded pages, in reading order. */
function update_pages(array $u): array
{
    $list = json_decode((string) ($u['pages'] ?? ''), true);
    return is_array($list) ? array_values(array_filter($list, 'is_page_name')) : [];
}

function is_page_name($n): bool
{
    return is_string($n) && preg_match('/^\d{3,4}\.(jpg|png|webp|gif)$/', $n) === 1;
}

/** A chapter with its source's name and hosting permission, or null. */
function chapter_for_pages(int $updateId): ?array
{
    return db_one('SELECT u.*, s.name AS source_name, s.hosting_allowed, s.hosting_note FROM updates u LEFT JOIN sources s ON s.id = u.source_id WHERE u.id = ?', [$updateId]);
}

/**
 * Adds uploaded images (the $_FILES entry of a multiple file input) after the
 * chapter's existing pages, ordered by their file names.
 * @return array{0:int,1:string[]} [pages added, problems]
 */
function pages_upload(array $u, array $files): array
{
    if (empty($u['hosting_allowed'])) {
        return [0, ['This chapter\'s source has no hosting permission. Edit the source first.']];
    }
    $names = update_pages($u);
    $max = (int) config('max_pages_per_chapter');
    $maxBytes = (int) config('max_page_bytes');
    $items = [];
    foreach ((array) ($files['name'] ?? []) as $i => $orig) {
        $items[] = ['name' => (string) $orig, 'tmp' => (string) ($files['tmp_name'][$i] ?? ''), 'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($files['size'][$i] ?? 0)];
    }
    usort($items, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

    $dir = pages_private_dir((int) $u['id']);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return [0, ['Could not create the folder for this chapter\'s pages.']];
    }
    $added = 0;
    $problems = [];
    foreach ($items as $f) {
        $label = str_limit($f['name'], 80);
        if ($f['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $problems[] = "$label is larger than your server allows (" . ini_get('upload_max_filesize') . ').';
            continue;
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) {
            $problems[] = "$label did not upload. Try again.";
            continue;
        }
        if ($f['size'] > $maxBytes) {
            $problems[] = "$label is over " . round($maxBytes / 1048576) . ' MB.';
            continue;
        }
        $info = @getimagesize($f['tmp']);
        $ext = $info ? (PAGE_TYPES[$info[2]] ?? null) : null;
        if ($ext === null || $info[0] < 1 || $info[1] < 1 || $info[0] > 20000 || $info[1] > 60000) {
            $problems[] = "$label is not a JPG, PNG, WEBP or GIF image.";
            continue;
        }
        if (count($names) >= $max) {
            $problems[] = "A chapter can have at most $max pages, so the rest were skipped.";
            break;
        }
        $file = sprintf('%03d.%s', count($names) + 1, $ext);
        if (!move_uploaded_file($f['tmp'], "$dir/$file")) {
            $problems[] = "$label could not be saved.";
            continue;
        }
        chmod("$dir/$file", 0600);
        $names[] = $file;
        $added++;
    }
    db_run('UPDATE updates SET pages = ? WHERE id = ?', [$names ? json_encode($names) : null, (int) $u['id']]);
    if ($added) {
        log_info("Added $added page(s) to {$u['series_title']} chapter " . format_chapter((float) $u['chapter_number']), (int) $u['source_id']);
    }
    return [$added, $problems];
}

/** Deletes a chapter's uploaded pages, from the private folder and the website. */
function pages_clear(int $updateId): void
{
    rm_tree(pages_private_dir($updateId));
    rm_tree(pages_public_dir() . '/' . $updateId);
    db_run('UPDATE updates SET pages = NULL WHERE id = ?', [$updateId]);
}

/**
 * Copies the pages of permitted, visible chapters to the website and removes every
 * other copy there.
 * @return array<int,string[]> update id => page addresses relative to the site root
 */
function pages_publish(): array
{
    $public = pages_public_dir();
    $rows = db_all('SELECT u.id, u.pages FROM updates u JOIN sources s ON s.id = u.source_id LEFT JOIN titles t ON t.id = u.title_id
        WHERE u.pages IS NOT NULL AND u.hidden = 0 AND s.hosting_allowed = 1 AND (t.id IS NULL OR t.hidden = 0)');
    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $keep = [];
        foreach (update_pages($r) as $name) {
            $src = pages_private_dir($id) . "/$name";
            $dst = "$public/$id/$name";
            if (!is_file($src)) {
                continue;
            }
            if (!is_file($dst) || filesize($dst) !== filesize($src)) {
                if (!is_dir("$public/$id") && !mkdir("$public/$id", 0755, true) && !is_dir("$public/$id")) {
                    throw new RuntimeException("Cannot create folder $public/$id");
                }
                copy($src, "$dst.tmp");
                chmod("$dst.tmp", 0644);
                rename("$dst.tmp", $dst);
            }
            $keep[$name] = true;
            $out[$id][] = "data/pages/$id/$name";
        }
        foreach (glob("$public/$id/*") ?: [] as $f) {
            if (!isset($keep[basename($f)])) {
                @unlink($f);
            }
        }
    }
    if (is_dir($public)) {
        foreach (glob("$public/*", GLOB_ONLYDIR) ?: [] as $d) {
            if (!isset($out[(int) basename($d)])) {
                rm_tree($d); // permission removed, chapter hidden or deleted
            }
        }
        if (!is_file("$public/index.html")) {
            write_atomic("$public/index.html", '');
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

function rm_tree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") && !is_link("$dir/$f") ? rm_tree("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
}

/** "8M" -> 8388608, as PHP reads its own size settings. */
function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n > 0 ? $n : PHP_INT_MAX;
}
