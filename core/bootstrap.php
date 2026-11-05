<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => dirname(__DIR__) . '/app/',
        'Core\\' => __DIR__ . '/',
    ];
    foreach ($prefixes as $prefix => $baseDirectory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDirectory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

$app = require dirname(__DIR__) . '/config/app.php';
date_default_timezone_set($app['timezone']);
configure_error_output($app['debug']);

/** PHP errors go to the log and reach a web response only under APP_DEBUG. The CLI keeps php.ini's setting so tests and tools still print their failures. */
function configure_error_output(bool $debug, string $sapi = PHP_SAPI): void
{
    if ($sapi !== 'cli') {
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
    }
}

/** Request text from $_GET or $_POST: an absent or array-shaped value (name[]=1) gives $default. */
function input_text(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $configured = (require dirname(__DIR__) . '/config/app.php')['url'];
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $base = $scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/');
    return $base . '/' . ltrim($path, '/');
}

/**
 * A URL path as url() expects it: relative to the app, so the base is added back exactly once.
 * The root .htaccess rewrites /folder/x into public/ while the address stays /folder/x, so under
 * /folder/public both that and /folder count as the base. Null when the path lies outside the app.
 */
function app_path(string $path): ?string
{
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($base === '/' || $base === '.') { $base = ''; }
    $segments = explode('/', $path);
    foreach (str_ends_with($base, '/public') ? [$base, substr($base, 0, -7)] : [$base] as $candidate) {
        $depth = substr_count($candidate, '/') + 1;
        // Browsers percent-encode the address (/My%20Folder) while SCRIPT_NAME is decoded.
        if (rawurldecode(implode('/', array_slice($segments, 0, $depth))) === $candidate) {
            return '/' . implode('/', array_slice($segments, $depth));
        }
    }
    return null;
}

function asset(string $path): string { return url('/assets/' . ltrim($path, '/')); }
function old(string $key, mixed $default = ''): mixed { return $GLOBALS['old'][$key] ?? $default; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . e(\Core\Csrf::token()) . '">'; }
/** Interface text: Vietnamese source in, current-locale text out (see core/I18n.php). */
function __(string $text, array $params = []): string { return \Core\I18n::translate($text, $params); }

/** Display label for a stored status code (booking, listing, user, review); unknown codes are shown as stored. */
function status_label(?string $code): string
{
    $labels = [
        'draft' => __('Bản nháp'), 'pending' => __('Chờ duyệt'), 'approved' => __('Đã duyệt'), 'rejected' => __('Bị từ chối'),
        'confirmed' => __('Đã xác nhận'), 'cancelled' => __('Đã hủy'), 'completed' => __('Hoàn thành'),
        'active' => __('Đang hoạt động'), 'suspended' => __('Tạm khóa'), 'deleted' => __('Đã xóa'),
        'visible' => __('Đang hiển thị'), 'hidden' => __('Đã ẩn'),
    ];
    return $labels[(string) $code] ?? (string) $code;
}

/** "2026-10-13" -> "13/10/2026"; with $withTime, "2026-10-13 14:25:58.624797" -> "13/10/2026 14:25". */
function display_date(?string $value, bool $withTime = false): string
{
    return $withTime ? \App\Services\ConversationService::displayTime($value) : \App\Services\ConversationService::displayDate($value);
}

/** Decimal fraction in the current locale: 4,5 in Vietnamese, 4.5 in English; $trim drops trailing zeros. */
function display_decimal(float $value, int $decimals = 1, bool $trim = false): string
{
    $point = \Core\I18n::locale() === 'en' ? '.' : ',';
    $text = number_format($value, $decimals, $point, $point === '.' ? ',' : '.');
    return $trim && $decimals > 0 ? rtrim(rtrim($text, '0'), $point) : $text;
}

/** Resolve database image paths under the configured app base, preserving remote URLs. */
function image_url(?string $path): string
{
    if (!$path) { return asset('images/placeholder.svg'); }
    if (preg_match('#^https?://#i', $path)) { return $path; }
    return url('/'.ltrim($path, '/'));
}

