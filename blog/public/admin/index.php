<?php
// AbroadPath blog admin: write, publish and delete posts. Private: password protected, not indexed.
declare(strict_types=1);

define('ABROADPATH', true);
require __DIR__ . '/inc/app.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$missing = [];
if (PHP_VERSION_ID < 80000) {
    $missing[] = 'PHP 8.0 or newer (this server runs ' . PHP_VERSION . ')';
}
foreach (['pdo_sqlite', 'dom', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = "the PHP extension \"$ext\"";
    }
}
if ($missing) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Server check</title><body style="font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px">'
        . '<h1>This server is missing something</h1><ul><li>' . implode('</li><li>', array_map('h', $missing)) . '</li></ul>'
        . '<p>In cPanel, open <b>Select PHP Version</b> (or <b>MultiPHP Manager</b>), choose PHP 8.1 or newer and turn these extensions on.</p></body>';
    exit;
}

require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/html.php';
require __DIR__ . '/inc/render.php';
require __DIR__ . '/inc/posts.php';
require __DIR__ . '/inc/views.php';

try {
    site();
    db();
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[abroadpath admin] ' . $e->getMessage());
    echo view_problem('The admin could not start', '<p>' . h($e->getMessage()) . '</p><p>If this mentions a folder, check that your cPanel home folder has space and that '
        . h(basename(private_dir())) . ' can be created in it.</p>');
    exit;
}

header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('X-Frame-Options: DENY');

// Always use the address ending in /admin/ so the session cookie and links line up.
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (!str_ends_with($path, '/')) {
    header('Location: ' . admin_url() . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
    exit;
}

start_session();
$a = (string) ($_GET['a'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

function go(string $action = '', array $params = []): void
{
    header('Location: ' . admin_url($action, $params), true, 303);
    exit;
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// More than the server accepts in one request: PHP drops the whole form, token included.
if ($isPost && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $limit = 'The image or post was bigger than the server accepts (' . round(upload_limit() / 1048576, 1) . ' MB). Use a smaller image.';
    if ($a === 'upload') {
        json_out(['error' => $limit], 413);
    }
    flash('error', $limit);
    $editId = (int) ($_GET['id'] ?? 0);
    go($editId ? 'edit' : 'new', $editId ? ['id' => $editId] : []);
}

// A form posted after the page sat open too long (or from somewhere else) is refused.
if ($isPost && !csrf_ok()) {
    if ($a === 'upload') {
        json_out(['error' => 'Your session ran out. Save the post, then sign in again.'], 403);
    }
    http_response_code(400);
    flash('error', 'That form had expired, so nothing was changed. Please try again.');
    go();
}

// ----- First visit: choose the password with the setup code -----
if (!is_set_up()) {
    $error = null;
    if ($isPost && $a === 'setup') {
        $error = run_setup((string) ($_POST['code'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['repeat'] ?? ''));
        if ($error === null) {
            flash('ok', 'Your password is set. You can write your first post now.');
            go();
        }
    }
    setup_code();
    echo view_setup($error);
    exit;
}

if (session_expired()) {
    logout();
    start_session();
}

// ----- Sign in -----
if (!is_logged_in()) {
    $error = null;
    if ($isPost && $a === 'login') {
        if (login_locked()) {
            $error = 'Too many wrong passwords. Wait ' . LOGIN_WINDOW_MIN . ' minutes and try again.';
        } elseif (attempt_login((string) ($_POST['password'] ?? ''))) {
            go();
        } else {
            $error = 'That password is not right.';
        }
    }
    if ($a === 'upload') {
        json_out(['error' => 'You were signed out. Save the post, then sign in again.'], 403);
    }
    http_response_code($error ? 401 : 200);
    echo view_login($error);
    exit;
}

// ----- Signed in -----
$id = (int) ($_GET['id'] ?? 0);

// The site's lists of admin posts (live/posts.json, sitemap-posts.xml) exist from the first sign-in on.
if (!is_file(site_dir() . '/live/posts.json')) {
    try {
        write_live_files();
    } catch (Throwable $e) {
        flash('error', 'Could not write the post list in the site folder: ' . $e->getMessage());
    }
}

switch ($a) {
    case 'logout':
        if ($isPost) {
            logout();
        }
        go();

    case 'new':
        echo view_editor(blank_post());
        exit;

    case 'edit':
        $post = post_by_id($id);
        if (!$post) {
            flash('error', 'That post no longer exists.');
            go();
        }
        echo view_editor($post);
        exit;

    case 'save':
        if (!$isPost) {
            go();
        }
        $existing = $id ? post_by_id($id) : null;
        if ($id && !$existing) {
            flash('error', 'That post no longer exists.');
            go();
        }
        $action = (string) ($_POST['action'] ?? 'draft');
        if (!in_array($action, ['draft', 'publish', 'update', 'unpublish'], true)) {
            $action = 'draft';
        }
        [$savedId, $errors, $fields] = post_save($existing, $_POST, $_FILES, $action);
        if ($errors) {
            http_response_code(422);
            echo view_editor(array_replace($existing ?? blank_post(), $fields), $errors);
            exit;
        }
        $saved = post_by_id($savedId);
        $messages = [
            'publish' => 'Published. The post is live on the site now.',
            'update' => 'Saved. The changes are live on the site.',
            'unpublish' => 'Taken off the site. The post is a draft again.',
            'draft' => 'Draft saved. Only you can see it until you publish it.',
        ];
        flash('ok', $messages[$action === 'draft' && $saved['status'] === 'published' ? 'update' : $action]);
        go('edit', ['id' => $savedId]);

    case 'delete':
        if ($isPost && ($post = post_by_id($id))) {
            post_delete($id);
            flash('ok', 'Deleted "' . $post['title'] . '".');
        }
        go();

    case 'upload':
        if (!$isPost) {
            json_out(['error' => 'Send the image with the editor.'], 405);
        }
        if (empty($_FILES['image'])) {
            json_out(['error' => 'The image was bigger than the server accepts (' . round(upload_limit() / 1048576, 1) . ' MB).'], 413);
        }
        [$file, $err] = store_image($_FILES['image']);
        if ($err) {
            json_out(['error' => $err], 422);
        }
        json_out(['url' => media_url($file)]);

    case 'password':
        $error = null;
        if ($isPost) {
            $error = change_password((string) ($_POST['current'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['repeat'] ?? ''));
            if ($error === null) {
                flash('ok', 'Password changed.');
                go();
            }
        }
        echo view_password($error);
        exit;

    default:
        echo view_list();
        exit;
}
