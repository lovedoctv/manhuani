<?php
// Signing in to the admin: first-time setup, password checks, login limits and form tokens.
defined('ABROADPATH') || exit;

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MIN = 15;
const PASSWORD_MIN = 10;

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function is_set_up(): bool
{
    return (string) config('admin_password_hash') !== '';
}

/**
 * The one-time code that proves the person setting the password can open the server's files.
 * It is written to setup-code.txt in the private folder, which only File Manager can read.
 */
function setup_code(): string
{
    $file = ensure_private_dir() . '/setup-code.txt';
    $code = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if (!preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code)) {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $abc[random_int(0, strlen($abc) - 1)] . (in_array($i, [3, 7], true) ? '-' : '');
        }
        write_atomic($file, $code . "\n");
    }
    return $code;
}

function login_locked(): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - LOGIN_WINDOW_MIN * 60);
    $n = (int) (db_one('SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND attempted_at >= ?', [client_ip(), $since])['n'] ?? 0);
    return $n >= LOGIN_MAX_ATTEMPTS;
}

function failed_attempt(): void
{
    usleep(random_int(300000, 700000)); // slows down guessing
    db_run('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)', [client_ip(), now()]);
    db_run('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
}

/** Checks the setup form. Returns an error message, or null when the password was set. */
function run_setup(string $code, string $password, string $repeat): ?string
{
    if (login_locked()) {
        return 'Too many tries. Wait ' . LOGIN_WINDOW_MIN . ' minutes and try again.';
    }
    if (!hash_equals(setup_code(), strtoupper(trim($code)))) {
        failed_attempt();
        return 'That setup code is not right. Copy it again from setup-code.txt.';
    }
    if ($error = password_problem($password, $repeat)) {
        return $error;
    }
    save_config(['admin_password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    @unlink(private_dir() . '/setup-code.txt');
    sign_in();
    return null;
}

function password_problem(string $password, string $repeat): ?string
{
    if (mb_strlen($password) < PASSWORD_MIN) {
        return 'Use a password of at least ' . PASSWORD_MIN . ' characters.';
    }
    if ($password !== $repeat) {
        return 'The two passwords are not the same.';
    }
    return null;
}

function attempt_login(string $password): bool
{
    $hash = (string) config('admin_password_hash');
    if ($hash !== '' && password_verify($password, $hash)) {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            save_config(['admin_password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        db_run('DELETE FROM login_attempts WHERE ip = ?', [client_ip()]);
        sign_in();
        return true;
    }
    failed_attempt();
    return false;
}

function sign_in(): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['since'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/** Sessions started before the last password change are signed out. */
function session_expired(): bool
{
    return is_logged_in() && (int) ($_SESSION['since'] ?? 0) < (int) config('sessions_after');
}

/** Changing the password signs out every other device that was signed in. */
function change_password(string $current, string $password, string $repeat): ?string
{
    if (!password_verify($current, (string) config('admin_password_hash'))) {
        failed_attempt();
        return 'Your current password is not right.';
    }
    if ($error = password_problem($password, $repeat)) {
        return $error;
    }
    save_config(['admin_password_hash' => password_hash($password, PASSWORD_DEFAULT), 'sessions_after' => time()]);
    $_SESSION['since'] = time();
    return null;
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
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    return is_string($sent) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
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
