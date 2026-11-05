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

function calendarMonthRefused(string $value): bool
{
    try { \Core\Calendar::month($value); } catch (DomainException $exception) { return true; }
    return false;
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

/** A throwaway php -S for the app. $scriptName reports a sub-folder front controller, as Apache does; $phpSettings are extra -d values. */
function auditServerStart(string $scriptName = '', array $phpSettings = []): array
{
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    expect($listener !== false, 'Could not reserve a test server port.');
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $root = dirname(__DIR__);
    $router = (string) tempnam(sys_get_temp_dir(), 'h2h');
    file_put_contents($router, '<?php '.($scriptName === '' ? '' : '$_SERVER["SCRIPT_NAME"]='.var_export($scriptName, true).';').' return require '.var_export($root.'/public/router.php', true).';');
    $settings = ['-d', 'error_log='];
    foreach ($phpSettings as $name => $value) { array_push($settings, '-d', $name.'='.$value); }
    $output = tmpfile();
    $process = proc_open(array_merge([PHP_BINARY], $settings, ['-S', $address, '-t', $root.'/public', $router]),
        [0 => ['pipe', 'r'], 1 => $output, 2 => $output], $pipes, $root, array_merge(getenv(), ['APP_URL' => '']), ['bypass_shell' => true]);
    expect(is_resource($process), 'Could not start the test server.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorText, 1);
        if ($connection !== false) {
            fclose($connection);
            return ['process' => $process, 'output' => $output, 'router' => $router, 'url' => 'http://'.$address];
        }
        usleep(100000);
    }
    throw new RuntimeException('The test server did not start.');
}

function auditServerStop(array $server): void
{
    proc_terminate($server['process']); proc_close($server['process']);
    fclose($server['output']); unlink($server['router']);
}

/** One browser session; cookies stay on the handle. */
function auditBrowser(): CurlHandle
{
    $browser = curl_init();
    curl_setopt_array($browser, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 20]);
    return $browser;
}

/** $fields null is a GET. Location comes back as path and query only. */
function auditRequest(CurlHandle $browser, string $url, array|string|null $fields = null, string $referer = '', array $headers = []): array
{
    curl_setopt_array($browser, [CURLOPT_URL => $url, CURLOPT_REFERER => $referer, CURLOPT_HTTPHEADER => $headers]);
    if ($fields === null) { curl_setopt($browser, CURLOPT_HTTPGET, true); }
    else { curl_setopt_array($browser, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_array($fields) ? http_build_query($fields) : $fields]); }
    $body = curl_exec($browser);
    $location = (string) curl_getinfo($browser, CURLINFO_REDIRECT_URL);
    $query = (string) parse_url($location, PHP_URL_QUERY);
    return ['status' => curl_getinfo($browser, CURLINFO_RESPONSE_CODE), 'location' => (string) parse_url($location, PHP_URL_PATH).($query !== '' ? '?'.$query : ''), 'body' => (string) $body];
}

function auditFormToken(string $html): string
{
    expect((bool) preg_match('/name="_token" value="([^"]+)"/', $html, $match), 'Form token missing.');
    return $match[1];
}

/** Signs in on the form the browser already has open, and returns where the app sends the user. */
function auditSignIn(CurlHandle $browser, string $base, string $formHtml, string $email): string
{
    return auditRequest($browser, $base.'/login', ['_token' => auditFormToken($formHtml), 'email' => $email, 'password' => 'Password123!'])['location'];
}

