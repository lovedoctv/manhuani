<?php
// Command-line tool for the crawler. Run from cPanel Terminal or Cron Jobs:
//   php cli.php setup      first-time install (creates config, database, password)
//   php cli.php run        check enabled sources that are due (use this in cron)
//   php cli.php run --all  check every enabled source now
//   php cli.php test ID    test one source
//   php cli.php publish    rewrite the website's update files
//   php cli.php password   change the dashboard password
//   php cli.php status     show a short summary
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('MANHUANI', true);
require __DIR__ . '/lib/bootstrap.php';

$cmd = $argv[1] ?? 'help';

function ask(string $q, bool $hidden = false): string
{
    fwrite(STDOUT, $q);
    if ($hidden && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) {
        shell_exec('stty -echo');
        $v = fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    } else {
        $v = fgets(STDIN);
    }
    return trim((string) $v);
}

function ask_new_password(): string
{
    while (true) {
        $p1 = ask('Choose a dashboard password (at least 10 characters): ', true);
        if (strlen($p1) < 10) {
            fwrite(STDOUT, "Too short, try again.\n");
            continue;
        }
        $p2 = ask('Type it again: ', true);
        if ($p1 !== $p2) {
            fwrite(STDOUT, "They don't match, try again.\n");
            continue;
        }
        return $p1;
    }
}

function write_config(string $dir, array $values): void
{
    $php = "<?php\n// Manhuani crawler settings. Keep this file private: it is outside your website folder.\n// See admin/lib/bootstrap.php (default_config) for every setting you can add here.\nreturn " . var_export($values, true) . ";\n";
    write_atomic($dir . '/config.php', $php);
    chmod($dir . '/config.php', 0600);
}

