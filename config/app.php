<?php

declare(strict_types=1);

return [
    'name' => 'Home2Home',
    'env' => getenv('APP_ENV') ?: 'local',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
    'url' => rtrim(getenv('APP_URL') ?: '', '/'),
    'timezone' => 'Asia/Ho_Chi_Minh',
    'upload_path' => dirname(__DIR__) . '/public/uploads',
    'upload_max_bytes' => 5 * 1024 * 1024,
];

