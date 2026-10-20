<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/core/bootstrap.php';
require __DIR__.'/procedures/install.php';
$app = require dirname(__DIR__).'/config/app.php';
$config = require dirname(__DIR__).'/config/database.php';
if ($app['env'] !== 'local' || !in_array($config['host'], ['localhost','127.0.0.1','::1'], true)
    || $config['database'] !== 'db_home2home') {
    fwrite(STDERR, "BLOCKED: installer is restricted to local db_home2home.\n"); exit(1);
}
$count = installHome2HomeProcedures(\Core\Database::connection(), in_array('--include-tests', $argv, true));
echo "Installed {$count} routine definitions; no DROP, data or grants changed.\n";
