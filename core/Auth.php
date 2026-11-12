<?php

declare(strict_types=1);

namespace Core;

use App\Models\User;

final class Auth
{
    private static ?array $resolvedUser = null;
    private static bool $resolved = false;

    public static function attempt(string $email, string $password): bool
    {
        $user = (new User())->findByEmail($email);
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        return self::signIn((int)$user['id']);
    }

    /** Called only after password verification or a server-verified OAuth identity. */
    public static function signIn(int $userId): bool
    {
        $user=(new User())->findWithRoles($userId);
        if($user===null){return false;}
        session_regenerate_id(true);
        Session::forgetFormData();
        unset($_SESSION['_google_oauth'],$_SESSION['_facebook_oauth']);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['auth_version'] = $user['auth_version'];
        self::$resolvedUser = $user;
        self::$resolved = true;
        return true;
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$resolvedUser;
        }
        self::$resolved = true;
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        self::$resolvedUser = (new User())->findWithRoles((int) $_SESSION['user_id']);
        // Suspended, removed or password changed elsewhere: this session ends here, once, so messages set after this point survive.
        if (!self::$resolvedUser || !hash_equals(self::$resolvedUser['auth_version'],(string)($_SESSION['auth_version']??''))) {
            self::$resolvedUser=null;
            unset($_SESSION['user_id'],$_SESSION['auth_version']);
            Session::forgetFormData();
        }
        return self::$resolvedUser;
    }

    public static function id(): ?int { return self::user()['id'] ?? null; }
    public static function check(): bool { return self::user() !== null; }
    public static function hasRole(string $role): bool { return in_array($role, self::user()['roles'] ?? [], true); }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        // Start a fresh anonymous session/cookie so post-logout confirmation survives redirects.
        session_id('');
        Session::start();
        session_regenerate_id(true);
        self::$resolvedUser = null;
        self::$resolved = true;
    }
}

