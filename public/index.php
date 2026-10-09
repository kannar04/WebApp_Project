<?php

declare(strict_types=1);

use Core\Session;

require dirname(__DIR__) . '/core/bootstrap.php';

Session::start();

$router = require dirname(__DIR__) . '/routes/web.php';

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $exception) {
    http_response_code(500);
    $config = require dirname(__DIR__) . '/config/app.php';
    if ($config['debug']) {
        echo '<pre>' . e((string) $exception) . '</pre>';
    } else {
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
            (new \Core\Controller())->json(false, 'Home2Home đang tạm thời gặp sự cố. Vui lòng thử lại.', null, [], 500);
        }
        // This standalone view remains usable even when authentication/database lookup fails.
        require dirname(__DIR__) . '/app/Views/errors/500.php';
    }
}

