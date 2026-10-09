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

function asset(string $path): string { return url('/assets/' . ltrim($path, '/')); }
function old(string $key, mixed $default = ''): mixed { return $GLOBALS['old'][$key] ?? $default; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . e(\Core\Csrf::token()) . '">'; }

