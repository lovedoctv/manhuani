<?php
// For tests only: makes PHP's built-in web server (php -S) follow the site's .htaccess rules.
// Usage: php -S 127.0.0.1:8090 -t <folder holding AbroadPath/> blog/test/router.php
$root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = '/AbroadPath/';

// Locked: admin/inc/, and every .php file in admin/ except its page
if (str_starts_with($path, $base . 'admin/inc/') || (str_starts_with($path, $base . 'admin/') && str_ends_with($path, '.php') && $path !== $base . 'admin/index.php')) {
    http_response_code(403);
    return true;
}
if (preg_match('#^' . $base . 'media/.*\.(php|html?|svg|js)$#i', $path)) {
    http_response_code(403);
    return true;
}
// RewriteRule ^blog/([a-z0-9-]+)/?$ post.php?slug=$1 (when there's no folder)
if (preg_match('#^' . $base . 'blog/([a-z0-9-]+)/?$#', $path, $m) && !is_dir("$root{$base}blog/{$m[1]}")) {
    $_GET['slug'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = $base . 'post.php';
    require "$root{$base}post.php";
    return true;
}
if ($path === $base . 'admin/' || $path === $base . 'admin') {
    $_SERVER['SCRIPT_NAME'] = $base . 'admin/index.php';
    require "$root{$base}admin/index.php";
    return true;
}
// Folders without a trailing slash, and folder/index.html
if (is_dir($root . $path)) {
    if (!str_ends_with($path, '/')) {
        header("Location: $path/", true, 301);
        return true;
    }
    if (is_file("$root{$path}index.html")) {
        header('Content-Type: text/html; charset=utf-8');
        readfile("$root{$path}index.html");
        return true;
    }
}
if (!is_file($root . $path)) {
    http_response_code(404);
    readfile("$root{$base}404.html");
    return true;
}
return false;
