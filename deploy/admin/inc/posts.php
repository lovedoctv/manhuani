<?php
// Writing, publishing and deleting admin posts, and the images uploaded for them.
defined('ABROADPATH') || exit;

const IMAGE_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
const IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const IMAGE_MAX_PIXELS = 30000000;
const IMAGE_MAX_WIDTH = 1600;
const FACTS_MAX = 8;

function post_by_id(int $id): ?array
{
    $row = db_one('SELECT * FROM posts WHERE id = ?', [$id]);
    return $row ? post_decode($row) : null;
}

/** Every admin post, most recently changed first. */
function admin_posts(): array
{
    return array_map('post_decode', db_all('SELECT * FROM posts ORDER BY updated_at DESC, id DESC'));
}

/** Addresses an admin post can't take: the posts in the code, and folders the site already uses. */
function taken_slugs(): array
{
    return array_merge(array_column(site('posts'), 'slug'), ['page']);
}

/** An empty post for the "New post" form. */
function blank_post(): array
{
    return [
        'id' => null, 'slug' => '', 'title' => '', 'description' => '', 'category' => site('categories')[0]['slug'],
        'countries' => [], 'body' => '', 'facts' => [], 'deadline' => null, 'official_name' => '', 'official_url' => '',
        'checked' => gmdate('Y-m-d'), 'cover' => null, 'status' => 'draft', 'published_at' => null,
    ];
}

function valid_date(string $d): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

/**
 * Checks the editor form. Returns [the post's fields, errors by field name].
 * $publish asks for everything a reader needs; a draft only needs a title.
 */
function post_validate(array $in, ?array $existing, bool $publish): array
{
    $e = [];
    $s = fn (string $k) => trim(str_replace("\r", '', (string) ($in[$k] ?? '')));
    $p = [];

    $p['title'] = str_limit($s('title'), 200);
    if (mb_strlen($p['title']) < 5) {
        $e['title'] = 'Give the post a title of at least 5 characters.';
    }

    $slug = slugify($s('slug') !== '' ? $s('slug') : $p['title']);
    $p['slug'] = $slug;
    if ($slug === '') {
        $e['slug'] = 'The web address needs at least one letter or number.';
    } elseif (in_array($slug, taken_slugs(), true)) {
        $e['slug'] = 'A post in the site already uses this web address. Change it a little.';
    } else {
        $other = db_one('SELECT id FROM posts WHERE slug = ? AND id != ?', [$slug, (int) ($existing['id'] ?? 0)]);
        if ($other) {
            $e['slug'] = 'Another of your posts already uses this web address.';
        }
    }
    $p['description'] = str_limit($s('description'), 300);
    if ($publish && mb_strlen($p['description']) < 30) {
        $e['description'] = 'Write a short summary (at least 30 characters). It shows on Google and in the post lists.';
    }

    $cats = array_column(site('categories'), 'slug');
    $p['category'] = in_array($s('category'), $cats, true) ? $s('category') : '';
    if ($p['category'] === '') {
        $e['category'] = 'Pick a category.';
    }

    $countries = array_column(site('countries'), 'slug');
    $p['countries'] = array_values(array_intersect($countries, array_map('strval', (array) ($in['countries'] ?? []))));

    $facts = [];
    $labels = (array) ($in['fact_label'] ?? []);
    $values = (array) ($in['fact_value'] ?? []);
    foreach ($labels as $i => $label) {
        $label = str_limit((string) $label, 60);
        $value = str_limit((string) ($values[$i] ?? ''), 200);
        if ($label === '' && $value === '') {
            continue;
        }
        if ($label === '' || $value === '') {
            $e['facts'] = 'Each fact needs both a label and a value.';
            continue;
        }
        $facts[] = ['label' => $label, 'value' => $value];
    }
    if (count($facts) > FACTS_MAX) {
        $e['facts'] = 'Use at most ' . FACTS_MAX . ' facts.';
    }
    $p['facts'] = array_slice($facts, 0, FACTS_MAX);

    $deadline = $s('deadline');
    $p['deadline'] = $deadline === '' ? null : $deadline;
    if ($deadline !== '' && !valid_date($deadline)) {
        $e['deadline'] = 'Use a real date for the deadline.';
    }

    $p['official_name'] = str_limit($s('official_name'), 120);
    $p['official_url'] = $s('official_url');
    if ($p['official_url'] !== '' && !preg_match('#^https?://[^\s"<>]+\.[^\s"<>]+$#i', $p['official_url'])) {
        $e['official_url'] = 'The official link must start with https:// (copy it from the browser address bar).';
    } elseif ($p['official_url'] !== '' && $p['official_name'] === '') {
        $e['official_name'] = 'Name the official page, for example "British Council Nigeria".';
    } elseif ($p['official_url'] === '' && $p['official_name'] !== '') {
        $e['official_url'] = 'Paste the link to the official page, or clear its name.';
    }

    $p['checked'] = $s('checked') !== '' ? $s('checked') : gmdate('Y-m-d');
    if (!valid_date($p['checked'])) {
        $e['checked'] = 'Use a real date for when you checked the facts.';
    }

    try {
        $p['body'] = clean_html(plain_to_html((string) ($in['body'] ?? '')));
    } catch (InvalidArgumentException $ex) {
        $e['body'] = $ex->getMessage();
        $p['body'] = (string) ($existing['body'] ?? '');
    }
    if ($publish && !html_has_content($p['body'])) {
        $e['body'] = 'Write the post before publishing it.';
    }
    return [$p, $e];
}

