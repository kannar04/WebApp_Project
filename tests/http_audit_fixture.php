<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

$appConfig = require dirname(__DIR__).'/config/app.php';
$dbConfig = require dirname(__DIR__).'/config/database.php';
if (PHP_SAPI !== 'cli' || $appConfig['env'] !== 'local'
    || !in_array($dbConfig['host'], ['localhost', '127.0.0.1', '::1'], true)) { exit(1); }
$database = \Core\ProcedureConnection::connection();
$command = $argv[1] ?? '';
$marker = $argv[2] ?? '';
if (!preg_match('/^[a-f0-9]{16}$/', $marker)) { exit(1); }
$emailPrefix = 'http-audit-'.$marker;
$title = 'HTTP audit '.$marker;

if ($command === 'create') {
    $password = $argv[3] ?? '';
    if (strlen($password) < 16) { exit(1); }
    $users = new \App\Models\User();
    $guestId = $users->create($emailPrefix.'-guest@home2home.test', $password, 'HTTP audit guest', null);
    $hostId = $users->create($emailPrefix.'-host@home2home.test', $password, 'HTTP audit host', null);
    $adminId = $users->create($emailPrefix.'-admin@home2home.test', $password, 'HTTP audit admin', null);
    $users->replaceRoles($hostId, ['guest', 'host']);
    $users->replaceRoles($adminId, ['guest', 'admin']);
    $model = new \App\Models\Listing();
    $data = [
        'property_type_id' => (int) $model->types()[0]['id'],
        'cancellation_policy_id' => (int) $model->policies()[0]['id'],
        'title' => $title, 'address' => 'Audit address', 'city' => 'Audit',
        'description' => 'Fixture for HTTP audit', 'nightly_price' => 100000,
        'cleaning_fee' => 10000, 'max_guests' => 2, 'room_count' => 1,
        'bedroom_count' => 1, 'bed_count' => 1, 'check_in_time' => '14:00', 'check_out_time' => '11:00',
    ];
    $listingId = $model->create($hostId, $data);
    $amenityId = (int) $model->allAmenities()[0]['id'];
    $model->setAmenities($listingId, [$amenityId], $hostId);
    echo json_encode(compact('guestId', 'hostId', 'adminId', 'listingId', 'amenityId', 'emailPrefix', 'title'));
    exit;
}

if ($command === 'inspect') {
    $statement = $database->prepare('CALL sp_test_listing_inspect(?)');
    $statement->execute([$title]);
    echo json_encode($statement->fetch());
    exit;
}

if ($command === 'user-id' && ($argv[3]??'')==='registered') {
    $statement=$database->prepare('CALL sp_test_user_id(?)');
    $statement->execute([$emailPrefix.'-registered@home2home.test']);
    echo (int)$statement->fetchColumn();
    exit;
}

if ($command === 'cleanup') {
    $fixtureUploads=[];
    // Resolve exclusively this run's random marker; never perform broad test-prefix deletes.
    $statement = $database->prepare('CALL sp_test_listing_ids(?,?)');
    $statement->execute([$title, 'Audit']);
    $listingIds = $statement->fetchAll(PDO::FETCH_COLUMN);
    $database->beginTransaction();
    foreach ($listingIds as $listingId) {
        $bookings=$database->prepare('CALL sp_test_booking_ids(?)');
        $bookings->execute([$listingId]);
        foreach ($bookings->fetchAll(PDO::FETCH_COLUMN) as $bookingId) {
            $database->prepare('CALL sp_test_cleanup_booking(?)')->execute([$bookingId]);
        }
        $photos=$database->prepare('CALL sp_demo_photo_paths(?)');
        $photos->execute([$listingId]);
        foreach ($photos->fetchAll(PDO::FETCH_COLUMN) as $path) {
            if (preg_match('~^/uploads/listing-'.(int)$listingId.'-[a-f0-9]{24}\.(jpg|png|webp)$~D',$path)) { $fixtureUploads[]=$path; }
        }
        $database->prepare('CALL sp_test_cleanup_listing(?)')->execute([$listingId]);

    }
    foreach (['guest', 'host', 'admin', 'registered'] as $role) {
        $statement = $database->prepare('CALL sp_test_user_id(?)');
        $statement->execute([$emailPrefix.'-'.$role.'@home2home.test']);
        $userId = $statement->fetchColumn();
        if ($userId === false) { continue; }
        $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$userId]);
    }
    $database->commit();
    $uploadRoot=realpath(dirname(__DIR__).'/public/uploads');
    foreach ($fixtureUploads as $relativePath) {
        $target=realpath(dirname(__DIR__).'/public'.$relativePath);
        if ($target!==false && $uploadRoot!==false && is_file($target)
            && str_starts_with(strtolower(str_replace('\\','/',$target)),strtolower(str_replace('\\','/',$uploadRoot)).'/')) {
            if (!unlink($target)) { throw new RuntimeException('Could not clean this fixture upload.'); }
        }
    }
    echo "Fixture cleanup complete\n";
}