/** Protected page, Referer, expired form and language switch all land inside the app, with its base exactly once. */
function auditReturnTargets(string $server, string $base): void
{
    $browser = auditBrowser();
    expect(auditRequest($browser, $server.$base.'/bookings?status=x')['location'] === $base.'/login', 'Protected page did not ask to sign in: '.$base);
    $form = auditRequest($browser, $server.$base.'/login')['body'];
    expect(auditSignIn($browser, $server.$base, $form, 'guest@home2home.test') === $base.'/bookings?status=x', 'Sign-in lost the protected page: '.$base);
    $browser = auditBrowser();
    $form = auditRequest($browser, $server.$base.'/login', null, $server.$base.'/listings/1')['body'];
    expect(auditSignIn($browser, $server.$base, $form, 'guest@home2home.test') === $base.'/listings/1', 'Sign-in lost the Referer page: '.$base);
    $browser = auditBrowser();
    expect(auditRequest($browser, $server.$base.'/login', ['_token' => 'stale'], $server.$base.'/login')['location'] === $base.'/login', 'Expired form left the page: '.$base);
    expect(auditRequest($browser, $server.$base.'/login', ['_token' => 'stale'], $server.$base.'/listings/1?month=2030-01')['location'] === $base.'/listings/1?month=2030-01', 'Expired form lost the query: '.$base);
    $page = auditRequest($browser, $server.$base.'/listings/1')['body'];
    expect((bool) preg_match('/name="return_to" value="([^"]+)"/', $page, $match) && $match[1] === '/listings/1', 'Language switch must return to an app path: '.$base);
    expect(auditRequest($browser, $server.$base.'/locale', ['_token' => auditFormToken($page), 'locale' => 'vi', 'return_to' => $match[1]])['location'] === $base.'/listings/1', 'Language switch left the page: '.$base);
}

/** Renders the login view in a child process, because APP_ENV is read when the view runs. */
function loginViewUnderEnv(string $environment): string
{
    $process = proc_open([PHP_BINARY, '-r', 'require "core/bootstrap.php"; $old=[]; $returnTarget="/bookings"; require "app/Views/auth/login.php";'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), ['APP_ENV' => $environment]), ['bypass_shell' => true]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    return $output;
}

/** Controller::json() in a child process, since it exits. $data is PHP source. Returns the decoded body and the status it set. */
function jsonHelperOutput(string $data): array
{
    $code = 'require "core/bootstrap.php"; function reportStatus(): void { echo "|", http_response_code(); } register_shutdown_function("reportStatus"); (new Core\Controller())->json(true, "ok", '.$data.');';
    $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    $split = (int) strrpos($output, '|');
    return ['body' => json_decode(substr($output, 0, $split), true), 'status' => (int) substr($output, $split + 1)];
}

