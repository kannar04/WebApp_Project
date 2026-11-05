<?php
declare(strict_types=1);

/** The routine files the installer runs, in order. docs/database-setup.md lists the production ones for phpMyAdmin. */
function home2HomeProcedureFiles(bool $testing = false): array
{
    // Retain the historical cleanup migration, but require separate reviewed execution.
    // Routine consumers outside PHP cannot be ruled out by this installer.
    $files = array_values(array_diff(glob(__DIR__.'/[0-9]*.sql'), [__DIR__.'/040_prune_transition_helpers.sql']));
    // testing.sql sorts before feature fixtures such as testing_messages.sql.
    return $testing ? array_merge($files, glob(__DIR__.'/testing*.sql')) : $files;
}

/** Administrative DDL loader; never called by an HTTP controller. */
function installHome2HomeProcedures(PDO $pdo, bool $testing = false): int
{
    if (PHP_SAPI !== 'cli') { throw new RuntimeException('Procedure installation is CLI only.'); }
    $count = 0;
    foreach (home2HomeProcedureFiles($testing) as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) { throw new RuntimeException('Cannot read procedure script.'); }
        $sql = preg_replace('/^DELIMITER\s+.*$/m', '', $sql);
        foreach (explode('$$', $sql) as $definition) {
            $create = preg_match('/\bCREATE\s+(?:OR REPLACE\s+)?PROCEDURE\b/i', $definition);
            // Anything else between delimiters stays skipped, except an index that only adds and is safe to repeat on an existing database.
            $index = preg_match('/^(?:\s|--[^\n]*\n)*CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS\b/i', $definition);
            if (!$create && !$index) { continue; }
            $pdo->exec(trim($definition));
            if ($create) { $count++; }
        }
    }
    return $count;
}