/** Text with no tags at all (typed without the editor) becomes paragraphs, one per blank line. */
function plain_to_html(string $body): string
{
    if ($body === '' || preg_match('/<[a-z][^>]*>/i', $body)) {
        return $body;
    }
    $paras = preg_split('/\n\s*\n/', str_replace("\r", '', trim($body))) ?: [];
    return implode('', array_map(fn ($p) => '<p>' . nl2br(h(trim($p)), false) . '</p>', $paras));
}

/**
 * Saves the editor form. $action is draft (save, keep or make it a draft), publish, or unpublish.
 * Returns [post id or null, errors by field name, the fields as entered].
 */
function post_save(?array $existing, array $in, array $files, string $action): array
{
    $publish = $action === 'publish' || ($action === 'update' && ($existing['status'] ?? '') === 'published');
    [$p, $errors] = post_validate($in, $existing, $publish);
    $p['cover'] = $existing['cover'] ?? null;

    $upload = $files['cover'] ?? null;
    if (!$errors && is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$file, $err] = store_image($upload);
        if ($err) {
            $errors['cover'] = $err;
        } else {
            $p['cover'] = $file;
        }
    } elseif (!empty($in['remove_cover'])) {
        $p['cover'] = null;
    }
    if ($errors) {
        return [null, $errors, $p + ['id' => $existing['id'] ?? null, 'status' => $existing['status'] ?? 'draft']];
    }

    $status = match ($action) {
        'publish' => 'published',
        'unpublish' => 'draft',
        default => $existing['status'] ?? 'draft',
    };
    $now = now();
    $row = [
        'slug' => $p['slug'], 'title' => $p['title'], 'description' => $p['description'], 'category' => $p['category'],
        'countries' => json_encode($p['countries']), 'body' => $p['body'], 'facts' => json_encode($p['facts'], JSON_UNESCAPED_UNICODE),
        'deadline' => $p['deadline'], 'official_name' => $p['official_name'], 'official_url' => $p['official_url'],
        'checked' => $p['checked'], 'cover' => $p['cover'], 'status' => $status,
        'published_at' => $status === 'published' ? ($existing['published_at'] ?? $now) : ($existing['published_at'] ?? null),
        'updated_at' => $now,
    ];
    if ($existing) {
        $sets = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
        db_run("UPDATE posts SET $sets WHERE id = ?", [...array_values($row), $existing['id']]);
        $id = (int) $existing['id'];
    } else {
        $row['created_at'] = $now;
        db_run('INSERT INTO posts (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
        $id = (int) db()->lastInsertId();
    }
    write_live_files();
    clean_unused_media();
    return [$id, [], $p];
}

function post_delete(int $id): void
{
    $post = post_by_id($id);
    if (!$post) {
        return;
    }
    db_run('DELETE FROM posts WHERE id = ?', [$id]);
    write_live_files();
    // Its images go at once, unless another post uses them too.
    $files = array_filter([...html_media_files((string) $post['body']), $post['cover']]);
    clean_unused_media(86400, $files);
}

// ---------- Images ----------

function media_dir(): string
{
    return site_dir() . '/media';
}

function media_name_ok(?string $file): bool
{
    return is_string($file) && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $file) === 1;
}

