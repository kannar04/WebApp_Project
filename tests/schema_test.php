<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
);
$testDatabase = 'db_home2home_schema_test';
$pdo->exec('DROP DATABASE IF EXISTS ' . $testDatabase);

try {
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Không đọc được schema.sql');
    }
    $schema = str_replace('db_home2home', $testDatabase, $schema);
    $pdo->exec($schema);
    $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$testDatabase}'")->fetchColumn();
    if ($count < 18) {
        throw new RuntimeException('Schema thiếu bảng: chỉ tạo được ' . $count);
    }
    echo "PASS schema: {$count} tables created" . PHP_EOL;
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS ' . $testDatabase);
}

