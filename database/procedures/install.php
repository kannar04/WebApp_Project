<?php
declare(strict_types=1);

/** Administrative DDL loader; never called by an HTTP controller. */
function installHome2HomeProcedures(PDO $pdo, bool $testing = false): int
{
    if (PHP_SAPI !== 'cli') { throw new RuntimeException('Procedure installation is CLI only.'); }
    $files = glob(__DIR__.'/[0-9]*.sql');
    if ($testing) { $files[] = __DIR__.'/testing.sql'; }
    $count = 0;
    foreach ($files as $file) {
        // Retain the historical cleanup migration, but require separate reviewed execution.
        // Routine consumers outside PHP cannot be ruled out by this installer.
        if (basename($file) === '040_prune_transition_helpers.sql') { continue; }
        $sql = file_get_contents($file);
        if ($sql === false) { throw new RuntimeException('Cannot read procedure script.'); }
        $sql = preg_replace('/^DELIMITER\s+.*$/m', '', $sql);
        foreach (explode('$$', $sql) as $definition) {
            $create = preg_match('/\bCREATE\s+(?:OR REPLACE\s+)?PROCEDURE\b/i', $definition);
            if (!$create) { continue; }
            $pdo->exec(trim($definition));
            if ($create) { $count++; }
        }
    }
    return $count;
}