/** The largest upload the server accepts, in bytes (the smaller of PHP's limits and ours). */
function upload_limit(): int
{
    $bytes = function (string $v): int {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')), IMAGE_MAX_BYTES]);
    return min($limits);
}

/**
 * Checks one uploaded image (a real JPG, PNG, WEBP or GIF under the size limit) and saves it
 * in media/ under a new random name. Large photos are made smaller and saved again, which also
 * drops the camera and location details stored inside them.
 * @return array{0:?string,1:?string} [file name, error message]
 */
function store_image(array $f): array
{
    $name = str_limit(basename((string) ($f['name'] ?? 'The image')), 80);
    $error = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return [null, "$name is bigger than the server allows (" . round(upload_limit() / 1048576, 1) . ' MB).'];
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        return [null, "$name did not upload. Try again."];
    }
    if ((int) $f['size'] > IMAGE_MAX_BYTES) {
        return [null, "$name is over " . (IMAGE_MAX_BYTES >> 20) . ' MB. Use a smaller image.'];
    }
    $info = @getimagesize((string) $f['tmp_name']);
    $ext = $info ? (IMAGE_TYPES[$info[2]] ?? null) : null;
    if ($ext === null || $info[0] < 1 || $info[1] < 1) {
        return [null, "$name is not a JPG, PNG, WEBP or GIF image."];
    }
    if ($info[0] * $info[1] > IMAGE_MAX_PIXELS) {
        return [null, "$name is too large ({$info[0]}×{$info[1]} pixels). Use an image under 5000×5000."];
    }
    $dir = media_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return [null, 'Could not create the media folder for images.'];
    }
    $base = bin2hex(random_bytes(12));
    [$file, $w, $h] = resize_image((string) $f['tmp_name'], $info, $ext, "$dir/$base");
    if ($file === null) {
        $file = "$base.$ext";
        if (!move_uploaded_file((string) $f['tmp_name'], "$dir/$file")) {
            return [null, "$name could not be saved."];
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
    }
    db_run('INSERT INTO media (file, width, height, bytes, created_at) VALUES (?, ?, ?, ?, ?)', [$file, $w, $h, (int) filesize("$dir/$file"), now()]);
    return [$file, null];
}

/**
 * Saves a smaller, re-encoded copy (WEBP when the server supports it) when PHP's GD extension is
 * there. GIFs keep their animation, so they are stored as they are. Returns [file, width, height],
 * or [null, 0, 0] to store the original.
 */
function resize_image(string $tmp, array $info, string $ext, string $target): array
{
    $open = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$ext] ?? null;
    if ($open === null || !function_exists($open) || !function_exists('imagecreatetruecolor')) {
        return [null, 0, 0];
    }
    $im = @$open($tmp);
    if (!$im) {
        return [null, 0, 0];
    }
    // Phone photos are often stored sideways with a note saying which way is up.
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($tmp)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $im = imagerotate($im, $angle, 0) ?: $im;
        }
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > IMAGE_MAX_WIDTH) {
        $nh = (int) round($h * IMAGE_MAX_WIDTH / $w);
        $small = imagecreatetruecolor(IMAGE_MAX_WIDTH, $nh);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $im, 0, 0, 0, 0, IMAGE_MAX_WIDTH, $nh, $w, $h);
        $im = $small;
        [$w, $h] = [IMAGE_MAX_WIDTH, $nh];
    }
    imagesavealpha($im, true);
    if (function_exists('imagewebp') && @imagewebp($im, "$target.webp", 82)) {
        return [basename("$target.webp"), $w, $h];
    }
    if ($ext === 'jpg' && @imagejpeg($im, "$target.jpg", 85)) {
        return [basename("$target.jpg"), $w, $h];
    }
    if ($ext === 'png' && @imagepng($im, "$target.png", 6)) {
        return [basename("$target.png"), $w, $h];
    }
    return [null, 0, 0];
}

/**
 * Deletes uploaded images no post uses any more. Images younger than $minAge seconds are kept,
 * because they may belong to a post that is still being written and hasn't been saved yet,
 * except the ones in $now (the images of a post that was just deleted).
 */
function clean_unused_media(int $minAge = 86400, array $now = []): void
{
    $used = [];
    foreach (db_all('SELECT body, cover FROM posts') as $p) {
        foreach (html_media_files((string) $p['body']) as $f) {
            $used[$f] = true;
        }
        if ($p['cover']) {
            $used[$p['cover']] = true;
        }
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - $minAge);
    $marks = implode(',', array_fill(0, count($now), '?'));
    $sql = 'SELECT id, file FROM media WHERE created_at <= ?' . ($now ? " OR file IN ($marks)" : '');
    foreach (db_all($sql, [$cutoff, ...array_values($now)]) as $m) {
        if (!isset($used[$m['file']]) && media_name_ok($m['file'])) {
            @unlink(media_dir() . '/' . $m['file']);
            db_run('DELETE FROM media WHERE id = ?', [$m['id']]);
        }
    }
}
