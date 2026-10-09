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
$columns = $pdo->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll();
echo json_encode(compact('routines','columns'), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
