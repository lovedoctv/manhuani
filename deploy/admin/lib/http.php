<?php
defined('MANHUANI') || exit;

/**
 * Polite, locked-down HTTP client.
 * - Only http(s) on ports 80/443, and never private/internal addresses (stops the
 *   dashboard being used to reach your own server or network).
 * - Identifies itself with a clear User-Agent, waits between requests to the same
 *   site, caps response size and the number of requests per run.
 * - Never sends cookies, never logs in, never solves CAPTCHAs.
 */
final class Http
{
    private static array $lastRequestAt = [];
    private static int $requestCount = 0;
    private static array $hostDelay = [];
    private static ?int $budget = null;

    public static function setHostDelay(string $host, int $seconds): void
    {
        self::$hostDelay[strtolower($host)] = $seconds;
    }

    public static function requestCount(): int
    {
        return self::$requestCount;
    }

    /** Starts a new request budget: $max requests, or max_requests_per_run when null. */
    public static function resetBudget(?int $max = null): void
    {
        self::$requestCount = 0;
        self::$budget = $max;
    }

    /** @return array{status:int, headers:array<string,string>, body:string, url:string, error:?string} */
    public static function get(string $url, array $extraHeaders = [], int $maxRedirects = 3): array
    {
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $res = self::once($url, $extraHeaders);
            if ($res['error'] !== null || !in_array($res['status'], [301, 302, 303, 307, 308], true)) {
                return $res;
            }
            $loc = $res['headers']['location'] ?? '';
            if ($loc === '') {
                return $res;
            }
            $url = resolve_url($url, $loc);
        }
        return self::fail($url, 'Too many redirects');
    }

    /** POST a JSON body (used for official APIs such as AniList's GraphQL). No redirects are followed. */
    public static function postJson(string $url, array $data, array $extraHeaders = []): array
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::once($url, array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders), $body);
    }

    private static function once(string $url, array $extraHeaders, ?string $postBody = null): array
    {
        if (self::$requestCount >= (self::$budget ?? (int) config('max_requests_per_run'))) {
            return self::fail($url, 'Request limit for this run reached; will continue next run');
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return self::fail($url, 'Only plain http:// or https:// web addresses are allowed');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $allowPrivate = (bool) config('allow_private_hosts');
        if (!$allowPrivate && !in_array($port, [80, 443], true)) {
            return self::fail($url, 'Only standard web ports (80/443) are allowed');
        }
        $ip = self::resolvePublicIp($host, $allowPrivate);
        if ($ip === null) {
            return self::fail($url, "Address $host could not be resolved or points to a private network");
        }

        // Be polite: wait between requests to the same host.
        $delay = max((int) config('min_delay_seconds'), self::$hostDelay[$host] ?? 0);
        $delay = min($delay, (int) config('max_delay_seconds'));
        if (isset(self::$lastRequestAt[$host])) {
            $wait = self::$lastRequestAt[$host] + $delay - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1e6));
            }
        }
        self::$lastRequestAt[$host] = microtime(true);
        self::$requestCount++;

        $headers = [];
        $body = '';
        $max = (int) config('max_bytes');
        $tooBig = false;
        $ch = curl_init($url);
        $resolveHost = str_contains($ip, ':') ? "[$ip]" : $ip;
        if ($postBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        }
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["$host:$port:$resolveHost"],   // pin the checked IP (no DNS tricks)
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => (int) config('timeout_seconds'),
            CURLOPT_USERAGENT => (string) config('user_agent'),
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => self::headers($extraHeaders),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $max, &$tooBig) {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooBig = true;
                    return 0; // abort
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $ok ? null : curl_error($ch);
        curl_close($ch);
        if ($tooBig) {
            return self::fail($url, 'Response is larger than the ' . round($max / 1048576, 1) . ' MB limit');
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'url' => $url, 'error' => $err ?: null];
    }

    /** Default Accept header unless the caller sets its own. */
    private static function headers(array $extra): array
    {
        foreach ($extra as $h) {
            if (stripos($h, 'accept:') === 0) {
                return $extra;
            }
        }
        return array_merge(['Accept: application/rss+xml, application/atom+xml, application/feed+json, application/xml;q=0.9, text/html;q=0.8, */*;q=0.5'], $extra);
    }

    private static function resolvePublicIp(string $host, bool $allowPrivate): ?string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
        if (!$ips) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $r) {
                $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
            }
            $ips = array_values(array_filter($ips));
            if (!$ips) {
                $ips = @gethostbynamel($host) ?: [];
            }
        }
        if (!$ips) {
            return null;
        }
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (!$public && !$allowPrivate) {
                return null; // any private answer means we refuse the host entirely
            }
        }
        return $ips[0];
    }

    private static function fail(string $url, string $error): array
    {
        return ['status' => 0, 'headers' => [], 'body' => '', 'url' => $url, 'error' => $error];
    }
}

function resolve_url(string $base, string $rel): string
{
    $rel = trim(html_entity_decode($rel, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($rel === '') {
        return $base;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $rel)) {
        return $rel;
    }
    $b = parse_url($base);
    $root = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($rel, '//')) {
        return ($b['scheme'] ?? 'https') . ':' . $rel;
    }
    if (str_starts_with($rel, '/')) {
        return $root . $rel;
    }
    if (str_starts_with($rel, '?')) {
        return $root . ($b['path'] ?? '/') . $rel;
    }
    $path = $b['path'] ?? '/';
    $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
    $segments = [];
    foreach (explode('/', $dir . $rel) as $seg) {
        if ($seg === '..') {
            array_pop($segments);
        } elseif ($seg !== '.') {
            $segments[] = $seg;
        }
    }
    return $root . '/' . ltrim(implode('/', $segments), '/');
}

/** Spots pages that are walls we must not get past: CAPTCHAs, bot checks, logins, paywalls. */
function detect_blocker(array $res): ?string
{
    $status = $res['status'];
    $body = strtolower(substr($res['body'], 0, 20000));
    $server = strtolower($res['headers']['server'] ?? '');
    if ($status === 401 || $status === 407) {
        return 'This source requires a login. Logins are not supported.';
    }
    if ($status === 402) {
        return 'This source is behind a paywall. Paywalls are not supported.';
    }
    $markers = ['captcha', 'cf-chl', 'challenge-platform', 'just a moment...', 'attention required', 'checking your browser', 'ddos-guard', 'are you a robot', 'bot verification', 'please verify you are a human'];
    foreach ($markers as $m) {
        if (str_contains($body, $m) && ($status >= 400 || str_contains($body, '<form') || str_contains($server, 'cloudflare') || str_contains($body, 'challenge'))) {
            return 'This source shows a CAPTCHA or anti-bot check. It will not be bypassed, so this source can\'t be used automatically.';
        }
    }
    if ($status === 403) {
        return 'This source refused access (403 Forbidden). Automated access is not permitted.';
    }
    if (preg_match('/<input[^>]+type=["\']?password/i', $body) && preg_match('/(log ?in|sign ?in)/i', $body) && !preg_match('/<(rss|feed)\b/i', $body)) {
        return 'This page asks for a login. Logins are not supported.';
    }
    return null;
}
