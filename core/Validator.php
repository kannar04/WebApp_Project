<?php

declare(strict_types=1);

namespace Core;

final class Validator
{
    public const PASSWORD_RULE = 'Mật khẩu cần dài 8–72 byte (chữ có dấu tính 2–3 byte) và không chứa ký tự NUL.';
    public const MONEY_MAX = 9999999999.99;

    /**
     * A price, fee or percent on any form: digits with up to 2 decimals after a dot, so the Vietnamese 500.000 is refused instead of read as 500.
     * Blank gives null: each form decides what blank means. Returns the number as text, for the DECIMAL parameter of a routine.
     */
    public static function decimal(string $value, float $minimum, float $maximum, string $message): ?string
    {
        $value = trim($value);
        if ($value === '') { return null; }
        if (!self::isDecimal($value) || (float) $value < $minimum || (float) $value > $maximum) { throw new \DomainException($message); }
        return $value;
    }

    public static function isDecimal(string $value): bool
    {
        return preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $value) === 1;
    }

    /**
     * The nightly price and cleaning fee of the Host and Admin listing forms. Prices are charged in whole dong, so one under 0.5 would make the nights free.
     * A blank price fails that minimum; a blank fee means no fee. Throws before changing anything, so the caller still holds what was typed.
     */
    public static function listingPrices(array $data): array
    {
        $data['nightly_price'] = self::decimal($data['nightly_price'] ?: '0', 0.5, self::MONEY_MAX, 'Giá mỗi đêm phải từ 1đ trở lên. Viết liền không dấu chấm, ví dụ 500000.');
        $data['cleaning_fee'] = self::decimal($data['cleaning_fee'] ?: '0', 0, self::MONEY_MAX, 'Phụ phí không được âm. Viết liền không dấu chấm, ví dụ 50000.');
        return $data;
    }

    /** For setting a password only: bcrypt ignores what follows byte 72 or a NUL. Login stays lenient so older, longer passwords still open. */
    public static function passwordAllowed(string $password): bool
    {
        return strlen($password) >= 8 && strlen($password) <= 72 && !str_contains($password, "\0");
    }

    public static function required(array $data, array $fields): array
    {
        $errors = [];
        foreach ($fields as $field => $label) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                $errors[$field] = $label . ' là bắt buộc.';
            }
        }
        return $errors;
    }
}

