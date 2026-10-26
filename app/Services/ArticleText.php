<?php
declare(strict_types=1);
namespace App\Services;

/** Plain-text helpers for articles: Vietnamese slugs, safe paragraph formatting and summaries. */
final class ArticleText
{
    public const CATEGORIES = ['news' => 'Tin tức', 'guide' => 'Cẩm nang du lịch'];
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    private const SLUG_MAX = 180;
    private const FOLD = [
        'a' => 'àáạảãâầấậẩẫăằắặẳẵ', 'e' => 'èéẹẻẽêềếệểễ', 'i' => 'ìíịỉĩ', 'o' => 'òóọỏõôồốộổỗơờớợởỡ',
        'u' => 'ùúụủũưừứựửữ', 'y' => 'ỳýỵỷỹ', 'd' => 'đ',
    ];

    /** Lowercase ASCII slug without Vietnamese diacritics; empty string when nothing usable remains. */
    public static function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $map = [];
        foreach (self::FOLD as $ascii => $letters) {
            foreach (preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY) as $letter) { $map[$letter] = $ascii; }
        }
        $text = strtr($text, $map);
        // Decomposed input (base letter + combining mark) loses only the marks.
        $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if (strlen($text) > self::SLUG_MAX) {
            $text = substr($text, 0, self::SLUG_MAX);
            $cut = strrpos($text, '-');
            if ($cut !== false && $cut > 40) { $text = substr($text, 0, $cut); }
            $text = trim($text, '-');
        }
        return $text;
    }

    /** Escapes everything first; only <p>, <br> and <h2> produced here ever reach the page. */
    public static function toHtml(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));
        $html = '';
        foreach (preg_split('/\n[ \t]*\n+/', $body) as $block) {
            $lines = explode("\n", trim($block));
            if (str_starts_with($lines[0], '## ')) {
                $heading = trim(substr(array_shift($lines), 3));
                if ($heading !== '') { $html .= '<h2>'.e($heading).'</h2>'; }
            }
            $paragraph = trim(implode("\n", $lines));
            if ($paragraph !== '') { $html .= '<p>'.nl2br(e($paragraph), false).'</p>'; }
        }
        return $html;
    }

    /** First words of the body without heading markers, for cards and meta descriptions. */
    public static function excerptFrom(string $body, int $limit = 200): string
    {
        $text = (string) preg_replace('/^##\s+/m', '', $body);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $limit) { return $text; }
        $text = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($text, ' ');
        if ($space !== false && $space > $limit / 2) { $text = mb_substr($text, 0, $space); }
        return rtrim($text, " ,.;:-").'…';
    }

    public static function readingMinutes(string $body): int
    {
        $words = preg_split('/\s+/u', trim($body), -1, PREG_SPLIT_NO_EMPTY);
        return max(1, (int) ceil(count($words) / 220));
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? $category;
    }

    public static function date(?string $value): string
    {
        $time = $value ? strtotime($value) : false;
        return $time ? date('d/m/Y', $time) : '';
    }
}