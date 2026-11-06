<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require dirname(__DIR__).'/core/bootstrap.php';

$root=dirname(__DIR__);
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
$metadataFiles=['database/tools/routine_catalog.php','database/tools/reassign_routine_owners.php','database/import.php','tests/fresh_setup_test.php','tests/schema_test.php','tests/stored_procedure_audit_test.php'];
$violations=[]; $calls=[];
foreach ($files as $file) {
    if ($file->getExtension()!=='php') { continue; }
    $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
    if (preg_match('~(^|/)(\.git|vendor|node_modules)/|^lang/~',$relative)) { continue; }
    $source=file_get_contents($file->getPathname());
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0]!==T_CONSTANT_ENCAPSED_STRING) { continue; }
        $value=substr($token[1],1,-1);
        if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\s+\S/i',$value)) {
            if (!in_array($relative,$metadataFiles,true) || !str_contains($value,'information_schema.')) {
                $violations[]=$relative.':'.$token[2];
            }
        }
        if (preg_match('/^CALL\s+`?([A-Za-z_][A-Za-z_0-9 ]*?)`?\(([^)]*)\)$/D',$value,$match)) {
            $calls[]=[$relative,$token[2],trim($match[1]),substr_count($match[2],'?')];
        }
    }
}
if ($violations) { throw new RuntimeException('Direct business SQL found: '.implode(', ',$violations)); }
echo "PASS PHP scan: zero direct business SQL literals; metadata exceptions reviewed\n";

// The audit doc is checked by hand against the code, so its tables and fixture counts have to stay readable and true.
$auditLines=file($root.'/docs/stored-procedure-audit.md',FILE_IGNORE_NEW_LINES);
$tableCells=null;
foreach ($auditLines as $index=>$line) {
    if (!str_starts_with($line,'|')) { $tableCells=null; continue; }
    $cells=substr_count((string)preg_replace('/`[^`]*`/','',$line),'|')-1;
    $tableCells??=$cells;
    if ($cells!==$tableCells) { throw new RuntimeException('Audit doc line '.($index+1).' has '.$cells.' cells, its table has '.$tableCells.'.'); }
}
$paymentFixtures=substr_count((string)file_get_contents($root.'/database/procedures/testing_payment.sql'),'CREATE OR REPLACE PROCEDURE');
if (!str_contains(implode("\n",$auditLines),'Test fixture routines mới ('.$paymentFixtures.', chỉ cài trong DB test riêng): `testing_payment.sql`')) {
    throw new RuntimeException('Audit doc does not say testing_payment.sql has '.$paymentFixtures.' fixtures.');
}
echo "PASS audit doc: every table row has its header's cell count; the payment fixture count matches the file\n";

/** Routine names defined in SQL files, read from the source so a routine added by any branch is covered. */
function auditRoutineNames(array $files): array
{
    $names=[];
    foreach ($files as $file) {
        $source=(string)file_get_contents($file);
        $found=preg_match_all('/\bCREATE\s+(?:OR\s+REPLACE\s+)?PROCEDURE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`(]+?)`?\s*\(/i',$source,$match);
        if ($found!==preg_match_all('/\bCREATE\s+(?:OR\s+REPLACE\s+)?PROCEDURE\b/i',$source)) { throw new RuntimeException('Could not read every routine name in '.basename($file).'.'); }
        $names=array_merge($names,$match[1]);
    }
    return array_values(array_unique($names));
}
require_once $root.'/database/procedures/install.php';
$productionRoutines=auditRoutineNames(home2HomeProcedureFiles());
// Mapped means a backticked name on a table row; any backticked routine name in the doc has to exist, including sp_test_ fixtures.
preg_match_all('/`([^`]+)`/',implode("\n",preg_grep('/^\|/',$auditLines)),$tableNames);
$unmapped=array_diff($productionRoutines,$tableNames[1]);
if ($unmapped) { throw new RuntimeException('Production routines missing from docs/stored-procedure-audit.md: '.implode(', ',$unmapped)); }
preg_match_all('/`((?:[A-Z]{2,4}_ ?)?sp_[A-Za-z0-9_]+)`/',implode("\n",$auditLines),$docNames);
$staleNames=array_diff(array_unique($docNames[1]),auditRoutineNames(home2HomeProcedureFiles(true)));
if ($staleNames) { throw new RuntimeException('docs/stored-procedure-audit.md names routines that no longer exist: '.implode(', ',$staleNames)); }
echo 'PASS audit doc maps all '.count($productionRoutines)." production routines and names none that no longer exist\n";

// Read-only schema metadata is an explicit administrative audit exception.
$pdo=\Core\Database::connection();
$rows=$pdo->query('SELECT r.ROUTINE_NAME,COUNT(p.ORDINAL_POSITION) parameter_count FROM information_schema.ROUTINES r LEFT JOIN information_schema.PARAMETERS p ON p.SPECIFIC_SCHEMA=r.ROUTINE_SCHEMA AND p.SPECIFIC_NAME=r.ROUTINE_NAME WHERE r.ROUTINE_SCHEMA=DATABASE() AND r.ROUTINE_TYPE=\'PROCEDURE\' GROUP BY r.ROUTINE_NAME')->fetchAll(PDO::FETCH_ASSOC);
$signatures=array_column($rows,'parameter_count','ROUTINE_NAME');
foreach ($calls as [$file,$line,$name,$count]) {
    if (!array_key_exists($name,$signatures) || (int)$signatures[$name]!==$count) {
        throw new RuntimeException("Missing/mismatched procedure {$name} at {$file}:{$line}");
    }
}
echo 'PASS '.count($calls).' CALL sites / '.count(array_unique(array_column($calls,2)))." routine signatures against the live database\n";

$adapter=\Core\ProcedureConnection::connection();
try { $adapter->prepare('not_a_call'); throw new RuntimeException('Adapter allowed a non-CALL statement.'); }
catch (InvalidArgumentException $expected) {}
for ($i=0;$i<50;$i++) {
    $statement=$adapter->prepare('CALL `DST_ sp_list_amenities`()');
    $statement->execute(); $statement->fetch();
    $adapter->query('CALL LTP_sp_list_cancellation_policies()')->fetchAll();
}
echo "PASS CALL-only guard and 100 sequential routines with partially consumed caller results\n";

$user=$adapter->prepare('CALL sp_user_get_by_email(?)');
$user->execute(['guest@home2home.test']);
$guest=$user->fetch();
$cities=$adapter->prepare('CALL LTP_sp_search_listings_by_city(?)');
$cities->execute([null]);
$listings=$cities->fetchAll();
if (!$guest || !$listings) { throw new RuntimeException('Local demo records required for routine compatibility tests.'); }
$cities->execute([$listings[0]['city']]);
if (!$cities->fetch()) { throw new RuntimeException('Legacy city search is incompatible with existing collations.'); }
$notifications=$adapter->prepare('CALL NMT_sp_get_user_notifications(?)');
$notifications->execute([(int)$guest['id']]); $notifications->fetchAll();
$read=$adapter->prepare('CALL NMT_sp_mark_notification_read(?,?)');
$read->execute([(int)$guest['id'],0]);
if ((int)$read->fetchColumn()!==0) { throw new RuntimeException('A missing notification was changed.'); }
echo "PASS legacy city/notification read routines and repaired notification parameter\n";
