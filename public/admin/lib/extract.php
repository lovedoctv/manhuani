<?php
defined('MANHUANI') || exit;

/**
 * Pulls "series title" and "chapter number" out of a feed item title, e.g.
 *   "Dungeon Chef Chapter 19"          -> Dungeon Chef / 19
 *   "Sword Saint – Ch. 24.5"           -> Sword Saint / 24.5
 *   "Chapter 12 - Moonlit Pavilion"    -> Moonlit Pavilion / 12
 *   "Chapter 7" in a per-series feed   -> <feed title> / 7
 * @return array{series:string, number:?float, label:string}
 */
function extract_chapter(string $title, string $feedTitle = ''): array
{
    $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
    $kw = '(?:chapter|chap\.?|ch\.?|episode|ep\.?|#)';
    $num = '(\d{1,5}(?:[.,]\d{1,2})?)';

    // "Series ... Chapter 12 ..."
    if (preg_match("/^(.*?)[\s\-–—:|,·]*\b$kw\s*$num\b(.*)$/iu", $title, $m) && trim($m[1]) !== '') {
        $number = (float) str_replace(',', '.', $m[2]);
        $series = $m[1];
        // Some feeds repeat the chapter number in front: "22 — Series Name – Chapter 22".
        if (preg_match('/^\s*(\d{1,5}(?:[.,]\d{1,2})?)\s*[\-–—:|.]\s+(.+)$/u', $series, $pm)
            && (float) str_replace(',', '.', $pm[1]) === $number) {
            $series = $pm[2];
        }
        return [
            'series' => clean_series($series),
            'number' => $number,
            'label' => clean_label($m[3]),
        ];
    }
    // "Chapter 12 - Series" or just "Chapter 12" (per-series feed)
    if (preg_match("/^$kw\s*$num\b\s*[\-–—:|]?\s*(.*)$/iu", $title, $m)) {
        $rest = trim($m[2]);
        $useFeed = $feedTitle !== '' && ($rest === '' || mb_strlen($rest) < 3);
        return [
            'series' => clean_series($useFeed ? $feedTitle : ($rest !== '' ? $rest : $feedTitle)),
            'number' => (float) str_replace(',', '.', $m[1]),
            'label' => '',
        ];
    }
    return ['series' => clean_series($title), 'number' => null, 'label' => ''];
}

function clean_series(string $s): string
{
    $s = trim($s, " \t-–—:|,·.");
    $s = preg_replace('/\s*[\[(](?:manhua|manhwa|manga|webtoon|raw|eng|english)[\])]\s*$/iu', '', $s) ?? $s;
    return str_limit($s, 200);
}

function clean_label(string $s): string
{
    $s = trim($s, " \t-–—:|,·");
    return str_limit($s, 200);
}
