<?php
// Turns an admin post into a full page, with the same layout and markup as the posts in the code
// (blog/src/pages/blog/[slug].astro), and writes the lists the rest of the site reads.
defined('ABROADPATH') || exit;

/** A post row from the database, with its JSON fields decoded. */
function post_decode(array $p): array
{
    $p['countries'] = json_decode((string) $p['countries'], true) ?: [];
    $p['facts'] = json_decode((string) $p['facts'], true) ?: [];
    return $p;
}

function post_by_slug(string $slug): ?array
{
    $row = db_one('SELECT * FROM posts WHERE slug = ?', [$slug]);
    return $row ? post_decode($row) : null;
}

/** Published admin posts, newest first. */
function published_posts(): array
{
    return array_map('post_decode', db_all("SELECT * FROM posts WHERE status = 'published' ORDER BY published_at DESC, id DESC"));
}

/** The date a post shows as published, and the later date it was changed (or null). */
function post_dates(array $p): array
{
    $date = substr((string) ($p['published_at'] ?? $p['created_at']), 0, 10);
    $updated = substr((string) $p['updated_at'], 0, 10);
    return [$date, $updated > $date ? $updated : null];
}

function media_url(string $file): string
{
    return url('media/' . $file);
}

/** One post card, like components/PostCard.astro. $p uses the field names of site.json posts. */
function post_card(array $p): string
{
    $flags = implode(' ', array_filter(array_map(fn ($c) => country_info($c)['flag'] ?? null, $p['countries'])));
    $left = $p['deadline'] ? days_left($p['deadline']) : null;
    $foot = $left !== null && $left >= 0
        ? '<span class="deadline-pill' . ($left <= 14 ? ' soon' : '') . '">Deadline ' . h(format_date($p['deadline'])) . '</span>'
        : '<span class="muted">Updated ' . h(format_date($p['updated'] ?? $p['date'])) . '</span>';
    return '<article class="post-card" data-date="' . h(iso_date($p['date'])) . '"><p class="card-meta">'
        . '<a class="cat-label" href="' . h(url('category/' . $p['category'] . '/')) . '">' . h(category_name($p['category'])) . '</a>'
        . ($flags !== '' ? ' <span aria-hidden="true">' . h($flags) . '</span>' : '')
        . '</p><h3><a href="' . h(post_path($p['slug'])) . '">' . h($p['title']) . '</a></h3>'
        . '<p class="muted">' . h($p['description']) . '</p><p class="card-foot small">' . $foot . '</p></article>';
}

/** A post in the shape site.json uses for the posts in the code. */
function post_summary(array $p): array
{
    [$date, $updated] = post_dates($p);
    return [
        'slug' => $p['slug'], 'title' => $p['title'], 'description' => $p['description'], 'category' => $p['category'],
        'countries' => $p['countries'], 'date' => $date, 'updated' => $updated, 'deadline' => $p['deadline'] ?: null,
        'from_code' => (bool) ($p['from_code'] ?? false),
    ];
}

/** "Read next": posts in the same category first, then the newest others (code and admin posts together). */
function related_posts(array $post, int $limit = 4): array
{
    $all = array_map('post_summary', published_posts());
    $mine = array_column($all, 'slug', 'slug');
    foreach (site('posts') as $p) {
        if (isset($mine[$p['slug']])) {
            continue; // edited in the admin: its edited version is already in the list
        }
        $p['date'] = substr((string) $p['date'], 0, 10);
        $p['updated'] = $p['updated'] ? substr($p['updated'], 0, 10) : null;
        $p['deadline'] = $p['deadline'] ? substr($p['deadline'], 0, 10) : null;
        $all[] = $p;
    }
    $all = array_values(array_filter($all, fn ($p) => $p['slug'] !== $post['slug']));
    usort($all, fn ($a, $b) => strcmp($b['date'], $a['date']));
    $same = array_filter($all, fn ($p) => $p['category'] === $post['category']);
    $other = array_filter($all, fn ($p) => $p['category'] !== $post['category']);
    return array_slice(array_merge($same, $other), 0, $limit);
}

function offer_boxes(string $category, string $slug): string
{
    $out = '';
    foreach ((array) site('offers') as $o) {
        if (empty($o['url']) || !in_array($category, (array) ($o['categories'] ?? []), true)) {
            continue;
        }
        $fill = str_replace(['{source}', '{campaign}', '{post}'], ['direct', 'none', $slug], (string) $o['url']);
        $out .= '<aside class="offer-box"><p class="offer-label">Recommended</p><p class="offer-title">' . h($o['title']) . '</p>'
            . '<p class="offer-text">' . h($o['text']) . '</p><a class="btn btn-accent" href="' . h($fill) . '" data-offer="' . h($o['id'])
            . '" data-href="' . h($o['url']) . '" data-post="' . h($slug) . '" target="_blank" rel="sponsored nofollow noopener">' . h($o['cta']) . '</a></aside>';
    }
    return $out;
}

