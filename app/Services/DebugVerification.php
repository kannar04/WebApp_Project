<?php
declare(strict_types=1);
namespace App\Services;

/** Local, session-only simulation. Never writes verification records. */
final class DebugVerification
{
    public static function available(): bool
    {
        return getenv('APP_ENV')==='local'
            && filter_var(getenv('DEBUG_VERIFICATION_ENABLED')?:'false',FILTER_VALIDATE_BOOL)
            && in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true);
    }

    public static function enabled(): bool
    {
        return self::available() && !empty($_SESSION['_debug_verification']['enabled']);
    }

    public static function verified(string $kind, int $userId): bool
    {
        return self::enabled() && ($_SESSION['_debug_verification']['user_id']??null)===$userId
            && !empty($_SESSION['_debug_verification'][$kind]);
    }
}
