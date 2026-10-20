<?php
declare(strict_types=1);

// Read-only inventory. Candidate references are evidence for review, never deletion authority.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$root = dirname(__DIR__, 2);
$files = []; $phpSources = []; $definitions = []; $phpCalls = []; $hashes = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) { continue; }
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('~(^|/)(\.git|vendor|node_modules)/~', $path)) { continue; }
    $role = match (true) {
        str_starts_with($path, 'app/Controllers/') => 'HTTP controller',
        str_starts_with($path, 'app/Models/') => 'CALL model/repository',
        str_starts_with($path, 'app/Services/') => 'Business/upload service',
        str_starts_with($path, 'app/Views/') => 'MVC view/layout',
        str_starts_with($path, 'core/') => 'Shared runtime core',
        str_starts_with($path, 'config/'), str_starts_with($path, '.env') => 'Configuration',
        str_starts_with($path, 'tests/') => 'Regression test/fixture',
        str_starts_with($path, 'database/procedures/') => 'Routine definition/migration/loader',
        str_starts_with($path, 'database/seeds/') => 'Safe demo image seeder',
        str_starts_with($path, 'database/tools/') => 'Read-only audit utility',
        $path === 'database/schema.sql' => 'Canonical initialization schema',
        $path === 'database/seed.sql' => 'Local demo seed (upserts)',
        str_starts_with($path, 'database/') => 'CLI database installation',
        str_starts_with($path, 'routes/') => 'Dynamic route registry',
        str_starts_with($path, 'public/assets/') => 'Frontend/design/demo asset',
        $path === 'public/uploads/.gitkeep' => 'Runtime upload directory placeholder',
        str_starts_with($path, 'public/uploads/') => 'Private runtime user upload',
        str_starts_with($path, 'public/') => 'Web entry/rewrite',
        str_starts_with($path, 'image/') => 'Source diagram/design evidence',
        str_starts_with($path, 'docs/'), str_starts_with($path, 'File_Ideas/'), str_starts_with($path, 'skill_'), $path === 'README.md' => 'Shared team documentation',
        $path === '.gitignore' => 'Team Git rules',
        default => 'REVIEW_REQUIRED',
    };
    $files[] = ['file'=>$path, 'role'=>$role, 'bytes'=>$file->getSize()];
    $hashes[hash_file('sha256', $file->getPathname())][] = $path;
    if ($file->getExtension() !== 'php') { continue; }
    $source = file_get_contents($file->getPathname());
    $phpSources[$path] = $source;
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        $value = substr($token[1], 1, -1);
        if (preg_match('/^CALL\s+`?([A-Za-z_][A-Za-z_0-9 ]*?)`?\(([^)]*)\)$/D', $value, $call)) {
            $phpCalls[] = ['file'=>$path, 'line'=>$token[2], 'procedure'=>trim($call[1]), 'arguments'=>substr_count($call[2], '?')];
        }
    }
    preg_match_all('/\b(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/', $source, $matches);
    foreach ($matches[1] as $method) { $definitions[] = ['file'=>$path, 'method'=>$method]; }
}
usort($files, static fn(array $left, array $right): int => strcmp($left['file'], $right['file']));
$candidates = [];
foreach ($definitions as $definition) {
    $method = $definition['method'];
    if (str_starts_with($method, '__')) { continue; }
    $referencePattern = '/(?:->|::)\s*'.preg_quote($method, '/').'\s*\(|[\'\"]'.preg_quote($method, '/').'[\'\"]/';
    $references = [];
    foreach ($phpSources as $path=>$source) {
        if (preg_match($referencePattern, $source)) { $references[] = $path; }
    }
    if (!$references) { $candidates[] = $definition + ['status'=>'REVIEW_REQUIRED: dynamic/external consumers not ruled out']; }
}
$duplicates = array_values(array_filter($hashes, static fn(array $paths): bool => count($paths)>1));
echo json_encode(['files'=>$files, 'php_files'=>count($phpSources), 'candidate_methods'=>$candidates,
    'php_calls'=>$phpCalls, 'identical_files'=>$duplicates], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