function share_bar(string $title, string $link): string
{
    $text = rawurlencode("$title $link");
    $u = rawurlencode($link);
    $t = rawurlencode($title);
    return '<div class="share-bar" aria-label="Share this post"><span class="small muted">Share:</span>'
        . '<a class="share whatsapp" href="' . h("https://wa.me/?text=$text") . '" target="_blank" rel="noopener">WhatsApp</a>'
        . '<a class="share facebook" href="' . h("https://www.facebook.com/sharer/sharer.php?u=$u") . '" target="_blank" rel="noopener">Facebook</a>'
        . '<a class="share telegram" href="' . h("https://t.me/share/url?url=$u&text=$t") . '" target="_blank" rel="noopener">Telegram</a>'
        . '<a class="share x" href="' . h("https://twitter.com/intent/tweet?url=$u&text=$t") . '" target="_blank" rel="noopener">X</a>'
        . '<button class="share copy" type="button" data-copy="' . h($link) . '">Copy link</button></div>';
}

function json_script($data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}

/** The whole page for one admin post. $preview marks a draft shown to the signed-in admin. */
function render_post_page(array $p, bool $preview = false): string
{
    $layout = @file_get_contents(__DIR__ . '/layout.html');
    if ($layout === false || strpos($layout, '<div id="zz-main"></div>') === false) {
        throw new RuntimeException('admin/inc/layout.html is missing. Deploy the site again.');
    }
    [$date, $updated] = post_dates($p);
    $slug = $p['slug'];
    $link = abs_url("blog/$slug/");
    $cat = $p['category'];
    $catUrl = url("category/$cat/");
    $catName = category_name($cat);
    $image = $p['cover'] ? abs_url('media/' . $p['cover']) : (string) site('ogImage');

    $crumbs = [['Home', url()], [$catName, $catUrl], [$p['title'], post_path($slug)]];
    $html = '<div class="container narrow block"><nav class="breadcrumbs small" aria-label="Breadcrumb">'
        . '<a href="' . h($crumbs[0][1]) . '">Home</a><span aria-hidden="true">›</span>'
        . '<a href="' . h($catUrl) . '">' . h($catName) . '</a><span aria-hidden="true">›</span>'
        . '<span aria-current="page">' . h($p['title']) . '</span></nav>'
        . json_script([
            '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn ($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => rtrim((string) site('url'), '/') . $c[1]], $crumbs, array_keys($crumbs)),
        ]);

    if ($preview) {
        $html .= '<p class="preview-note small">'
            . (!empty($p['from_code'])
                ? 'Preview of your changes. Only you can see them: the site shows the original until you publish them. '
                : 'Preview: this post is a draft, so only you can see it. ')
            . '<a href="' . h(url('admin/?a=edit&id=' . (int) $p['id'])) . '">Back to the editor</a></p>';
    }

    $html .= '<article class="prose" data-post="' . h($slug) . '" data-category="' . h($cat) . '">'
        . '<p class="card-meta"><a class="cat-label" href="' . h($catUrl) . '">' . h($catName) . '</a></p>'
        . '<h1>' . h($p['title']) . '</h1>'
        . '<p class="post-meta small muted">Updated <time datetime="' . h(iso_date($updated ?? $date)) . '">' . h(format_date($updated ?? $date)) . '</time>'
        . ' · Facts checked <time datetime="' . h(iso_date($p['checked'])) . '">' . h(format_date($p['checked'])) . '</time></p>';

    if ($p['countries']) {
        $html .= '<p class="country-chips">';
        foreach ($p['countries'] as $c) {
            if ($info = country_info($c)) {
                $html .= '<a href="' . h(url("country/$c/")) . '">' . h($info['flag'] . ' ' . $info['name']) . '</a>';
            }
        }
        $html .= '</p>';
    }

    if ($p['facts'] || $p['deadline'] || $p['official_url']) {
        $html .= '<div class="facts-box"><dl>';
        foreach ($p['facts'] as $f) {
            $html .= '<dt>' . h($f['label']) . '</dt><dd>' . h($f['value']) . '</dd>';
        }
        if ($p['deadline']) {
            $left = days_left($p['deadline']);
            $class = 'deadline-pill' . ($left >= 0 && $left <= 14 ? ' soon' : '') . ($left < 0 ? ' closed' : '');
            $html .= '<dt>Deadline</dt><dd><time datetime="' . h(iso_date($p['deadline'])) . '">' . h(format_date($p['deadline'])) . '</time>'
                . '<span class="' . $class . '" data-deadline="' . h(iso_date($p['deadline'])) . '"><span data-days-left>' . h(days_left_label($left)) . '</span></span></dd>';
        }
        $html .= '</dl>';
        if ($p['official_url']) {
            $html .= '<a class="btn btn-primary" href="' . h($p['official_url']) . '" target="_blank" rel="noopener" data-official>Official page: ' . h($p['official_name']) . '</a>';
        }
        $html .= '</div>';
    }

    if ($p['cover']) {
        $html .= '<figure class="post-cover"><img src="' . h(media_url($p['cover'])) . '" alt="" decoding="async"></figure>';
    }

    $html .= '<div class="post-body">' . render_body((string) $p['body']) . '</div>'
        . offer_boxes($cat, $slug) . share_bar($p['title'], $link) . '</article>';
    $after = trim((string) (site('ads')['afterArticle'] ?? ''));
    if ($after !== '') {
        $html .= '<div class="ad-slot ad-afterArticle">' . $after . '</div>';
    }
    $html .= '</div>';

    $related = related_posts($p);
    if ($related) {
        $html .= '<section class="container block"><h2 class="section-title">Read next</h2><div class="post-grid">'
            . implode('', array_map('post_card', $related)) . '</div></section>';
    }

    $ld = [
        '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $p['title'], 'description' => $p['description'],
        'datePublished' => iso_date($date), 'dateModified' => iso_date($updated ?? $p['checked']), 'mainEntityOfPage' => $link,
        'author' => ['@type' => 'Organization', 'name' => site('name')], 'publisher' => ['@type' => 'Organization', 'name' => site('name')],
    ];
    if ($p['cover']) {
        $ld['image'] = $image;
    }

    $page = str_replace(
        ['<div id="zz-main"></div>', '"ZZJSONLDZZ"', 'https://zzcanonical.invalid/', 'https://zzimage.invalid/', 'ZZTITLEZZ', 'ZZDESCRIPTIONZZ'],
        [$html, json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), h($link), h($image), h($p['title']), h($p['description'])],
        $layout
    );
    // Highlight the post's category in the menu, as on the built pages.
    $page = preg_replace('#<a href="' . preg_quote(h($catUrl), '#') . '">#', '<a href="' . h($catUrl) . '" class="active">', $page, 1) ?? $page;
    if ($preview) {
        $page = preg_replace('#</title>#', '</title><meta name="robots" content="noindex, nofollow">', $page, 1) ?? $page;
    }
    return $page;
}

