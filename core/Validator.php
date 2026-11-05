<?php

declare(strict_types=1);

namespace Core;

final class Validator
{
    public const PASSWORD_RULE = 'Mật khẩu cần dài 8–72 byte (chữ có dấu tính 2–3 byte) và không chứa ký tự NUL.';

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

