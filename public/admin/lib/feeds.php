<?php
defined('MANHUANI') || exit;

/**
 * Feeds (RSS, Atom, JSON Feed) are the "authorized method" we use: sites publish
 * them specifically so other programs can read their updates.
 */
function feed_kind(array $res): ?string
{
    $type = strtolower($res['headers']['content-type'] ?? '');
    $head = ltrim(substr($res['body'], 0, 2000));
    if (str_contains($type, 'json') || str_starts_with($head, '{')) {
        $j = json_decode($res['body'], true);
        if (is_array($j) && isset($j['items']) && str_contains((string) ($j['version'] ?? ''), 'jsonfeed')) {
            return 'jsonfeed';
        }
        return null;
    }
    if (preg_match('/<rss\b/i', $head) || preg_match('/<rdf:RDF\b/i', $head)) {
        return 'rss';
    }
    if (preg_match('/<feed\b[^>]*xmlns=["\']http:\/\/www\.w3\.org\/2005\/Atom/i', $head)) {
        return 'atom';
    }
    return null;
}

/** Finds feed links advertised in a web page's <head>. */
function discover_feeds(string $html, string $baseUrl): array
{
    $found = [];
    if (preg_match_all('/<link\b[^>]*>/i', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (!preg_match('/rel=["\']?[^"\'>]*alternate/i', $tag)) {
                continue;
            }
            // Plain application/json links on WordPress sites point at the REST API
            // (or oEmbed), which is never used automatically, so only real feed types count.
            if (!preg_match('/type=["\']?(application\/(rss|atom)\+xml|application\/feed\+json)/i', $tag)) {
                continue;
            }
            if (preg_match('/href=["\']([^"\']+)["\']/i', $tag, $hm) && !preg_match('#/wp-json/|oembed#i', $hm[1])) {
                $found[] = resolve_url($baseUrl, $hm[1]);
            }
        }
    }
    return array_values(array_unique($found));
}

/** Spots an advertised API (we only report it; use it only if the site's terms allow). */
function detect_api(string $html, string $baseUrl): ?string
{
    if (preg_match('/<link[^>]+rel=["\']https:\/\/api\.w\.org\/["\'][^>]*href=["\']([^"\']+)/i', $html, $m)
        || preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']https:\/\/api\.w\.org\//i', $html, $m)) {
        return 'WordPress REST API at ' . resolve_url($baseUrl, $m[1]);
    }
    if (preg_match('/<link[^>]+rel=["\']?(api|service)["\']?[^>]*href=["\']([^"\']+)/i', $html, $m)) {
        return 'API link at ' . resolve_url($baseUrl, $m[2]);
    }
    return null;
}

/**
 * Parses a feed into plain items.
 * @return array{title:string, items:list<array{title:string, link:string, date:?string, id:string}>}
 */
function parse_feed(string $body, string $kind, string $feedUrl): array
{
    $max = (int) config('max_items_per_feed');
    $items = [];
    $channelTitle = '';

    if ($kind === 'jsonfeed') {
        $j = json_decode($body, true) ?: [];
        $channelTitle = (string) ($j['title'] ?? '');
        foreach (array_slice($j['items'] ?? [], 0, $max) as $it) {
            $link = (string) ($it['url'] ?? $it['external_url'] ?? '');
            $items[] = [
                'title' => (string) ($it['title'] ?? ''),
                'link' => $link ? resolve_url($feedUrl, $link) : '',
                'date' => feed_date($it['date_published'] ?? $it['date_modified'] ?? null),
                'id' => (string) ($it['id'] ?? $link),
            ];
        }
        return ['title' => $channelTitle, 'items' => $items];
    }

    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        throw new RuntimeException('The feed is not valid XML');
    }

    if ($kind === 'atom') {
        $channelTitle = (string) $xml->title;
        $n = 0;
        foreach ($xml->entry as $e) {
            if ($n++ >= $max) break;
            $link = '';
            foreach ($e->link as $l) {
                $rel = (string) ($l['rel'] ?? 'alternate');
                if ($rel === 'alternate' || $link === '') {
                    $link = (string) $l['href'];
                }
            }
            $items[] = [
                'title' => trim((string) $e->title),
                'link' => $link ? resolve_url($feedUrl, $link) : '',
                'date' => feed_date((string) ($e->published ?: $e->updated)),
                'id' => (string) ($e->id ?: $link),
            ];
        }
        return ['title' => $channelTitle, 'items' => $items];
    }

    // RSS 2.0 / RSS 1.0 (RDF)
    $channel = $xml->channel ?? null;
    $channelTitle = $channel ? (string) $channel->title : '';
    $nodes = ($channel && $channel->item->count()) ? $channel->item : $xml->item;
    $n = 0;
    foreach ($nodes as $it) {
        if ($n++ >= $max) break;
        $dc = $it->children('http://purl.org/dc/elements/1.1/');
        $link = trim((string) $it->link) ?: trim((string) $it->guid);
        $items[] = [
            'title' => trim((string) $it->title),
            'link' => $link ? resolve_url($feedUrl, $link) : '',
            'date' => feed_date((string) ($it->pubDate ?: $dc->date)),
            'id' => (string) ($it->guid ?: $link),
        ];
    }
    return ['title' => $channelTitle, 'items' => $items];
}

function feed_date($value): ?string
{
    if (!$value) {
        return null;
    }
    // Ignore a leading weekday ("Wed, 15 Oct ..."): feeds sometimes get it wrong,
    // and PHP would then jump to the next matching weekday.
    $value = preg_replace('/^\s*[a-z]{3,9},?\s+(?=\d)/i', '', (string) $value);
    $t = strtotime($value);
    if ($t === false || $t > time() + 86400) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $t);
}
