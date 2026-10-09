<?php
declare(strict_types=1);

require dirname(__DIR__).'/core/bootstrap.php';
require dirname(__DIR__).'/database/procedures/install.php';

function cleanupExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

// This PDO records DDL only: installer safety is checked without mutating any database.
final class RecordingInstallerPDO extends PDO
{
    public array $definitions = [];
    public function __construct() {}
    public function exec(string $statement): int|false { $this->definitions[] = $statement; return 0; }
}
$recording = new RecordingInstallerPDO();
$productionCount = installHome2HomeProcedures($recording);
foreach ($recording->definitions as $definition) {
    cleanupExpect(!preg_match('/\bDROP\s+(TABLE|DATABASE|PROCEDURE)\b/i', $definition), 'Installer unexpectedly executes DROP.');
    cleanupExpect(!preg_match('/\bPROCEDURE\s+`?sp_test_/i', $definition), 'Test routines installed without opt-in.');
}
$expectedProduction=75;
foreach (['050_quality_core.sql','055_quality_search.sql','060_quality_community.sql','065_password_recovery.sql'] as $migration) {
    $source=file_get_contents(dirname(__DIR__).'/database/procedures/'.$migration);
    $expectedProduction+=preg_match_all('/CREATE OR REPLACE PROCEDURE/',$source);
}
cleanupExpect($productionCount === $expectedProduction, 'Unexpected production definition count.');
$withTests = new RecordingInstallerPDO();
cleanupExpect(installHome2HomeProcedures($withTests, true) === $expectedProduction+16, 'Test routine opt-in changed.');
echo "PASS installer: $productionCount production definitions, 16 opt-in test definitions; zero DROP\n";

$controller = new App\Controllers\AdminController();
$rolesValidator = new ReflectionMethod($controller, 'rolesAreValid');
foreach ([[], ['guest'], ['guest','host','admin']] as $roles) { cleanupExpect($rolesValidator->invoke($controller, $roles), 'Valid roles rejected.'); }
foreach (['guest', [['admin']], ['unknown'], [null], ['guest','host','admin','guest']] as $roles) {
    cleanupExpect(!$rolesValidator->invoke($controller, $roles), 'Malformed roles accepted.');
}
echo "PASS shared Admin role validation: valid/empty/scalar/nested/unknown/oversized\n";

$root = dirname(__DIR__);
$schema = file_get_contents($root.'/database/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $schema, $tables);
cleanupExpect(count($tables[1]) === 22 && count(array_unique($tables[1])) === 22, 'Duplicate/missing schema definitions.');
foreach (['app','core','public','routes'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php','js'], true)) { continue; }
        $source = file_get_contents($file->getPathname());
        cleanupExpect(!preg_match('/\b(?:var_dump|print_r|mysqli_query)\s*\(|console\.log\s*\(/', $source), 'Runtime debug/direct mysqli found: '.$file->getFilename());
        cleanupExpect(!preg_match('/\b(?:CREATE|ALTER|DROP)\s+(?:TABLE|DATABASE)\b/i', $source), 'Schema DDL found in runtime.');
        if (str_contains($file->getPathname(), 'Controllers') || str_contains($file->getPathname(), 'Views')) {
            cleanupExpect(!preg_match('/->\s*(?:prepare|query|exec)\s*\(/', $source), 'SQL execution found in Controller/View.');
        }
    }
}
echo "PASS 22 unique schema tables; no runtime DDL/debug/mysqli or Controller/View queries\n";

function ignoredByGit(string $path): bool
{
    $process = proc_open(['git','check-ignore','--no-index','-q',$path], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) { throw new RuntimeException('Git could not start.'); }
    foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
    $status = proc_close($process);
    cleanupExpect(in_array($status, [0,1], true), 'git check-ignore failed.');
    return $status === 0;
}
$private = ['.env','.env.local','.env.production','config/database.local.php','secrets/key.pem','logs/app.log','tmp/work.tmp',
    '.idea/workspace.xml','.vscode/settings.json','.DS_Store','Thumbs.db','node_modules/pkg/index.js','vendor/pkg/file.php',
    'coverage/report.html','database/dumps/customer-export.sql','public/uploads/avatar.jpg'];
$shared = ['.env.example','.env.sample','README.md','skill_web.md','skill_UIUX.md','skill_user.md','File_Ideas/Functions.txt',
    'image/erd-home2home.drawio.png','image/so-do-lop_web.png','image/so-do-phan-ra-chuc-nang_web.png',
    'app/Models/Listing.php','core/ProcedureConnection.php','config/database.php','routes/web.php','tests/codebase_cleanup_test.php',
    'database/schema.sql','database/seed.sql','database/procedures/020_booking.sql','database/migrations/example.sql',
    'docs/codebase-cleanup-report.md','public/assets/css/app.css','public/assets/js/app.js',
    'public/assets/images/demo/1600585154340-be6161a56a0c.jpg','public/uploads/.gitkeep','composer.lock','package-lock.json'];
foreach ($private as $path) { cleanupExpect(ignoredByGit($path), 'Private path is shareable: '.$path); }
foreach ($shared as $path) { cleanupExpect(!ignoredByGit($path), 'Shared path is ignored: '.$path); }
echo 'PASS Git rules: '.count($private).' private / '.count($shared)." shareable paths\n";
