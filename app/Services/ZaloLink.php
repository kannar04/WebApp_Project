<?php
declare(strict_types=1);
namespace App\Services;

/** Builds a zalo.me chat link from a stored phone number; only Vietnamese mobile numbers qualify. */
final class ZaloLink
{
    public static function url(?string $phone): ?string
    {
        if ($phone === null) { return null; }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0084')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '84') && strlen($digits) === 11) {
            $digits = '0'.substr($digits, 2);
        } elseif (strlen($digits) === 9 && $digits[0] !== '0') {
            $digits = '0'.$digits;
        }
        // Vietnamese mobile ranges: 03x, 05x, 07x, 08x, 09x followed by 8 digits.
        return preg_match('/^0[35789]\d{8}$/D', $digits) === 1 ? 'https://zalo.me/'.$digits : null;
    }
}
