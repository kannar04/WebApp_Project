<?php

declare(strict_types=1);

namespace Core;

/**
 * Vietnamese/English interface. Vietnamese source text is the key: views call __('Đăng nhập'),
 * lang/en/*.php map that text to English, and missing keys fall back to the Vietnamese source.
 * Runtime messages with variable parts (procedure SIGNAL text, notifications, flash messages)
 * are matched against the {placeholder} templates in lang/en/patterns*.php.
 */
final class I18n
{
    public const LOCALES = ['vi', 'en'];
    public const COOKIE = 'h2h_locale';
    private const PATTERN_PREFIX = 'patterns';
    private const SCRIPT_FILE = 'js.php';

    private static ?string $locale = null;
    private static ?array $dictionary = null;
    private static ?array $patterns = null;

    public static function locale(): string
    {
        if (self::$locale === null) {
            $requested = $_COOKIE[self::COOKIE] ?? 'vi';
            self::$locale = is_string($requested) && in_array($requested, self::LOCALES, true) ? $requested : 'vi';
        }
        return self::$locale;
    }

    /** Used by the locale switch for the current response and by CLI tests. */
    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::LOCALES, true) ? $locale : 'vi';
    }

    public static function remember(string $locale): void
    {
        self::setLocale($locale);
        setcookie(self::COOKIE, self::$locale, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    /** Translates Vietnamese source text; {name} placeholders are filled from $params in either locale. */
    public static function translate(string $text, array $params = []): string
    {
        $result = $text;
        if (self::locale() === 'en' && $text !== '') {
            $dictionary = self::dictionary();
            $result = $dictionary[$text] ?? self::matchPattern($text) ?? $text;
        }
        if ($params === []) {
            return $result;
        }
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }
        return strtr($result, $replacements);
    }

    /** All exact vi => en entries from lang/en/*.php except the pattern file. */
    public static function dictionary(): array
    {
        if (self::$dictionary === null) {
            self::$dictionary = [];
            foreach (glob(self::directory() . '/*.php') ?: [] as $file) {
                if (str_starts_with(basename($file), self::PATTERN_PREFIX)) {
                    continue;
                }
                $entries = require $file;
                if (is_array($entries)) {
                    self::$dictionary += $entries;
                }
            }
        }
        return self::$dictionary;
    }

    /** Template entries such as 'Booking #{id} đã được xác nhận.' => 'Booking #{id} has been confirmed.' */
    public static function patterns(): array
    {
        if (self::$patterns === null) {
            self::$patterns = [];
            foreach (glob(self::directory() . '/' . self::PATTERN_PREFIX . '*.php') ?: [] as $file) {
                $entries = require $file;
                if (is_array($entries)) {
                    self::$patterns += $entries;
                }
            }
        }
        return self::$patterns;
    }

    /** English strings for public/assets/js, emitted as JSON by the layouts; empty in Vietnamese. */
    public static function scriptDictionary(): array
    {
        if (self::locale() !== 'en') {
            return [];
        }
        $file = self::directory() . '/' . self::SCRIPT_FILE;
        $entries = is_file($file) ? require $file : [];
        return is_array($entries) ? $entries : [];
    }

    /** Current request path relative to the app base, for the locale switch to return to. */
    public static function returnPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($scriptDirectory !== '/' && $scriptDirectory !== '.' && str_starts_with($path, $scriptDirectory)) {
            $path = substr($path, strlen($scriptDirectory)) ?: '/';
        }
        $query = parse_url($uri, PHP_URL_QUERY);
        return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    /** Accepts only same-site relative paths; anything else returns to the home page. */
    public static function safeReturnPath(mixed $path): string
    {
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) || strlen($path) > 2000) {
            return '/';
        }
        return $path;
    }

    /** Compiles a template's {name} slots into a regex and fills the English template with the captures. */
    public static function matchPattern(string $text): ?string
    {
        foreach (self::patterns() as $source => $target) {
            $slots = [];
            $regex = self::compile((string) $source, $slots);
            if ($slots === [] || !preg_match($regex, $text, $matches)) {
                continue;
            }
            $replacements = [];
            foreach ($slots as $slot) {
                $value = $matches[$slot] ?? '';
                // Captured values may themselves be translatable text (status labels, nested reasons).
                $replacements['{' . $slot . '}'] = self::dictionary()[$value] ?? $value;
            }
            return strtr((string) $target, $replacements);
        }
        return null;
    }

    private static function compile(string $template, array &$slots): string
    {
        $parts = preg_split('/\{([a-z_][a-z0-9_]*)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$template];
        $regex = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $regex .= preg_quote($part, '/');
            } else {
                $slots[] = $part;
                $regex .= '(?<' . $part . '>.+?)';
            }
        }
        return '/^' . $regex . '$/su';
    }

    private static function directory(): string
    {
        return dirname(__DIR__) . '/lang/en';
    }
}
