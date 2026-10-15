<?php
defined('MANHUANI') || exit;

function log_event(string $level, string $message, ?int $sourceId = null): void
{
    try {
        db_run('INSERT INTO logs (level, source_id, message, created_at) VALUES (?, ?, ?, ?)', [
            $level, $sourceId, str_limit($message, 2000), now(),
        ]);
    } catch (Throwable $e) {
        error_log('[manhuani] ' . $level . ': ' . $message);
    }
    if (PHP_SAPI === 'cli' && getenv('MANHUANI_QUIET') !== '1') {
        fwrite(STDOUT, sprintf("[%s] %s%s\n", strtoupper($level), $sourceId ? "#$sourceId " : '', $message));
    }
}

function log_info(string $m, ?int $s = null): void { log_event('info', $m, $s); }
function log_warn(string $m, ?int $s = null): void { log_event('warning', $m, $s); }
function log_error(string $m, ?int $s = null): void { log_event('error', $m, $s); }

function prune_old_rows(): void
{
    $cut = gmdate('Y-m-d H:i:s', time() - 86400 * (int) config('log_retention_days'));
    db_run('DELETE FROM logs WHERE created_at < ?', [$cut]);
    db_run('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
}
