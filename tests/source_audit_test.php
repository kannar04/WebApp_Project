<?php
declare(strict_types=1);

use App\Models\AdminRepository;
use App\Models\Listing;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\BookingService;
use Core\ProcedureConnection as Database;

require dirname(__DIR__).'/core/bootstrap.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function expectDomainFailure(callable $operation, string $message): void
{
    try { $operation(); } catch (DomainException $exception) { return; }
    throw new RuntimeException($message);
}

$appConfig = require dirname(__DIR__).'/config/app.php';
$dbConfig = require dirname(__DIR__).'/config/database.php';
expect($appConfig['env'] === 'local' && in_array($dbConfig['host'], ['localhost', '127.0.0.1', '::1'], true), 'Run fixture tests only on a local database.');
$database = Database::connection();
$router = require dirname(__DIR__).'/routes/web.php';
$routes = (new ReflectionProperty($router, 'routes'))->getValue($router);
$routeCount = 0;
foreach ($routes as $methodRoutes) {
    foreach ($methodRoutes as $route) {
        [$controllerClass, $methodName] = $route['handler'];
        expect(class_exists($controllerClass), 'Controller class missing.');
        expect((new ReflectionMethod($controllerClass, $methodName))->isPublic(), 'Route handler is not public.');
        $routeCount++;
    }
}
echo "PASS {$routeCount} route handlers/autoload\n";

$userModel = new User();
$host = $userModel->findByEmail('host@home2home.test');
$guest = $userModel->findByEmail('guest@home2home.test');
$admin = $userModel->findByEmail('admin@home2home.test');
expect($host && $guest && $admin, 'Local demo accounts required.');
$listingModel = new Listing();
$listingId = null;
$bookingIds = [];
$fixtureUserId = null;

try {
    expectDomainFailure(fn() => (new Wishlist())->toggle((int) $guest['id'], PHP_INT_MAX), 'Wishlist accepted a nonexistent listing.');
    $types = $listingModel->types();
    $policies = $listingModel->policies();
    $policy = current(array_filter($policies, fn($policy) => !(bool) $policy['refund_fees']));
    expect((bool) $types && (bool) $policy, 'Active types and no-fee-refund policy required.');
    $listingData = [
        'property_type_id' => (int) $types[0]['id'], 'cancellation_policy_id' => (int) $policy['id'],
        'title' => 'Audit fixture '.bin2hex(random_bytes(6)), 'address' => 'Test only', 'city' => 'Audit',
        'description' => 'Temporary test fixture', 'nightly_price' => 100000, 'cleaning_fee' => 20000,
        'max_guests' => 2, 'room_count' => 1, 'bedroom_count' => 1, 'bed_count' => 1,
        'check_in_time' => '16:00', 'check_out_time' => '10:00',
    ];
    $listingId = $listingModel->create((int) $host['id'], $listingData);
    $database->prepare('CALL sp_test_listing_approve(?)')->execute([$listingId]);

    // Exercise the real ERD event CHECK constraint without changing any existing listing.
    $database->beginTransaction();
    (new AdminRepository())->moderate($listingId, (int) $admin['id'], 'rejected', 'Audit rejection');
    $statement = $database->prepare('CALL sp_test_moderation_actions(?)');
    $statement->execute([$listingId]);
    expect($statement->fetchColumn() === 'reject', 'Moderation event must use ERD verb reject.');
    (new AdminRepository())->moderate($listingId, (int) $admin['id'], 'approved', 'Audit approval');
    $database->rollBack();
    echo "PASS actual SQL moderation constraints/transaction rollback\n";

    $service = new BookingService();
    $start = (new DateTimeImmutable('today'))->modify('+4500 days');
    $end = $start->modify('+2 days');
    $checkIn = $start->format('Y-m-d');
    $checkOut = $end->format('Y-m-d');
    $bookingId = $service->create($listingId, (int) $guest['id'], $checkIn, $checkOut, 2);
    $bookingIds[] = $bookingId;
    $refund = $service->cancelByGuest($bookingId, (int) $guest['id'], 'Fixture pending cancellation');
    expect($refund['refund_amount'] === 220000.0, 'Pending refund must include the fee.');
    $statement = $database->prepare('CALL sp_test_booking_inspect(?)');
    $statement->execute([$bookingId]);
    expect(json_decode($statement->fetch()['policy_snapshot'], true)['check_in_time'] === '16:00:00', 'Arrival time not snapshotted.');
    echo "PASS pending refund and arrival-time snapshot\n";

    $bookingId = $service->create($listingId, (int) $guest['id'], $checkIn, $checkOut, 2);
    $bookingIds[] = $bookingId;
    expect($listingModel->setAvailability($listingId, (int) $host['id'], $checkIn, false, 'Audit block'), 'Could not block pending date.');
    expectDomainFailure(fn() => $service->adminTransition($bookingId, (int) $admin['id'], 'confirmed'), 'Admin ignored blocked dates.');
    expect($listingModel->setAvailability($listingId, (int) $host['id'], $checkIn, true, null), 'Could not reopen date.');
    $service->hostTransition($bookingId, (int) $host['id'], 'confirmed');
    expect(!$listingModel->setAvailability($listingId, (int) $host['id'], $checkIn, false, 'Audit block'), 'Confirmed booking date was blocked.');
    expectDomainFailure(fn() => $service->hostTransition($bookingId, (int) $host['id'], 'completed'), 'Future booking completed by Host.');
    expectDomainFailure(fn() => $service->adminTransition($bookingId, (int) $admin['id'], 'completed'), 'Future booking completed by Admin.');
    expectDomainFailure(fn() => $service->hostTransition($bookingId, (int) $guest['id'], 'rejected'), 'Ownership guard failed.');
    echo "PASS booking/calendar guards, future completion, ownership\n";

    $fixtureUserId = $userModel->create('audit-'.bin2hex(random_bytes(6)).'@home2home.test', bin2hex(random_bytes(16)), 'Audit user', null);
    $database->beginTransaction();
    $userModel->replaceRoles($fixtureUserId, ['guest', 'host']);
    expect($database->inTransaction(), 'Nested role update committed the caller transaction.');
    $database->rollBack();
    expect($userModel->findWithRoles($fixtureUserId)['roles'] === ['guest'], 'Role rollback failed.');
    echo "PASS role changes preserve caller transaction\n";

    $localPhotos = $database->query('CALL sp_test_local_photos()')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($localPhotos as $path) {
        $localPath = dirname(__DIR__).'/public'.$path;
        expect(is_file($localPath) && getimagesize($localPath) !== false, 'Missing/invalid local image.');
    }
    expect((int) $database->query('CALL sp_test_orphan_photos()')->fetchColumn() === 0, 'Orphan photo reference.');
    echo 'PASS '.count($localPhotos)." local image references and photo foreign keys\n";
} finally {
    if ($database->inTransaction()) { $database->rollBack(); }
    $database->beginTransaction();
    foreach ($bookingIds as $bookingId) {
        $database->prepare('CALL sp_test_cleanup_booking(?)')->execute([$bookingId]);
    }
    if ($listingId !== null) { $database->prepare('CALL sp_test_cleanup_listing(?)')->execute([$listingId]); }
    if ($fixtureUserId !== null) { $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$fixtureUserId]); }
    $database->commit();
}
