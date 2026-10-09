<?php
declare(strict_types=1);
// Read-only metadata export for migration review; never dumps application data.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 2).'/core/bootstrap.php';
$pdo = \Core\Database::connection();
$routines = $pdo->query("SELECT ROUTINE_NAME,ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_TYPE='PROCEDURE' ORDER BY ROUTINE_NAME")->fetchAll();
foreach ($routines as &$routine) {
    $statement = $pdo->prepare('SELECT PARAMETER_NAME,DTD_IDENTIFIER FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE() AND SPECIFIC_NAME=? ORDER BY ORDINAL_POSITION');
    $statement->execute([$routine['ROUTINE_NAME']]); $routine['parameters'] = $statement->fetchAll();
}
$columns = $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll();
$foreignKeys = $pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll();
$indexes = $pdo->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll();
$tables = [];
foreach (array_unique(array_column($columns, 'TABLE_NAME')) as $table) {
    if (!preg_match('/^[a-z][a-z_0-9]*$/D', $table)) { throw new RuntimeException('Unexpected metadata table identifier.'); }
    $definition = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch();
    $tables[$table] = $definition['Create Table'];
}
echo json_encode(compact('routines','columns','foreignKeys','indexes','tables'), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