try {
    if (in_array($cmd, ['run', 'test', 'publish', 'status', 'connectors', 'connector-test', 'connector-run'], true) && private_dir() !== null) {
        db_ensure_schema();
    }
    switch ($cmd) {
        case 'setup':
            if ($missing = missing_requirements()) {
                throw new RuntimeException("This server is missing:\n  - " . implode("\n  - ", $missing)
                    . "\nIn cPanel, open \"Select PHP Version\" (or MultiPHP Manager) and choose PHP 8.1 or newer with these extensions turned on.");
            }
            $existing = private_dir();
            if ($existing !== null) {
                fwrite(STDOUT, "Already installed (settings in $existing/config.php).\nUpdating the database tables...\n");
                db_migrate();
                fwrite(STDOUT, "Done.\n");
                break;
            }
            $home = getenv('HOME') ?: dirname(__DIR__, 3);
            $dir = rtrim($home, '/') . '/manhuani_private';
            fwrite(STDOUT, "Installing the Manhuani crawler.\nPrivate settings and database will be stored in: $dir\n\n");
            if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
                throw new RuntimeException("Could not create $dir");
            }
            chmod($dir, 0700);
            $useMysql = strtolower(ask('Use a MySQL database instead of the built-in SQLite file? (y/N): ')) === 'y';
            $values = ['admin_password_hash' => password_hash(ask_new_password(), PASSWORD_DEFAULT)];
            if ($useMysql) {
                $values['db_driver'] = 'mysql';
                $values['mysql_host'] = ask('MySQL host [localhost]: ') ?: 'localhost';
                $values['mysql_name'] = ask('Database name (e.g. youruser_manhuani): ');
                $values['mysql_user'] = ask('Database user: ');
                $values['mysql_pass'] = ask('Database password: ', true);
            }
            write_config($dir, $values);
            putenv("MANHUANI_PRIVATE_DIR=$dir");
            try {
                db_migrate();
            } catch (Throwable $e) {
                // Remove the half-finished settings so running setup again starts fresh.
                @unlink($dir . '/config.php');
                throw new RuntimeException('Could not set up the database: ' . $e->getMessage()
                    . "\nNothing was saved. Check the details and run setup again.");
            }
            log_info('Crawler installed');
            publish_updates();
            fwrite(STDOUT, "\nInstalled. Open https://aduna.fit/manhuani/admin/ and log in with your password.\n");
            break;

        case 'run':
            $all = in_array('--all', $argv, true);
            $stats = crawler_run(null, $all);
            fwrite(STDOUT, "Checked {$stats['checked']} source(s) and connector(s), {$stats['new']} new update(s), " . ($stats['titles'] ?? 0) . " new title(s), {$stats['errors']} error(s).\n");
            break;

        case 'connectors':
            foreach (connectors_all() as $name => $c) {
                $st = json_decode((string) $c['last_stats'], true) ?: [];
                fwrite(STDOUT, str_pad($name, 14) . str_pad($c['enabled'] ? 'on' : 'off', 5) . 'last ok: ' . str_pad($c['last_success_at'] ?? 'never', 20)
                    . 'next: ' . str_pad($c['enabled'] ? ($c['next_run_at'] ?? 'now') : '-', 20) . 'fails: ' . (int) $c['fail_count']
                    . ($st ? "  last run: {$st['titles']} title(s), {$st['new']} chapter(s), {$st['linked']} matched, {$st['requests']} request(s)" : '')
                    . ($c['last_error'] ? "\n              error: {$c['last_error']}" : '') . "\n");
            }
            break;

        case 'connector-test':
            $r = connector_test((string) ($argv[2] ?? ''));
            foreach ($r['lines'] as [$lvl, $txt]) {
                fwrite(STDOUT, str_pad(strtoupper($lvl), 8) . " $txt\n");
            }
            fwrite(STDOUT, $r['ok'] ? "Result: works.\n" : "Result: not working.\n");
            break;

        case 'connector-run':
            $name = (string) ($argv[2] ?? '');
            $s = connectors_run(true, $name);
            if ($s['checked'] === 0) {
                throw new RuntimeException("Connector \"$name\" is unknown or not enabled. Enable it on the dashboard's Connectors page first.");
            }
            if ($s['new'] || $s['titles']) {
                publish_updates();
            }
            fwrite(STDOUT, "{$s['titles']} new title(s), {$s['new']} new chapter(s), {$s['errors']} error(s).\n");
            break;

        case 'test':
            $id = (int) ($argv[2] ?? 0);
            $r = source_test($id);
            foreach ($r['lines'] as [$lvl, $txt]) {
                fwrite(STDOUT, str_pad(strtoupper($lvl), 8) . " $txt\n");
            }
            fwrite(STDOUT, $r['ok'] ? "Result: ready to enable.\n" : "Result: can't be used automatically.\n");
            break;

        case 'publish':
            $n = publish_updates();
            fwrite(STDOUT, "Website update files written ($n updates).\n");
            break;

        case 'password':
            $dir = private_dir();
            if ($dir === null) {
                throw new RuntimeException('Not installed yet. Run: php cli.php setup');
            }
            $cfg = require $dir . '/config.php';
            $cfg['admin_password_hash'] = password_hash(ask_new_password(), PASSWORD_DEFAULT);
            write_config($dir, $cfg);
            fwrite(STDOUT, "Password changed.\n");
            break;

        case 'status':
            $c = db_one('SELECT (SELECT COUNT(*) FROM sources) s, (SELECT COUNT(*) FROM sources WHERE enabled=1) e, (SELECT COUNT(*) FROM updates) u, (SELECT COUNT(*) FROM titles) t, (SELECT COUNT(*) FROM connectors WHERE enabled=1) c');
            fwrite(STDOUT, "Sources: {$c['s']} ({$c['e']} enabled)\nConnectors enabled: {$c['c']} of " . count(connector_defs()) . "\nTitles: {$c['t']}\nUpdates: {$c['u']}\nLast run: " . (meta_get('last_run_at') ?? 'never') . " UTC\n");
            break;

        default:
            fwrite(STDOUT, "Usage: php cli.php setup | run [--all] | test ID | publish | password | status | connectors | connector-test NAME | connector-run NAME\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
