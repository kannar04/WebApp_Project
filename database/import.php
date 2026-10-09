<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/core/bootstrap.php';

$config = require dirname(__DIR__) . '/config/database.php';
$appConfig = require dirname(__DIR__) . '/config/app.php';
if ($appConfig['env'] !== 'local' || !in_array($config['host'], ['localhost', '127.0.0.1', '::1'], true)
    || $config['database'] !== 'db_home2home') {
    fwrite(STDERR, "BLOCKED: demo SQL import requires local db_home2home.\n");
    exit(1);
}
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
require __DIR__.'/procedures/install.php';
$count = installHome2HomeProcedures($pdo);
echo "Installed {$count} routine definitions".PHP_EOL;

