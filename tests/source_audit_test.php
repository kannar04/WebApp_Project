<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

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

/** app_path() as a request whose front controller is $scriptName sees it. */
function appPathUnder(string $scriptName, string $path): ?string
{
    $saved = $_SERVER['SCRIPT_NAME'] ?? null;
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $result = app_path($path);
    $_SERVER['SCRIPT_NAME'] = $saved;
    return $result;
}

/** Renders the login view in a child process, because APP_ENV is read when the view runs. */
function loginViewUnderEnv(string $environment): string
{
    $process = proc_open([PHP_BINARY, '-r', 'require "core/bootstrap.php"; $old=[]; $returnTarget="/bookings"; require "app/Views/auth/login.php";'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), ['APP_ENV' => $environment]), ['bypass_shell' => true]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    return $output;
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

$cliDisplayErrors = ini_get('display_errors');
configure_error_output(false, 'cli-server');
expect(ini_get('display_errors') === '0' && ini_get('log_errors') === '1', 'Web responses must not display PHP errors without APP_DEBUG.');
configure_error_output(true, 'cli-server');
expect(ini_get('display_errors') === '1' && ini_get('log_errors') === '1', 'APP_DEBUG must display PHP errors.');
ini_set('display_errors', (string) $cliDisplayErrors);
expect(input_text(['a' => 'x'], 'a') === 'x' && input_text(['a' => ['x']], 'a') === '' && input_text(['a' => ['x']], 'a', '7') === '7' && input_text([], 'a', '7') === '7', 'An array-shaped request value must read like an absent one.');
foreach (array_merge(glob(dirname(__DIR__).'/app/Controllers/*.php'), glob(dirname(__DIR__).'/app/Views/*/*.php')) as $sourceFile) {
    expect(!preg_match('/\((string|int|float)\)\s*\(?\$_(GET|POST)\[|\be\(\$_(GET|POST)\[/', (string) file_get_contents($sourceFile)), 'Request value assumed to be a string: '.basename($sourceFile));
}
echo "PASS error display follows APP_DEBUG, array-shaped request values read as absent\n";

$localLogin = loginViewUnderEnv('local'); $productionLogin = loginViewUnderEnv('production');
expect(str_contains($localLogin, 'class="demo-note"') && str_contains($localLogin, 'name="password"'), 'Local login page must show the demo accounts.');
expect(str_contains($productionLogin, 'name="password"') && !str_contains($productionLogin, 'demo-note') && !str_contains($productionLogin, '@home2home.test'), 'Demo accounts shown outside the local environment.');
echo "PASS demo accounts appear on the login page only in the local environment\n";

$returnTarget = new ReflectionMethod(\App\Controllers\AuthController::class, 'returnTarget');
$authController = new \App\Controllers\AuthController();
foreach (['/', '/bookings', '/listings/1?month=2030-01'] as $allowedTarget) {
    expect($returnTarget->invoke($authController, $allowedTarget) === $allowedTarget, 'Return target refused: '.$allowedTarget);
}
foreach (['', 'bookings', '//evil.example', 'http://evil.example', '/\evil.example', "/\r\n", "/\r\nX", "/\t/evil.example", '/login','/login/', '/register?x=1', '/%6cogin', '/forgot-password', '/reset-password?token=x'] as $refusedTarget) {
    expect($returnTarget->invoke($authController, $refusedTarget) === '', 'Return target accepted: '.$refusedTarget);
}
echo "PASS sign-in return targets refuse other sites and auth pages\n";

// Real Apache is checked by hand; here the rules that keep everything outside public/ off the web must stay in place.
$rootHtaccess = (string) file_get_contents(dirname(__DIR__).'/.htaccess');
expect(str_contains($rootHtaccess, 'RewriteRule ^(.*)$ public/$1 [L]') && str_contains($rootHtaccess, 'Options -Indexes')
    && (bool) preg_match('/<FilesMatch "\^\\\\\.">\s*Require all denied/', $rootHtaccess)
    && (bool) preg_match('/<IfModule !mod_rewrite\.c>\s*Require all denied/', $rootHtaccess), 'Root .htaccess must serve only public/ and deny dotfiles.');
expect(str_contains((string) file_get_contents(dirname(__DIR__).'/public/.htaccess'), 'Options -Indexes'), 'public/ must not list directories.');
foreach (glob(__DIR__.'/*.php') as $testScript) {
    expect((bool) preg_match('/^(?:\s*if \(|[a-zA-Z]+\()PHP_SAPI ?[!=]==? ?\'cli\'/m', (string) file_get_contents($testScript)), 'Test script runs over HTTP: '.basename($testScript));
}
expect(appPathUnder('/index.php', '/bookings') === '/bookings' && appPathUnder('/index.php', '/') === '/' && appPathUnder('/index.php', '//evil/x') === '//evil/x', 'Root deployment must keep the path.');
expect(appPathUnder('/WebApp/public/index.php', '/WebApp/public/bookings') === '/bookings' && appPathUnder('/WebApp/public/index.php', '/WebApp/public') === '/', 'public/ base not removed.');
expect(appPathUnder('/WebApp/public/index.php', '/WebApp/bookings') === '/bookings' && appPathUnder('/WebApp/public/index.php', '/WebApp/') === '/', 'Folder base (root .htaccess rewrite) not removed.');
expect(appPathUnder('/WebApp/public/index.php', '/other/bookings') === null && appPathUnder('/WebApp/public/index.php', '/WebAppX/bookings') === null, 'A path outside the app must not count as inside it.');
expect(appPathUnder('/public/index.php', '/public/login') === '/login' && appPathUnder('/public/index.php', '/login') === '/login', 'Project as the web root: both bases.');
expect(appPathUnder('/My Folder/public/index.php', '/My%20Folder/public/listings/1') === '/listings/1', 'Encoded folder name not recognised.');
echo "PASS root .htaccess serves only public/, test scripts are CLI only, app_path removes the base once\n";

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
    expect($refund['refund_amount'] === 0.0 && !$refund['was_paid'], 'A pending unpaid booking must record no refund.');
    $statement = $database->prepare('CALL sp_test_booking_inspect(?)');
    $statement->execute([$bookingId]);
    expect(json_decode($statement->fetch()['policy_snapshot'], true)['check_in_time'] === '16:00:00', 'Arrival time not snapshotted.');
    echo "PASS pending booking records no refund and arrival-time snapshot\n";

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
