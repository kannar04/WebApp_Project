<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/config/database.php';
$dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']);
$pdo = new PDO($dsn, $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
]);

$files = in_array('--seed-only', $argv, true) ? ['seed.sql'] : ['schema.sql', 'seed.sql'];
foreach ($files as $file) {
    $sql = file_get_contents(__DIR__ . '/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Không thể đọc ' . $file);
    }
    $pdo->exec($sql);
    echo 'Imported ' . $file . PHP_EOL;
}

