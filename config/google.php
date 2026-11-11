<?php
declare(strict_types=1);

$app = require __DIR__.'/app.php';
return [
    'client_id' => trim((string)(getenv('GOOGLE_CLIENT_ID') ?: '')),
    'client_secret' => trim((string)(getenv('GOOGLE_CLIENT_SECRET') ?: '')),
    'redirect_uri' => trim((string)(getenv('GOOGLE_REDIRECT_URI') ?: ($app['url'] !== '' ? $app['url'].'/auth/google/callback' : ''))),
];
