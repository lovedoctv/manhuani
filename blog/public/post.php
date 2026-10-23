<?php
// Shows a post written in the blog admin at /blog/<slug>/ (.htaccess sends those addresses here
// when the post has no folder of its own). Posts in the code are plain files and only come here
// once their edited version from the admin is published (see live/edited/ in .htaccess).
declare(strict_types=1);

define('ABROADPATH', true);
require __DIR__ . '/admin/inc/app.php';
require __DIR__ . '/admin/inc/html.php';
require __DIR__ . '/admin/inc/render.php';

function not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    @readfile(__DIR__ . '/404.html');
    exit;
}

$slug = (string) ($_GET['slug'] ?? '');
if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 80) {
    not_found();
}

try {
    $post = db(false) ? post_by_slug($slug) : null;
} catch (Throwable $e) {
    error_log('[abroadpath post] ' . $e->getMessage());
    $post = null;
}
if (!$post) {
    not_found();
}

// Drafts are only shown to the signed-in admin, as a preview.
$preview = $post['status'] !== 'published';
if ($preview) {
    start_session();
    if (!is_logged_in()) {
        not_found();
    }
}

// One address per published post: /blog/<slug>/ with the slash. Previews also open as
// post.php?slug=..., because the address of a post in the code still shows the original.
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (!$preview && $path !== post_path($slug)) {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $query = trim(preg_replace('/(^|&)slug=[^&]*/', '', $query) ?? '', '&');
    header('Location: ' . post_path($slug) . ($query !== '' ? "?$query" : ''), true, 301);
    exit;
}

try {
    $html = render_post_page($post, $preview);
} catch (Throwable $e) {
    error_log('[abroadpath post] ' . $e->getMessage());
    http_response_code(500);
    exit('This page could not be shown right now. Please try again in a minute.');
}
header('Content-Type: text/html; charset=utf-8');
header($preview ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=300');
header('X-Post-Source: admin'); // the admin checks for it after publishing changes to a post in the code
if ($preview) {
    header('X-Robots-Tag: noindex, nofollow');
}
echo $html;
