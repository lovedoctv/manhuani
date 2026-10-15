<?php
defined('MANHUANI') || exit;

const ROBOTS_TOKEN = 'manhuanibot';

/**
 * Checks robots.txt (RFC 9309) for a URL.
 * @return array{allowed:bool, delay:int, reason:string}
 */
function robots_check(string $url): array
{
    $p = parse_url($url);
    $host = strtolower($p['host'] ?? '');
    $origin = ($p['scheme'] ?? 'https') . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');

    $cached = db_one('SELECT status, body, fetched_at FROM robots_cache WHERE host = ?', [$origin]);
    if ($cached && ts($cached['fetched_at']) > time() - 12 * 3600) {
        $status = (int) $cached['status'];
        $body = (string) $cached['body'];
    } else {
        $res = Http::get($origin . '/robots.txt');
        $status = $res['error'] ? 0 : $res['status'];
        $body = $status >= 200 && $status < 300 ? $res['body'] : '';
        if ($res['error'] && str_contains($res['error'], 'Request limit')) {
            return ['allowed' => false, 'delay' => 0, 'reason' => $res['error']];
        }
        db_run('DELETE FROM robots_cache WHERE host = ?', [$origin]);
        db_run('INSERT INTO robots_cache (host, status, body, fetched_at) VALUES (?, ?, ?, ?)', [$origin, $status, $body, now()]);
    }

    if ($status >= 400 && $status < 500) {
        return ['allowed' => true, 'delay' => 0, 'reason' => 'No robots.txt rules (HTTP ' . $status . '), so access is allowed'];
    }
    if ($status === 0 || $status >= 500) {
        return ['allowed' => false, 'delay' => 0, 'reason' => 'robots.txt could not be read, so we assume access is not allowed for now'];
    }
    return robots_evaluate($body, $path);
}

/** @return array{allowed:bool, delay:int, reason:string} */
function robots_evaluate(string $body, string $path): array
{
    $groups = [];
    $current = null;
    $lastWasAgent = false;
    foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line));
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);
        if ($key === 'user-agent') {
            if (!$lastWasAgent || $current === null) {
                $groups[] = ['agents' => [], 'rules' => [], 'delay' => 0];
                $current = count($groups) - 1;
            }
            $groups[$current]['agents'][] = strtolower($value);
            $lastWasAgent = true;
            continue;
        }
        $lastWasAgent = false;
        if ($current === null) {
            continue;
        }
        if ($key === 'allow' || $key === 'disallow') {
            $groups[$current]['rules'][] = [$key === 'allow', $value];
        } elseif ($key === 'crawl-delay' && is_numeric($value)) {
            $groups[$current]['delay'] = (int) ceil((float) $value);
        }
    }

    // Use the groups written for ManhuaniBot if any exist, otherwise the "*" groups.
    $chosen = null;
    foreach ([ROBOTS_TOKEN, '*'] as $agent) {
        foreach ($groups as $g) {
            if (in_array($agent, $g['agents'], true)) {
                $chosen ??= ['rules' => [], 'delay' => 0, 'agent' => $agent];
                $chosen['rules'] = array_merge($chosen['rules'], $g['rules']);
                $chosen['delay'] = max($chosen['delay'], $g['delay']);
            }
        }
        if ($chosen) {
            break;
        }
    }
    if (!$chosen) {
        return ['allowed' => true, 'delay' => 0, 'reason' => 'robots.txt has no rules for us, so access is allowed'];
    }

    $best = null;
    foreach ($chosen['rules'] as [$allow, $pattern]) {
        if ($pattern === '') {
            continue; // "Disallow:" with nothing means allow everything
        }
        if (robots_match($pattern, $path)) {
            $len = strlen($pattern);
            if ($best === null || $len > $best[1] || ($len === $best[1] && $allow)) {
                $best = [$allow, $len, $pattern];
            }
        }
    }
    $who = $chosen['agent'] === '*' ? 'all bots' : 'ManhuaniBot';
    if ($best && !$best[0]) {
        return ['allowed' => false, 'delay' => $chosen['delay'], 'reason' => "robots.txt blocks this address for $who (rule: Disallow: {$best[2]})"];
    }
    return ['allowed' => true, 'delay' => $chosen['delay'], 'reason' => "robots.txt allows this address for $who" . ($chosen['delay'] ? " with a {$chosen['delay']}s crawl delay" : '')];
}

function robots_match(string $pattern, string $path): bool
{
    $anchored = str_ends_with($pattern, '$');
    if ($anchored) {
        $pattern = substr($pattern, 0, -1);
    }
    $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';
    return (bool) preg_match($regex, $path);
}
