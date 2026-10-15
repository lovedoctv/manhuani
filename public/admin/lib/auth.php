<?php
defined('MANHUANI') || exit;

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MIN = 15;
const SESSION_IDLE_SECONDS = 7200;

function start_session(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
    session_name('manhuani_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

function login_locked(): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - LOGIN_WINDOW_MIN * 60);
    $row = db_one('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND attempted_at >= ?', [client_ip(), $since]);
    return (int) ($row['n'] ?? 0) >= LOGIN_MAX_ATTEMPTS;
}

function attempt_login(string $password): bool
{
    $hash = (string) config('admin_password_hash');
    if ($hash !== '' && password_verify($password, $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        db_run('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
        log_info('Dashboard login from ' . client_ip());
        return true;
    }
    usleep(random_int(300000, 700000)); // slow down guessing
    db_run('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)', [client_ip(), now()]);
    log_warn('Failed dashboard login from ' . client_ip());
    return false;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function flash(?string $type = null, ?string $msg = null): array
{
    if ($type !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return [];
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
