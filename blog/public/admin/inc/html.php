<?php
// Post text: cleans what the editor sends (only safe formatting survives), and prepares it for the page.
defined('ABROADPATH') || exit;

const BODY_MAX_BYTES = 400000;

// Tags a post may use, with the attributes each may keep. Everything else is removed.
const HTML_ALLOWED = [
    'p' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'br' => [], 'hr' => [],
    'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [], 'mark' => [],
    'a' => ['href', 'title'], 'ul' => [], 'ol' => ['start'], 'li' => [], 'blockquote' => [],
    'img' => ['src', 'alt', 'width', 'height'], 'figure' => [], 'figcaption' => [],
    'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
    'pre' => [], 'code' => [],
];
// Removed together with everything inside them.
const HTML_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'option',
    'svg', 'math', 'head', 'title', 'meta', 'link', 'noscript', 'template', 'video', 'audio', 'canvas', 'frame', 'frameset', 'applet', 'base'];
const HTML_RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];
const HTML_BLOCKS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'figure', 'table', 'pre', 'hr', 'div', 'section',
    'article', 'header', 'footer', 'main', 'aside', 'nav', 'h1', 'h5', 'h6', 'thead', 'tbody', 'tr', 'td', 'th', 'figcaption', 'dl', 'dt', 'dd'];
// Wrapper elements that become a paragraph when they only hold text, and disappear otherwise.
const HTML_WRAPPERS = ['div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'dl', 'dt', 'dd'];

function html_load(string $html): DOMDocument
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="root">' . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

function html_root(DOMDocument $doc): DOMElement
{
    $root = $doc->getElementById('root');
    if (!$root) {
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'root') {
                return $div;
            }
        }
        throw new RuntimeException('Could not read the post text.');
    }
    return $root;
}

function html_inner(DOMElement $el): string
{
    $out = '';
    foreach ($el->childNodes as $child) {
        $out .= $el->ownerDocument->saveHTML($child);
    }
    return $out;
}

/** A link the post may keep: web, email and phone links, and links inside the site. */
function safe_href(string $href): ?string
{
    $href = trim($href);
    if ($href === '' || preg_match('/[\x00-\x1f]/', $href)) {
        return null;
    }
    if (preg_match('#^(https?://|mailto:|tel:)#i', $href) || preg_match('#^(/(?!/)|\#)#', $href)) {
        return $href;
    }
    // "www.example.com" typed without https://
    if (preg_match('#^www\.[a-z0-9-]+\.[a-z]#i', $href)) {
        return 'https://' . $href;
    }
    return null;
}

/** Images may come from the admin's own uploads, or from another https:// address. */
function safe_src(string $src): ?string
{
    $src = trim($src);
    $media = preg_quote(url('media/'), '#');
    if (preg_match('#^' . $media . '[a-f0-9]{24}\.(jpg|png|webp|gif)$#', $src) || preg_match('#^https://[^\s"\'<>]+$#i', $src)) {
        return $src;
    }
    return null;
}

/** Cleans post text from the editor: keeps headings, lists, links, images, tables and basic formatting. */
function clean_html(string $html): string
{
    if (strlen($html) > BODY_MAX_BYTES) {
        throw new InvalidArgumentException('The post text is too long.');
    }
    $src = html_root(html_load($html));
    $out = new DOMDocument('1.0', 'UTF-8');
    $root = $out->appendChild($out->createElement('div'));
    clean_children($src, $root, false);
    wrap_loose_inline($root);
    drop_empty_blocks($root);
    return trim(html_inner($root));
}

function clean_children(DOMNode $from, DOMNode $to, bool $inBlock): void
{
    foreach (iterator_to_array($from->childNodes) as $node) {
        if ($node instanceof DOMText) {
            $to->appendChild($to->ownerDocument->createTextNode($node->nodeValue ?? ''));
            continue;
        }
        if (!$node instanceof DOMElement) {
            continue; // comments, processing instructions
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, HTML_DROP, true)) {
            continue;
        }
        $tag = HTML_RENAME[$tag] ?? $tag;
        if (in_array($tag, HTML_WRAPPERS, true)) {
            $hasBlock = false;
            foreach ($node->getElementsByTagName('*') as $d) {
                if (in_array(strtolower($d->tagName), HTML_BLOCKS, true)) {
                    $hasBlock = true;
                    break;
                }
            }
            if ($hasBlock || $inBlock) {
                clean_children($node, $to, $inBlock);
                continue;
            }
            $tag = 'p';
        }
        // A paragraph or heading inside another one (pasted text does this) keeps only its text.
        if ($inBlock && in_array($tag, ['p', 'h2', 'h3', 'h4', 'blockquote', 'figure', 'pre', 'ul', 'ol', 'table'], true) && !in_array(strtolower($to->nodeName), ['li', 'blockquote', 'td', 'th', 'figure'], true)) {
            clean_children($node, $to, $inBlock);
            continue;
        }
        if (!isset(HTML_ALLOWED[$tag])) {
            clean_children($node, $to, $inBlock);
            continue;
        }
        $el = $to->ownerDocument->createElement($tag);
        foreach (HTML_ALLOWED[$tag] as $attr) {
            if (!$node->hasAttribute($attr)) {
                continue;
            }
            $v = trim($node->getAttribute($attr));
            if ($attr === 'href') {
                $v = safe_href($v);
            } elseif ($attr === 'src') {
                $v = safe_src($v);
            } elseif (in_array($attr, ['width', 'height', 'colspan', 'rowspan', 'start'], true)) {
                $v = preg_match('/^\d{1,5}$/', $v) && (int) $v > 0 ? $v : null;
            } elseif ($attr === 'scope') {
                $v = in_array($v, ['row', 'col'], true) ? $v : null;
            } else {
                $v = str_limit($v, 300);
            }
            if ($v !== null && $v !== '') {
                $el->setAttribute($attr, $v);
            }
        }
        if ($tag === 'a' && !$el->hasAttribute('href')) {
            clean_children($node, $to, $inBlock);
            continue;
        }
        if ($tag === 'img') {
            if ($el->hasAttribute('src')) {
                $el->setAttribute('alt', $el->getAttribute('alt'));
                $to->appendChild($el);
            }
            continue;
        }
        $to->appendChild($el);
        $block = in_array($tag, ['p', 'h2', 'h3', 'h4', 'li', 'td', 'th', 'blockquote', 'figure', 'figcaption', 'pre'], true);
        clean_children($node, $el, $inBlock || $block);
    }
}

