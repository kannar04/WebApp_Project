<?php

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';
require dirname(__DIR__) . '/database/procedures/install.php';

$config = require dirname(__DIR__) . '/config/database.php';
$appConfig = require dirname(__DIR__) . '/config/app.php';
if ($appConfig['env'] !== 'local' || !in_array($config['host'], ['localhost','127.0.0.1','::1'], true)) {
    throw new RuntimeException('Schema fixtures require a local loopback database.');
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
);
$testDatabase = 'db_home2home_schema_test_' . bin2hex(random_bytes(6));

try {
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Không đọc được schema.sql');
    }
    $schema = str_replace('db_home2home', $testDatabase, $schema);
    $pdo->exec($schema);
    $seed = file_get_contents(dirname(__DIR__) . '/database/seed.sql');
    if ($seed === false) { throw new RuntimeException('Không đọc được seed.sql'); }
    $seed = str_replace('db_home2home', $testDatabase, $seed);
    $pdo->exec($seed);
    $pdo->exec($seed);
    installHome2HomeProcedures($pdo, true);
    $statement = $pdo->query('CALL sp_test_seed_counts()');
    $seedCounts = $statement->fetch(PDO::FETCH_ASSOC);
    while ($statement->nextRowset()) {}
    $statement->closeCursor();
    if ((int) $seedCounts['photos'] !== 5 || (int) $seedCounts['amenities'] !== 6) {
        throw new RuntimeException('Seed lặp lại không đúng hoặc danh mục bị inactive.');
    }
    $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$testDatabase}'")->fetchColumn();
    if ($count !== 22) {
        throw new RuntimeException('Schema thiếu bảng: chỉ tạo được ' . $count);
    }
    echo "PASS schema: {$count} tables created; SQL seed repeat safe" . PHP_EOL;
    if (in_array('--compare-live', $argv, true)) {
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (!preg_match('/^[a-z][a-z_0-9]*$/D', $table) || !preg_match('/^[a-z][a-z_0-9]*$/D', $config['database'])) {
                throw new RuntimeException('Unexpected table/schema identifier.');
            }
            $actual = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
            $baseline = $pdo->query('SHOW CREATE TABLE `'.$config['database'].'`.`'.$table.'`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
            $normalize = static fn(string $ddl): string => preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
            if ($normalize($actual) !== $normalize($baseline)) { throw new RuntimeException('Schema parity failed: '.$table); }
        }
        echo "PASS exact SHOW CREATE TABLE parity for 22 tables (excluding generated sequence counters)\n";
    }
    $guestStatement=$pdo->prepare('CALL sp_user_get_by_email(?)');
    $guestStatement->execute(['guest@home2home.test']);
    $guest=$guestStatement->fetch(PDO::FETCH_ASSOC);
    while ($guestStatement->nextRowset()) {}
    $guestStatement->closeCursor();
    $historical=$pdo->prepare('CALL sp_test_historical_booking(?,?)');
    $historical->execute([1,(int)$guest['id']]);
    $bookingId=(int)$historical->fetchColumn();
    while ($historical->nextRowset()) {}
    $historical->closeCursor();
    $review=$pdo->prepare('CALL sp_review_create(?,?,?,?)');
    $review->execute([5,'Completed-stay test review',$bookingId,(int)$guest['id']]);
    $created=(int)$review->fetch(PDO::FETCH_ASSOC)['__affected'];
    while ($review->nextRowset()) {}
    $review->closeCursor();
    $review->execute([4,'Duplicate review must be rejected',$bookingId,(int)$guest['id']]);
    $duplicate=(int)$review->fetch(PDO::FETCH_ASSOC)['__affected'];
    while ($review->nextRowset()) {}
    $review->closeCursor();
    $review->execute([4,'Other guest must be rejected',$bookingId,1]);
    $otherGuest=(int)$review->fetch(PDO::FETCH_ASSOC)['__affected'];
    while ($review->nextRowset()) {}
    $review->closeCursor();
    if ($created!==1 || $duplicate!==0 || $otherGuest!==0) { throw new RuntimeException('Review eligibility or uniqueness failed.'); }
    $reviews=$pdo->prepare('CALL NTK_sp_get_listing_reviews(?)');
    $reviews->execute([1]);
    $visible=$reviews->fetch(PDO::FETCH_ASSOC);
    while ($reviews->nextRowset()) {}
    $reviews->closeCursor();
    if (!$visible || !isset($visible['guest_name']) || (int)$visible['rating']!==5) { throw new RuntimeException('Review display projection failed.'); }
    echo "PASS completed-stay review, duplicate/ownership guards and review projection in isolated database".PHP_EOL;
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS ' . $testDatabase);
}