/**
 * Rewrites the files the rest of the site reads after any change to the admin posts:
 * live/posts.json (lists, deadlines and search pick up new posts from it), sitemap-posts.xml, and
 * one file in live/edited/ for each post in the code whose edited version is published, which
 * tells .htaccess to show that version (through post.php) instead of the original.
 */
function write_live_files(): void
{
    $items = [];
    $urls = [];
    $edited = [];
    foreach (published_posts() as $p) {
        $s = post_summary($p);
        if ($s['from_code']) {
            $edited[$s['slug']] = true;
        }
        $items[] = [
            'slug' => $s['slug'], 'url' => post_path($s['slug']), 'title' => $s['title'], 'description' => $s['description'],
            'category' => $s['category'], 'categoryName' => category_name($s['category']), 'categoryUrl' => url('category/' . $s['category'] . '/'),
            'countries' => $s['countries'],
            'countryNames' => array_values(array_filter(array_map(fn ($c) => country_info($c)['name'] ?? null, $s['countries']))),
            'flags' => implode(' ', array_filter(array_map(fn ($c) => country_info($c)['flag'] ?? null, $s['countries']))),
            'date' => iso_date($s['date']), 'updated' => $s['updated'] ? iso_date($s['updated']) : null,
            'deadline' => $s['deadline'] ? iso_date($s['deadline']) : null, 'edits' => $s['from_code'],
        ];
        // Posts in the code are in the main sitemap.xml already.
        if (!$s['from_code']) {
            $urls[] = '  <url><loc>' . h(abs_url('blog/' . $s['slug'] . '/')) . '</loc><lastmod>' . iso_date($s['updated'] ?? $s['date']) . '</lastmod></url>';
        }
    }
    $dir = site_dir() . '/live/edited';
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $f) {
        if (preg_match('/^[a-z0-9-]+$/', $f) && !isset($edited[$f])) {
            @unlink("$dir/$f");
        }
    }
    foreach (array_keys($edited) as $slug) {
        if (!is_file("$dir/$slug")) {
            write_atomic("$dir/$slug", "Edited in the blog admin\n");
        }
    }
    write_atomic(site_dir() . '/live/posts.json', json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    write_atomic(site_dir() . '/sitemap-posts.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
        . implode("\n", $urls) . ($urls ? "\n" : '') . "</urlset>\n");
}