/** Text typed straight into the editor (not inside a paragraph) gets wrapped in paragraphs. */
function wrap_loose_inline(DOMElement $root): void
{
    $doc = $root->ownerDocument;
    $p = null;
    foreach (iterator_to_array($root->childNodes) as $node) {
        $isBlock = $node instanceof DOMElement && in_array(strtolower($node->tagName), HTML_BLOCKS, true);
        if ($isBlock) {
            $p = null;
            continue;
        }
        if ($node instanceof DOMElement && strtolower($node->tagName) === 'br' && $p === null) {
            $root->removeChild($node);
            continue;
        }
        if ($p === null) {
            if ($node instanceof DOMText && trim($node->nodeValue ?? '') === '') {
                continue;
            }
            $p = $doc->createElement('p');
            $root->insertBefore($p, $node);
        }
        $p->appendChild($node);
    }
}

/** Removes paragraphs and headings with nothing in them (the editor leaves these behind). */
function drop_empty_blocks(DOMElement $root): void
{
    foreach (iterator_to_array($root->childNodes) as $node) {
        if (!$node instanceof DOMElement) {
            if ($node instanceof DOMText && trim($node->nodeValue ?? '') === '') {
                $root->removeChild($node);
            }
            continue;
        }
        if (in_array(strtolower($node->tagName), ['p', 'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol'], true)
            && trim(str_replace("\u{a0}", ' ', $node->textContent)) === '' && $node->getElementsByTagName('img')->length === 0) {
            $root->removeChild($node);
            continue;
        }
        // Trailing line breaks at the end of a paragraph
        while ($node->lastChild instanceof DOMElement && strtolower($node->lastChild->tagName) === 'br') {
            $node->removeChild($node->lastChild);
        }
    }
}

/** True when the text has something a reader would see. */
function html_has_content(string $html): bool
{
    return trim(strip_tags($html, '<img>')) !== '';
}

/** Upload file names (media/<name>) used in a post's text. */
function html_media_files(string $html): array
{
    preg_match_all('#media/([a-f0-9]{24}\.(?:jpg|png|webp|gif))#', $html, $m);
    return array_values(array_unique($m[1]));
}

/**
 * The post text as it goes on the page, done the same way as the posts in the code:
 * site links get the site folder, outside links open in a new tab, and the in-article ads go
 * after the 2nd paragraph and about two thirds of the way down in longer posts.
 */
function render_body(string $html): string
{
    $doc = html_load($html);
    $root = html_root($doc);
    $base = base();
    $siteUrl = rtrim((string) site('url'), '/');
    foreach (iterator_to_array($root->getElementsByTagName('a')) as $a) {
        $href = $a->getAttribute('href');
        if (str_starts_with($href, '/') && !str_starts_with($href, '//') && !str_starts_with($href, $base)) {
            $a->setAttribute('href', $base . substr($href, 1));
        } elseif (preg_match('#^https?://#i', $href) && !str_starts_with($href, $siteUrl)) {
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener');
        }
    }
    foreach (iterator_to_array($root->getElementsByTagName('img')) as $img) {
        $img->setAttribute('loading', 'lazy');
        $img->setAttribute('decoding', 'async');
    }
    $paras = [];
    foreach ($root->childNodes as $node) {
        if ($node instanceof DOMElement && strtolower($node->tagName) === 'p') {
            $paras[] = $node;
        }
    }
    $at = [];
    if (count($paras) >= 3) {
        $at['inArticle'] = $paras[1];
    }
    if (count($paras) >= 8) {
        $at['inArticle2'] = $paras[(int) floor(count($paras) * 0.66)];
    }
    foreach ($at as $name => $after) {
        $mark = $doc->createComment("ad:$name");
        $after->nextSibling ? $root->insertBefore($mark, $after->nextSibling) : $root->appendChild($mark);
    }
    $out = html_inner($root);
    $ads = site('ads') ?? [];
    foreach (['inArticle', 'inArticle2'] as $name) {
        $code = trim((string) ($ads[$name] ?? ''));
        $out = str_replace("<!--ad:$name-->", $code !== '' ? "<div class=\"ad-slot ad-$name\">$code</div>" : '', $out);
    }
    return $out;
}
