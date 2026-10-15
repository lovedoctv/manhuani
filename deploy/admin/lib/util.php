<?php
defined('MANHUANI') || exit;

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ts(?string $sqlDate): ?int
{
    return $sqlDate ? strtotime($sqlDate . ' UTC') : null;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Same rule the website uses for series URLs: "Dungeon Chef!" -> "dungeon-chef". */
function slugify(string $s): string
{
    $s = strtolower(trim($s));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) {
            $s = strtolower($t);
        }
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

function str_limit(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

function ago(?string $sqlDate): string
{
    $t = ts($sqlDate);
    if (!$t) {
        return 'never';
    }
    $d = time() - $t;
    if ($d < 0) {
        $d = -$d;
        $suffix = 'from now';
    } else {
        $suffix = 'ago';
    }
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ' . $suffix;
    if ($d < 86400) return floor($d / 3600) . ' h ' . $suffix;
    return floor($d / 86400) . ' d ' . $suffix;
}

/** Writes a file atomically so visitors never read a half-written file. */
function write_atomic(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create folder $dir");
    }
    $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contents) === false) {
        throw new RuntimeException("Cannot write $path");
    }
    chmod($tmp, 0644);
    rename($tmp, $path);
}