/** A POST over a 2 KB post_max_size. $warningShown: display_startup_errors prints PHP's warning before a urlencoded body reaches any code. */
function auditOversizedPost(array $phpSettings, bool $warningShown): void
{
    $server = auditServerStart('', $phpSettings + ['post_max_size' => '2K', 'display_errors' => '1']);
    try {
        $url = $server['url'];
        $browser = auditBrowser();
        auditSignIn($browser, $url, auditRequest($browser, $url.'/login')['body'], 'guest@home2home.test');
        $page = auditRequest($browser, $url.'/profile')['body'];
        $token = auditFormToken($page);
        $message = 'vượt giới hạn 2 KB của máy chủ';
        $boundary = 'audit-boundary';
        $multipart = "--$boundary\r\nContent-Disposition: form-data; name=\"_token\"\r\n\r\n$token\r\n--$boundary\r\nContent-Disposition: form-data; name=\"full_name\"\r\n\r\nOversized name\r\n--$boundary\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"a.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".str_repeat('A', 3000)."\r\n--$boundary--\r\n";
        $headers = ["Content-Type: multipart/form-data; boundary=$boundary", 'Expect:'];
        $refused = auditRequest($browser, $url.'/profile', $multipart, $url.'/profile?tab=x', $headers);
        expect($refused['status'] === 302 && $refused['location'] === '/profile?tab=x' && trim($refused['body']) === '', 'Oversized upload must return to its page.');
        $returned = auditRequest($browser, $url.'/profile')['body'];
        expect(str_contains($returned, $message) && !str_contains($returned, 'Phiên biểu mẫu') && !str_contains($returned, 'Oversized name'), 'Oversized upload must name the limit and change nothing.');
        $outside = auditRequest($browser, $url.'/profile', $multipart, 'https://elsewhere.example/page', $headers);
        expect($outside['location'] === '/', 'Oversized upload must not leave the site.');
        auditRequest($browser, $url.'/profile');
        $json = auditRequest($browser, $url.'/profile', $multipart, '', array_merge($headers, ['Accept: application/json']));
        $answer = json_decode($json['body'], true);
        expect($json['status'] === 413 && ($answer['success'] ?? null) === false && str_contains((string) ($answer['message'] ?? ''), $message), 'Oversized script request must get a clean JSON 413.');
        $encoded = auditRequest($browser, $url.'/profile', '_token='.$token.'&full_name='.str_repeat('A', 3000), $url.'/profile', ['Expect:']);
        if ($warningShown) {
            expect($encoded['status'] === 200 && str_contains($encoded['body'], $message) && str_contains($encoded['body'], 'href="/profile"'), 'After a printed startup warning the message must be on the page with a way back.');
        } else {
            expect($encoded['status'] === 302 && $encoded['location'] === '/profile', 'Oversized text form must return to its page.');
            expect(str_contains(auditRequest($browser, $url.'/profile')['body'], $message), 'Oversized text form must name the limit.');
        }
        expect(str_contains(auditRequest($browser, $url.'/profile', '_x=1', $url.'/profile')['location'], '/profile'), 'A small POST without a token is still refused.');
        expect(str_contains(auditRequest($browser, $url.'/profile')['body'], 'Phiên biểu mẫu'), 'A small POST without a token is still an expired form.');
    } finally {
        auditServerStop($server);
    }
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

$rootServer = auditServerStart();
$subfolderServer = auditServerStart('/WebApp/public/index.php');
try {
    auditReturnTargets($rootServer['url'], '');
    auditReturnTargets($subfolderServer['url'], '/WebApp/public');
    // The address the root .htaccess keeps: /WebApp/x runs public/index.php.
    $viaFolder = auditBrowser();
    expect(auditRequest($viaFolder, $subfolderServer['url'].'/WebApp/')['status'] === 200 && auditRequest($viaFolder, $subfolderServer['url'].'/WebApp/listings/1')['status'] === 200, 'Folder address must serve the app.');
    expect(auditRequest($viaFolder, $subfolderServer['url'].'/WebApp/bookings')['location'] === '/WebApp/public/login', 'Folder address must reach the app routes.');
    $form = auditRequest($viaFolder, $subfolderServer['url'].'/WebApp/public/login')['body'];
    expect(auditSignIn($viaFolder, $subfolderServer['url'].'/WebApp/public', $form, 'guest@home2home.test') === '/WebApp/public/bookings', 'Folder address lost the protected page.');
    $elsewhere = auditBrowser();
    expect(auditRequest($elsewhere, $subfolderServer['url'].'/WebApp/public/login', ['_token' => 'stale'], $subfolderServer['url'].'/phpmyadmin/')['location'] === '/WebApp/public/', 'Expired form followed a Referer outside the app.');
    echo "PASS sign-in return, expired form and language switch keep the base exactly once at the root and under a sub-folder\n";

    $url = $rootServer['url'];
    foreach (["\0", "2027-02-01\0", "\x002027-02-01", '2027-02-30', '2027-2-1', '', 'x'] as $badDate) {
        expect(\Core\Calendar::date($badDate) === null, 'Bad date accepted.');
    }
    expect(\Core\Calendar::date('2027-02-01')?->format('Y-m-d') === '2027-02-01', 'Good date refused.');
    expect(calendarMonthRefused("\0") && calendarMonthRefused("2027-02\0") && !calendarMonthRefused('2027-02'), 'Month parser must refuse a NUL as invalid.');
    $anonymous = auditBrowser(); $admin = auditBrowser(); $hostBrowser = auditBrowser();
    auditSignIn($admin, $url, auditRequest($admin, $url.'/login')['body'], 'admin@home2home.test');
    auditSignIn($hostBrowser, $url, auditRequest($hostBrowser, $url.'/login')['body'], 'host@home2home.test');
    $adminToken = auditFormToken(auditRequest($admin, $url.'/profile')['body']);
    foreach ($routes as $method => $methodRoutes) {
        foreach ($methodRoutes as $route) {
            $path = (string) preg_replace('~^#\^|/\?\$#$~', '', $route['pattern']);
            $withControl = str_contains($path, '(?P<') ? [preg_replace('/\(\?P<\w+>\[\^\/\]\+\)/', '%00', $path), preg_replace('/\(\?P<\w+>\[\^\/\]\+\)/', '1%0A', $path)] : [$path.'%00', $path.'%0A'];
            foreach ($withControl as $badPath) {
                $response = auditRequest($admin, $url.$badPath, $method === 'POST' ? ['_token' => $adminToken] : null);
                expect($response['status'] === 404 && str_contains($response['body'], '<html'), "Control character in a path must give the normal 404: $method $badPath got ".$response['status']);
            }
            if ($method === 'GET') {
                $response = auditRequest($admin, $url.preg_replace('/\(\?P<\w+>\[\^\/\]\+\)/', '1', $path).'?month=%00&check_in=%00&check_out=%00&guests=%00&q=%00&status=%00&page=%00&next=%00&promo_code=%00');
                expect($response['status'] < 500 && ($response['location'] !== '' || trim($response['body']) !== ''), "NUL in a query must not break: GET $path got ".$response['status']);
            }
        }
    }
    $monthPage = auditRequest($anonymous, $url.'/listings/1?month=%00');
    expect($monthPage['status'] === 200 && str_contains($monthPage['body'], 'Tháng không hợp lệ.'), 'NUL month must read as an invalid month.');
    $quote = auditRequest($anonymous, $url.'/api/listings/1/quote?check_in=%00&check_out=2027-02-03&guests=1');
    expect($quote['status'] === 422 && (json_decode($quote['body'], true)['success'] ?? null) === false, 'NUL date in a quote must be a 422.');
    $hostToken = auditFormToken(auditRequest($hostBrowser, $url.'/profile')['body']);
    $blocked = auditRequest($hostBrowser, $url.'/api/host/listings/1/availability', '_token='.$hostToken.'&date=%00&open=1', '', ['Accept: application/json']);
    expect($blocked['status'] === 422 && (json_decode($blocked['body'], true)['message'] ?? '') === 'Ngày không hợp lệ.', 'NUL date on the availability form must be refused.');
    echo "PASS control characters in any route path give the 404 page; NUL in query and date values is an ordinary invalid value\n";
    $invalidText = jsonHelperOutput('["promo" => "a\xffb"]');
    expect($invalidText['status'] === 200 && $invalidText['body']['success'] === true && $invalidText['body']['data']['promo'] === "a\u{FFFD}b", 'json() must substitute invalid UTF-8.');
    $unencodable = jsonHelperOutput('["price" => INF]');
    expect($unencodable['status'] === 500 && $unencodable['body']['success'] === false && $unencodable['body']['message'] !== '' && $unencodable['body']['data'] === null, 'json() must answer in JSON when the data cannot be encoded.');
    $invalidPromo = auditRequest($anonymous, $url.'/api/listings/1/quote?check_in=2099-02-01&check_out=2099-02-03&guests=1&promo_code=%ff');
    expect(trim($invalidPromo['body']) !== '' && is_array(json_decode($invalidPromo['body'], true)), 'A quote with an invalid promo byte must still answer in JSON.');
    echo "PASS json() always emits valid JSON, never a blank body\n";

    expect(!\Core\Validator::passwordAllowed(str_repeat('a', 7)) && \Core\Validator::passwordAllowed(str_repeat('a', 8)) && \Core\Validator::passwordAllowed(str_repeat('a', 72)) && !\Core\Validator::passwordAllowed(str_repeat('a', 73)) && !\Core\Validator::passwordAllowed("abcdefgh\0ijkl") && !\Core\Validator::passwordAllowed(str_repeat('é', 36).'x'), 'Password rule must be 8-72 bytes without NUL.');
    $passwordModel = new User();
    $tooLong = str_repeat('Z', 73);
    $withNul = "abcdefgh\0ijkl";
    $tag = bin2hex(random_bytes(5));
    foreach (['long' => $tooLong, 'nul' => $withNul] as $name => $badPassword) {
        $email = "audit-$name-$tag@home2home.test";
        $browser = auditBrowser();
        $form = auditRequest($browser, $url.'/register')['body'];
        $result = auditRequest($browser, $url.'/register', ['_token' => auditFormToken($form), 'full_name' => 'Audit', 'email' => $email, 'password' => $badPassword]);
        expect($result['location'] === '/register' && $passwordModel->findByEmail($email) === null, "Register accepted a bad password: $name");
        expect(str_contains(auditRequest($browser, $url.'/register')['body'], \Core\Validator::PASSWORD_RULE), 'Register must state the password rule.');
        $result = auditRequest($admin, $url.'/admin/users', ['_token' => $adminToken, 'full_name' => 'Audit', 'email' => $email, 'password' => $badPassword, 'roles' => ['guest']]);
        expect($result['location'] === '/admin' && $passwordModel->findByEmail($email) === null, "Admin create accepted a bad password: $name");
    }
    $legacyPassword = str_repeat('L', 80);
    $fixtureEmail = "audit-password-$tag@home2home.test";
    $fixtureId = $passwordModel->create($fixtureEmail, $legacyPassword, 'Audit password fixture', null);
    try {
        $fixtureHash = $passwordModel->findByEmail($fixtureEmail)['password_hash'];
        $legacy = auditBrowser();
        $form = auditRequest($legacy, $url.'/login')['body'];
        expect(auditRequest($legacy, $url.'/login', ['_token' => auditFormToken($form), 'email' => $fixtureEmail, 'password' => $legacyPassword])['location'] === '/', 'A longer existing password must still log in.');
        $profileToken = auditFormToken(auditRequest($legacy, $url.'/profile')['body']);
        foreach ([$tooLong, $withNul] as $badPassword) {
            auditRequest($legacy, $url.'/profile/password', ['_token' => $profileToken, 'current_password' => $legacyPassword, 'new_password' => $badPassword, 'password_confirmation' => $badPassword]);
            auditRequest($admin, $url.'/admin/users/'.$fixtureId, ['_token' => $adminToken, 'full_name' => 'Audit password fixture', 'email' => $fixtureEmail, 'phone' => '', 'password' => $badPassword, 'roles' => ['guest']]);
            expect($passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'Change or admin edit accepted a bad password.');
        }
        expect(str_contains(auditRequest($legacy, $url.'/profile')['body'], \Core\Validator::PASSWORD_RULE), 'Change password must state the rule.');
        $rawToken = bin2hex(random_bytes(32));
        (new \App\Models\PasswordReset())->issue($fixtureId, $rawToken);
        $reader = auditBrowser();
        $resetForm = auditRequest($reader, $url.'/reset-password?token='.$rawToken)['body'];
        $resetReturn = '/reset-password?token='.$rawToken;
        foreach ([[$tooLong, $tooLong, \Core\Validator::PASSWORD_RULE], [$withNul, $withNul, \Core\Validator::PASSWORD_RULE], ['short', 'short', \Core\Validator::PASSWORD_RULE], ['Abcdefgh1', 'Abcdefgh2', 'Xác nhận mật khẩu không khớp.']] as [$badPassword, $confirmation, $message]) {
            $refused = auditRequest($reader, $url.'/reset-password', ['_token' => auditFormToken($resetForm), 'token' => $rawToken, 'password' => $badPassword, 'password_confirmation' => $confirmation]);
            expect($passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'Reset accepted a bad password.');
            expect($refused['location'] === $resetReturn, 'A reset form error must stay on the form with its token.');
            $returned = auditRequest($reader, $url.$resetReturn)['body'];
            expect(str_contains($returned, $message) && str_contains($returned, 'name="token" value="'.$rawToken.'"'), 'The reset form must show the error and keep the token.');
        }
        expect(auditRequest($reader, $url.'/reset-password', ['_token' => auditFormToken($resetForm), 'token' => 'not-a-token', 'password' => 'Abcdefgh1', 'password_confirmation' => 'Abcdefgh1'])['location'] === '/forgot-password', 'A malformed link must still go to the forgot page.');
        $exact = str_repeat('E', 72);
        auditRequest($admin, $url.'/admin/users/'.$fixtureId, ['_token' => $adminToken, 'full_name' => 'Audit password fixture', 'email' => $fixtureEmail, 'phone' => '', 'password' => '', 'roles' => ['guest']]);
        expect($passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'A blank password on admin edit must keep the current one.');
        expect(auditRequest($reader, $url.'/reset-password', ['_token' => auditFormToken($resetForm), 'token' => $rawToken, 'password' => $exact, 'password_confirmation' => $exact])['location'] === '/login', 'A 72-byte password must be accepted.');
        expect(password_verify($exact, $passwordModel->findByEmail($fixtureEmail)['password_hash']), 'Reset did not save the new password.');
    } finally {
        $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$fixtureId]);
    }
    echo "PASS password rule 8-72 bytes without NUL on register, admin create and edit, change and reset; login stays lenient; a reset form error keeps the form and token\n";

    auditOversizedPost(['display_startup_errors' => '1', 'output_buffering' => '4096'], true);
    auditOversizedPost(['display_startup_errors' => '0', 'output_buffering' => '0'], false);
    echo "PASS a POST over post_max_size says so with the limit, returns to its page and changes nothing\n";
} finally {
    auditServerStop($rootServer); auditServerStop($subfolderServer);
}

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
