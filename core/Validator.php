<?php

declare(strict_types=1);

namespace Core;

final class Validator
{
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

