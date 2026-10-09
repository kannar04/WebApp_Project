<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/core/bootstrap.php';

$appConfig = require dirname(__DIR__, 2).'/config/app.php';
$databaseConfig = require dirname(__DIR__, 2).'/config/database.php';
if ($appConfig['env'] !== 'local'
    || !in_array($databaseConfig['host'], ['localhost', '127.0.0.1', '::1'], true)
    || $databaseConfig['database'] !== 'db_home2home') {
    fwrite(STDERR, "BLOCKED: image seeding is restricted to local db_home2home.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$images = require __DIR__.'/listing_images.php';
$database = \Core\ProcedureConnection::connection();
$database->beginTransaction();
$inserted = 0;
$skipped = 0;

try {
    foreach ($images as $image) {
        $relativePath = '/assets/images/demo/'.$image['photo'].'.jpg';
        $filePath = dirname(__DIR__, 2).'/public'.$relativePath;
        if (!is_file($filePath) || (new finfo(FILEINFO_MIME_TYPE))->file($filePath) !== 'image/jpeg'
            || getimagesize($filePath) === false) {
            throw new RuntimeException('Missing or invalid demo JPEG: '.$image['photo']);
        }
        $statement = $database->prepare('CALL `sp_demo_listing_lock`(?,?,?)');
        $statement->execute(['host@home2home.test', $image['title'], $image['city']]);
        $listingIds = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($listingIds) !== 1) { $skipped++; continue; }
        $listingId = (int) $listingIds[0];
        $statement = $database->prepare('CALL `sp_demo_photo_paths`(?)');
        $statement->execute([$listingId]);
        $existingPaths = $statement->fetchAll(PDO::FETCH_COLUMN);
        // Never introduce demo photos to a listing that already has non-demo/user photos.
        $hasUserPhotos = array_filter($existingPaths, static fn(string $path): bool =>
            !str_starts_with($path, 'https://images.unsplash.com/photo-')
            && !str_starts_with($path, '/assets/images/demo/'));
        if ($hasUserPhotos || in_array($relativePath, $existingPaths, true)) { $skipped++; continue; }
        $statement = $database->prepare('CALL `sp_demo_photo_orders`(?)');
        $statement->execute([$listingId]);
        $sortOrders = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        // The existing ERD database enforces unique (listing_id, sort_order).
        $sortOrder = in_array(0, $sortOrders, true) ? max($sortOrders) + 1 : 0;
        $statement = $database->prepare('CALL `sp_demo_photo_insert`(?,?,?,?)');
        $statement->execute([$listingId, $relativePath, $image['alt'], $sortOrder]);
        $inserted++;
    }
    if ($apply) { $database->commit(); } else { $database->rollBack(); }
    echo ($apply ? 'APPLIED' : 'DRY RUN').": {$inserted} images ".($apply ? 'inserted' : 'would be inserted').", {$skipped} skipped.\n";
} catch (Throwable $exception) {
    if ($database->inTransaction()) { $database->rollBack(); }
    fwrite(STDERR, "FAIL: image seeding aborted; no database changes committed.\n");
    exit(1);
}
