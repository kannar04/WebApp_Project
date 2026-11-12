<?php

declare(strict_types=1);

namespace Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('home2home_session');
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);
            session_start();
        }
    }

    public static function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $message;
    }

    /** Keeps refused input for the form it came from; $scope names that form's record (listing:5, new-listing) so no other form takes it. */
    public static function old(array $input, ?string $scope = null): void
    {
        $_SESSION['_old'] = ['scope' => $scope, 'input' => $input];
    }

    /** Called when a session ends or a login starts: one person's refused forms and messages must not reach whoever signs in next on this browser. */
    public static function forgetFormData(): void
    {
        foreach (array_keys($_SESSION) as $key) {
            if ($key === '_flash' || $key === '_message_draft' || str_starts_with((string) $key, '_direct_draft:') || str_ends_with((string) $key, '_old')) {
                unset($_SESSION[$key]);
            }
        }
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    /** The kept input, once: to the form of its own scope, dropped for any other. Input kept with no scope is tied to no record and goes to whichever form asks. */
    public static function pullOld(?string $scope = null): array
    {
        $kept = self::pull('_old', []);
        return ($kept['scope'] ?? null) === null || $kept['scope'] === $scope ? ($kept['input'] ?? []) : [];
    }
}

