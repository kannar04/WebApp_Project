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
    // A browser posts the form's hidden next along with what was typed.
    $next = preg_match('/name="next" value="([^"]*)"/', $formHtml, $field) ? ['next' => html_entity_decode($field[1], ENT_QUOTES, 'UTF-8')] : [];
    return auditRequest($browser, $base.'/login', ['_token' => auditFormToken($formHtml), 'email' => $email, 'password' => 'Password123!'] + $next)['location'];
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
    expect(auditRequest(auditBrowser(), $server.$base.'/locale', ['_token' => 'stale', 'locale' => 'vi', 'return_to' => '/listings/1?guests=2'])['location'] === $base.'/listings/1?guests=2', 'A stale language switch without a Referer left the page: '.$base);
    expect(auditRequest(auditBrowser(), $server.$base.'/locale', ['_token' => 'stale', 'locale' => 'vi', 'return_to' => '//evil.example/x'])['location'] === $base.'/', 'A stale language switch followed a forged path: '.$base);
    // Signing in from the home page goes to the role's home; only an explicit next=/ asks for the home page.
    foreach (['host@home2home.test' => $base.'/host', 'admin@home2home.test' => $base.'/admin', 'guest@home2home.test' => $base.'/'] as $email => $roleHome) {
        $browser = auditBrowser();
        auditRequest($browser, $server.$base.'/login', null, $server.$base.'/listings/1');
        $form = auditRequest($browser, $server.$base.'/login', null, $server.$base.'/')['body'];
        expect(auditSignIn($browser, $server.$base, $form, $email) === $roleHome, 'Sign-in from the home page must reach the role home: '.$email.$base);
    }
    $browser = auditBrowser();
    $form = auditRequest($browser, $server.$base.'/login?next=/', null, $server.$base.'/listings/1')['body'];
    expect(auditSignIn($browser, $server.$base, $form, 'host@home2home.test') === $base.'/', 'An explicit next=/ must still work: '.$base);
    $browser = auditBrowser();
    $form = auditRequest($browser, $server.$base.'/login', null, $server.$base.'/listings/1')['body'];
    expect(auditSignIn($browser, $server.$base, $form, 'host@home2home.test') === $base.'/listings/1', 'A host must still return to a Referer page: '.$base);
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
// Apache applies this pattern to the file name it resolved, so "planted.php/x.jpg" is checked as planted.php; on Windows a trailing dot or space resolves to the same file.
$uploadsHtaccess = (string) file_get_contents(dirname(__DIR__).'/public/uploads/.htaccess');
expect((bool) preg_match('/^Options -ExecCGI -Includes -Indexes$/m', $uploadsHtaccess) && (bool) preg_match('/^<IfModule php_module>\s*php_flag engine off\s*<\/IfModule>$/m', $uploadsHtaccess)
    && (bool) preg_match('/^Require all denied\s*<FilesMatch "([^"]+)">\s*Require all granted\s*<\/FilesMatch>$/m', $uploadsHtaccess, $uploadsPattern), 'public/uploads must deny by default, serve only its pattern and run nothing.');
foreach (['a.jpg', 'a.JPG', 'a.jpeg', 'a.png', 'a.webp', 'a.gif', 'avatar-3-0123abc.jpg'] as $servedUpload) {
    expect(preg_match('~'.$uploadsPattern[1].'~', $servedUpload) === 1, 'Image refused in public/uploads: '.$servedUpload);
}
foreach (['planted.php', 'planted.PHP', 'planted.phtml', 'planted.phar', 'planted.phps', 'planted.php.', 'planted.php ', 'planted.pl', 'planted.cgi', 'planted.shtml', 'planted.svg', 'planted.jpg.php', '.htaccess', '.gitkeep', '.x.jpg'] as $refusedUpload) {
    expect(preg_match('~'.$uploadsPattern[1].'~', $refusedUpload) !== 1, 'public/uploads would serve '.$refusedUpload);
}
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

    // parse_url reads //x as a host with no path, and the home route's optional slash takes a bare //: a path starting with // is an ordinary 404.
    foreach ([[$rootServer['url'], ''], [$subfolderServer['url'], '/WebApp/public']] as [$serverUrl, $base]) {
        foreach (['//x', '//', '///', '//login', '//x/login', '//x/', '//?a=1', '//x?a=1', '/%2f'] as $doubleSlash) {
            $answer = auditRequest(auditBrowser(), $serverUrl.$base.$doubleSlash);
            expect($answer['status'] === 404 && str_contains($answer['body'], 'Không tìm thấy'), "$base$doubleSlash must be the normal 404, got {$answer['status']}.");
        }
        foreach (['/', '/?a=1', '/login', '/login/', '/listings/1'] as $normal) {
            expect(auditRequest(auditBrowser(), $serverUrl.$base.$normal)['status'] === 200, "$base$normal must still open.");
        }
    }
    echo "PASS a path starting with // is the normal 404 at the root and under a sub-folder, and / still opens\n";

    // parse_url gives up on a path holding :digits, which must not read as the home page. The language switch on that 404 returns to it, and a readable query with :digits is untouched.
    foreach ([[$rootServer['url'], ''], [$subfolderServer['url'], '/WebApp/public']] as [$serverUrl, $base]) {
        foreach (['/listings/1:80', '/foo:80', '/a:80/b', '/:80', '/login:80', '/listings/1:80/', '///x:80'] as $unreadable) {
            $colonBrowser = auditBrowser();
            $answer = auditRequest($colonBrowser, $serverUrl.$base.$unreadable);
            expect($answer['status'] === 404 && str_contains($answer['body'], 'Không tìm thấy'), "$base$unreadable must be the normal 404, got {$answer['status']}.");
            $backTo = $unreadable === '///x:80' ? '/' : $unreadable;
            expect(str_contains($answer['body'], 'name="return_to" value="'.$backTo.'"'), "$base$unreadable has the wrong language return path.");
            if ($unreadable === '/listings/1:80') {
                $switched = auditRequest($colonBrowser, $serverUrl.$base.'/locale', ['_token' => auditFormToken($answer['body']), 'locale' => 'en', 'return_to' => $unreadable]);
                expect($switched['status'] === 302 && $switched['location'] === $base.$unreadable, "The language switch must return to $base$unreadable, got {$switched['location']}.");
            }
        }
        foreach (['/?location=a:80', '/?x=1:80&y=2', '/listings/1?x=1:80'] as $readable) {
            $answer = auditRequest(auditBrowser(), $serverUrl.$base.$readable);
            expect($answer['status'] === 200 && str_contains($answer['body'], 'name="return_to" value="'.e($readable).'"'), "$base$readable must still open and return to itself.");
        }
    }
    echo "PASS a path parse_url cannot read is the normal 404 at the root and under a sub-folder, its language switch returns to it, and :digits in a query still works\n";

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
    foreach ([[str_repeat('a', 7), \Core\Validator::PASSWORD_RULE], [str_repeat('a', 8), null], [str_repeat('a', 72), null], [str_repeat('a', 73), \Core\Validator::PASSWORD_RULE], [str_repeat('ậ', 3), null], [str_repeat('ậ', 25), \Core\Validator::PASSWORD_RULE], ["abcdefgh\0ijkl", \Core\Validator::PASSWORD_HIDDEN_CHARACTER], ["\0", \Core\Validator::PASSWORD_HIDDEN_CHARACTER], [str_repeat('a', 72)."\0", \Core\Validator::PASSWORD_HIDDEN_CHARACTER]] as [$password, $problem]) {
        expect(\Core\Validator::passwordProblem($password) === $problem && \Core\Validator::passwordAllowed($password) === ($problem === null), 'passwordProblem must follow passwordAllowed and name the problem.');
    }
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
        $refusal = $badPassword === $withNul ? \Core\Validator::PASSWORD_HIDDEN_CHARACTER : \Core\Validator::PASSWORD_RULE;
        $refusedPage = auditRequest($browser, $url.'/register')['body'];
        expect(str_contains($refusedPage, $refusal) && !str_contains($refusedPage, $badPassword === $withNul ? \Core\Validator::PASSWORD_RULE : \Core\Validator::PASSWORD_HIDDEN_CHARACTER), "Register must say what is wrong with the password: $name");
        $result = auditRequest($admin, $url.'/admin/users', ['_token' => $adminToken, 'full_name' => 'Audit', 'email' => $email, 'password' => $badPassword, 'roles' => ['guest']]);
        expect($result['location'] === '/admin' && $passwordModel->findByEmail($email) === null, "Admin create accepted a bad password: $name");
        expect(str_contains(auditRequest($admin, $url.'/admin')['body'], $refusal), "Admin create must say what is wrong with the password: $name");
    }
    // Passwords are never trimmed: eight spaces meet the rule on register as on every other path, and the spaces count at login.
    $spaces = str_repeat(' ', 8);
    $spacesEmail = "audit-spaces-$tag@home2home.test";
    $spacesBrowser = auditBrowser();
    $form = auditRequest($spacesBrowser, $url.'/register')['body'];
    $registered = auditRequest($spacesBrowser, $url.'/register', ['_token' => auditFormToken($form), 'full_name' => 'Audit spaces', 'email' => $spacesEmail, 'password' => $spaces]);
    $spacesUser = $passwordModel->findByEmail($spacesEmail);
    try {
        expect($registered['location'] === '/' && $spacesUser !== null && password_verify($spaces, $spacesUser['password_hash']), 'Register must keep an 8-space password untrimmed, like the other password paths.');
        $spacesLogin = auditBrowser();
        $form = auditRequest($spacesLogin, $url.'/login')['body'];
        expect(auditRequest($spacesLogin, $url.'/login', ['_token' => auditFormToken($form), 'email' => $spacesEmail, 'password' => str_repeat(' ', 7)])['location'] === '/login', 'Login must count every space of a password.');
        expect(auditRequest($spacesLogin, $url.'/login', ['_token' => auditFormToken($form), 'email' => $spacesEmail, 'password' => $spaces])['location'] === '/', 'An 8-space password must log in.');
    } finally {
        if ($spacesUser !== null) { $database->prepare('CALL sp_test_cleanup_user(?)')->execute([(int) $spacesUser['id']]); }
    }
    $spacesBrowser = auditBrowser();
    $form = auditRequest($spacesBrowser, $url.'/register')['body'];
    auditRequest($spacesBrowser, $url.'/register', ['_token' => auditFormToken($form), 'full_name' => 'Audit spaces', 'email' => $spacesEmail, 'password' => str_repeat(' ', 7)]);
    expect($passwordModel->findByEmail($spacesEmail) === null && str_contains(auditRequest($spacesBrowser, $url.'/register')['body'], \Core\Validator::PASSWORD_RULE), 'Seven spaces are under the minimum.');
    $legacyPassword = str_repeat('L', 80);
    $fixtureEmail = "audit-password-$tag@home2home.test";
    $fixtureId = $passwordModel->create($fixtureEmail, $legacyPassword, 'Audit password fixture', null);
    try {
        $fixtureHash = $passwordModel->findByEmail($fixtureEmail)['password_hash'];
        $legacy = auditBrowser();
        $form = auditRequest($legacy, $url.'/login')['body'];
        expect(auditRequest($legacy, $url.'/login', ['_token' => auditFormToken($form), 'email' => $fixtureEmail, 'password' => $legacyPassword])['location'] === '/', 'A longer existing password must still log in.');
        $profileToken = auditFormToken(auditRequest($legacy, $url.'/profile')['body']);
        foreach ([[$tooLong, \Core\Validator::PASSWORD_RULE], [$withNul, \Core\Validator::PASSWORD_HIDDEN_CHARACTER]] as [$badPassword, $refusal]) {
            auditRequest($legacy, $url.'/profile/password', ['_token' => $profileToken, 'current_password' => $legacyPassword, 'new_password' => $badPassword, 'password_confirmation' => $badPassword]);
            auditRequest($admin, $url.'/admin/users/'.$fixtureId, ['_token' => $adminToken, 'full_name' => 'Audit password fixture', 'email' => $fixtureEmail, 'phone' => '', 'password' => $badPassword, 'roles' => ['guest']]);
            expect($passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'Change or admin edit accepted a bad password.');
            expect(str_contains(auditRequest($legacy, $url.'/profile')['body'], $refusal), 'Change password must say what is wrong with the password.');
            expect(str_contains(auditRequest($admin, $url.'/admin/users/'.$fixtureId.'/edit')['body'], $refusal), 'Admin edit must say what is wrong with the password.');
        }
        $rawToken = bin2hex(random_bytes(32));
        (new \App\Models\PasswordReset())->issue($fixtureId, $rawToken);
        $reader = auditBrowser();
        $resetForm = auditRequest($reader, $url.'/reset-password?token='.$rawToken)['body'];
        $resetReturn = '/reset-password?token='.$rawToken;
        foreach ([[$tooLong, $tooLong, \Core\Validator::PASSWORD_RULE], [$withNul, $withNul, \Core\Validator::PASSWORD_HIDDEN_CHARACTER], ['short', 'short', \Core\Validator::PASSWORD_RULE], ['Abcdefgh1', 'Abcdefgh2', 'Xác nhận mật khẩu không khớp.']] as [$badPassword, $confirmation, $message]) {
            $refused = auditRequest($reader, $url.'/reset-password', ['_token' => auditFormToken($resetForm), 'token' => $rawToken, 'password' => $badPassword, 'password_confirmation' => $confirmation]);
            expect($passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'Reset accepted a bad password.');
            expect($refused['location'] === $resetReturn, 'A reset form error must stay on the form with its token.');
            $returned = auditRequest($reader, $url.$resetReturn)['body'];
            expect(str_contains($returned, $message) && str_contains($returned, 'name="token" value="'.$rawToken.'"'), 'The reset form must show the error and keep the token.');
        }
        expect(auditRequest($reader, $url.'/reset-password', ['_token' => auditFormToken($resetForm), 'token' => 'not-a-token', 'password' => 'Abcdefgh1', 'password_confirmation' => 'Abcdefgh1'])['location'] === '/forgot-password', 'A malformed link must still go to the forgot page.');
        // A stale form has no Referer (Referrer-Policy on the reset page), and returns to itself with the link intact and unspent.
        $stale = auditRequest($reader, $url.'/reset-password', ['_token' => 'stale', 'token' => $rawToken, 'password' => 'Abcdefgh1', 'password_confirmation' => 'Abcdefgh1']);
        expect($stale['location'] === $resetReturn && $passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'A stale reset form must return to itself and change nothing.');
        $staleReturn = auditRequest($reader, $url.$resetReturn)['body'];
        expect(str_contains($staleReturn, 'Phiên biểu mẫu đã hết hạn') && str_contains($staleReturn, 'name="token" value="'.$rawToken.'"'), 'The reset form must explain the stale form and keep the token.');
        foreach (['not-a-token', '//evil.example', str_repeat('a', 64)."\r\nX-Evil: 1", ['array']] as $malformed) {
            expect(auditRequest(auditBrowser(), $url.'/reset-password', ['_token' => 'stale', 'token' => $malformed])['location'] === '/', 'A stale form with a malformed token must go home.');
        }
        expect(auditRequest(auditBrowser(), $url.'/reset-password', ['_token' => 'stale', 'token' => $rawToken], 'https://evil.example/x')['location'] === $resetReturn, 'A foreign Referer must not take the redirect.');
        // The language switch on that page has no Referer either, and returns the same way; the path it carries is checked, so a forged one goes home.
        expect(str_contains($staleReturn, 'name="return_to" value="'.$resetReturn.'"'), 'The language switch must return to the reset form.');
        $staleSwitcher = auditBrowser();
        expect(auditRequest($staleSwitcher, $url.'/locale', ['_token' => 'stale', 'locale' => 'en', 'return_to' => $resetReturn])['location'] === $resetReturn, 'A stale language switch must return to the reset form.');
        $switchReturn = auditRequest($staleSwitcher, $url.$resetReturn)['body'];
        expect(str_contains($switchReturn, 'Phiên biểu mẫu đã hết hạn') && str_contains($switchReturn, 'name="token" value="'.$rawToken.'"') && $passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'A stale language switch must keep the reset form and spend nothing.');
        expect(auditRequest($staleSwitcher, $url.'/locale', ['_token' => auditFormToken($switchReturn), 'locale' => 'en', 'return_to' => $resetReturn])['location'] === $resetReturn, 'The language switch must work on the second try.');
        expect(str_contains(auditRequest($staleSwitcher, $url.$resetReturn)['body'], '<html lang="en"'), 'The second try did not switch the language.');
        foreach (['//evil.example/x', 'https://evil.example/x', '/\\evil.example', 'evil.example', '', "/x\r\nX-Evil: 1", "/\t/evil.example", '/'.str_repeat('a', 2100), ['/listings/1']] as $forgedReturn) {
            foreach (['stale', auditFormToken($switchReturn)] as $switchToken) {
                expect(auditRequest($staleSwitcher, $url.'/locale', ['_token' => $switchToken, 'locale' => 'en', 'return_to' => $forgedReturn])['location'] === '/', 'A forged language return path must go home.');
            }
        }
        expect(auditRequest(auditBrowser(), $url.'/locale', ['_token' => 'stale', 'locale' => 'en', 'return_to' => '/listings/1'], 'https://evil.example/x')['location'] === '/listings/1', 'A foreign Referer must not take a stale language switch.');
        expect(auditRequest(auditBrowser(), $url.'/locale', ['_token' => 'stale', 'locale' => 'en', 'return_to' => '/listings/1'], $url.'/listings?location=x')['location'] === '/listings?location=x', 'A stale language switch must go back to the Referer it has.');
        $exact = str_repeat('E', 72);
        auditRequest($admin, $url.'/admin/users/'.$fixtureId, ['_token' => $adminToken, 'full_name' => 'Audit password fixture', 'email' => $fixtureEmail, 'phone' => '', 'password' => '', 'roles' => ['guest']]);
        expect($passwordModel->findByEmail($fixtureEmail)['password_hash'] === $fixtureHash, 'A blank password on admin edit must keep the current one.');
        expect(auditRequest($reader, $url.'/reset-password', ['_token' => auditFormToken($resetForm), 'token' => $rawToken, 'password' => $exact, 'password_confirmation' => $exact])['location'] === '/login', 'A 72-byte password must be accepted.');
        expect(password_verify($exact, $passwordModel->findByEmail($fixtureEmail)['password_hash']), 'Reset did not save the new password.');
    } finally {
        $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$fixtureId]);
    }
    echo "PASS password rule 8-72 bytes without NUL on register, admin create and edit, change and reset, each saying which problem it found; login stays lenient; a reset form error keeps the form and token\n";

    // Hints state the rule in words a person can act on, not in bytes. minlength and maxlength count characters, so they would block a valid short accented password and pass an over-long one.
    foreach ([[auditBrowser(), '/register'], [auditBrowser(), '/reset-password?token='.str_repeat('a', 64)], [$hostBrowser, '/profile'], [$admin, '/admin'], [$admin, '/admin/users/3/edit']] as [$hintBrowser, $hintPath]) {
        $hintPage = auditRequest($hintBrowser, $url.$hintPath)['body'];
        expect(str_contains(mb_strtolower($hintPage), 'ít nhất 8 ký tự') && !preg_match('/\bbytes?\b|\bNUL\b|8–72/i', $hintPage), "$hintPath must state the rule in plain words.");
        expect($hintPath === '/admin' || str_contains($hintPage, 'quá dài có thể bị từ chối, và chữ có dấu được tính nhiều hơn một ký tự.'), "$hintPath must warn about long passwords and accented letters.");
        expect(preg_match_all('/<input\b[^>]*type="password"[^>]*>/', $hintPage, $passwordInputs) > 0, "$hintPath has no password input.");
        foreach ($passwordInputs[0] as $passwordInput) { expect(!preg_match('/\b(?:min|max)length=/', $passwordInput), "$hintPath counts password characters in the browser: $passwordInput"); }
    }
    $englishHints = array_merge(require dirname(__DIR__).'/lang/en/common.php', require dirname(__DIR__).'/lang/en/admin.php', require dirname(__DIR__).'/lang/en/messages.php');
    foreach (['Ít nhất 8 ký tự. Mật khẩu quá dài có thể bị từ chối, và chữ có dấu được tính nhiều hơn một ký tự.', 'Mật khẩu mới cần có ít nhất 8 ký tự. Mật khẩu quá dài có thể bị từ chối, và chữ có dấu được tính nhiều hơn một ký tự. Sau khi đặt lại, các phiên cũ hết hiệu lực.', 'Ít nhất 8 ký tự', \Core\Validator::PASSWORD_RULE] as $hint) {
        expect(stripos((string) ($englishHints[$hint] ?? ''), 'at least 8 characters') !== false, "English must state the rule in plain words for: $hint");
    }
    foreach ([\Core\Validator::PASSWORD_RULE, \Core\Validator::PASSWORD_HIDDEN_CHARACTER] as $refusal) {
        expect(($englishHints[$refusal] ?? '') !== '' && !preg_match('/\bbytes?\b|\bNUL\b/i', $refusal.' '.$englishHints[$refusal]), "A refusal must be plain in both languages: $refusal");
    }
    $accentEmail = "audit-accent-$tag@home2home.test";
    $accentBrowser = auditBrowser();
    auditRequest($accentBrowser, $url.'/register', ['_token' => auditFormToken(auditRequest($accentBrowser, $url.'/register')['body']), 'full_name' => 'Audit accent', 'email' => $accentEmail, 'password' => str_repeat('ậ', 25)]);
    expect($passwordModel->findByEmail($accentEmail) === null, '25 accented letters are 75 bytes, over the limit.');
    auditRequest($accentBrowser, $url.'/register', ['_token' => auditFormToken(auditRequest($accentBrowser, $url.'/register')['body']), 'full_name' => 'Audit accent', 'email' => $accentEmail, 'password' => 'mậtkhẩu']);
    $accentUser = $passwordModel->findByEmail($accentEmail);
    expect($accentUser !== null && password_verify('mậtkhẩu', $accentUser['password_hash']), 'Seven accented characters are 11 bytes, within the rule.');
    $database->prepare('CALL sp_test_cleanup_user(?)')->execute([(int) $accentUser['id']]);
    echo "PASS password hints and refusals are in plain words in Vietnamese and English, and no password input counts characters in the browser\n";

    // A session that ends and a login that starts leave nothing of the last person's forms; every bag a controller keeps is covered, so a new one has to be too.
    $bagNames = ['_flash', '_old', '_message_draft'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/app', FilesystemIterator::SKIP_DOTS)) as $appFile) {
        if (preg_match_all("/Session::put\('(_[a-z_]+)'/", (string) file_get_contents($appFile->getPathname()), $bags)) { $bagNames = array_merge($bagNames, $bags[1]); }
    }
    expect(in_array('_profile_old', $bagNames, true) && in_array('_review_old', $bagNames, true), 'The scan for session bags found nothing.');
    $_SESSION = array_fill_keys($bagNames, ['left by the last person']) + ['_csrf' => 'kept', 'intended_url' => '/kept'];
    \Core\Session::forgetFormData();
    expect(array_keys($_SESSION) === ['_csrf', 'intended_url'], 'forgetFormData left a form bag or dropped something else: '.implode(', ', array_keys($_SESSION)));
    $_SESSION = [];
    $bagEmail = "audit-bags-$tag@home2home.test";
    $bagOwnerId = $passwordModel->create($bagEmail, 'Password123!', 'Audit bag owner', null);
    try {
        // Suspended: the session ends for good, and the sign-in message set after that point survives.
        $suspended = auditBrowser();
        auditSignIn($suspended, $url, auditRequest($suspended, $url.'/login')['body'], $bagEmail);
        auditRequest($suspended, $url.'/profile', ['_token' => auditFormToken(auditRequest($suspended, $url.'/profile')['body']), 'full_name' => 'Left behind', 'phone' => 'not a phone']);
        $passwordModel->setStatus($bagOwnerId, 'suspended');
        expect(auditRequest($suspended, $url.'/profile')['location'] === '/login', 'A suspended session must go to login.');
        $loginAfterSuspension = auditRequest($suspended, $url.'/login')['body'];
        expect(str_contains($loginAfterSuspension, 'Vui lòng đăng nhập') && !str_contains($loginAfterSuspension, 'Left behind'), 'Login after a suspension must show its own message only.');
        $passwordModel->setStatus($bagOwnerId, 'active');
        expect(auditRequest($suspended, $url.'/profile')['location'] === '/login', 'A session ended by suspension must not come back.');
        // Password changed elsewhere: the next person on this browser sees their own form, with none of the last person's input or refusal.
        $shared = auditBrowser();
        auditSignIn($shared, $url, auditRequest($shared, $url.'/login')['body'], $bagEmail);
        auditRequest($shared, $url.'/profile', ['_token' => auditFormToken(auditRequest($shared, $url.'/profile')['body']), 'full_name' => 'Left behind', 'phone' => 'not a phone']);
        $elsewhere = auditBrowser();
        auditSignIn($elsewhere, $url, auditRequest($elsewhere, $url.'/login')['body'], $bagEmail);
        auditRequest($elsewhere, $url.'/profile/password', ['_token' => auditFormToken(auditRequest($elsewhere, $url.'/profile')['body']), 'current_password' => 'Password123!', 'new_password' => 'Changed-elsewhere-1', 'password_confirmation' => 'Changed-elsewhere-1']);
        expect(auditRequest($shared, $url.'/profile')['location'] === '/login', 'A session must end when its password changes elsewhere.');
        auditSignIn($shared, $url, auditRequest($shared, $url.'/login')['body'], 'host@home2home.test');
        $nextPerson = auditRequest($shared, $url.'/profile')['body'];
        expect(!str_contains($nextPerson, 'Left behind') && !str_contains($nextPerson, 'alert-danger'), 'The next person inherited a form from the session that ended.');
        // A login over a live session, with no request in between, starts just as clean.
        $over = auditBrowser();
        auditSignIn($over, $url, auditRequest($over, $url.'/login')['body'], 'admin@home2home.test');
        $overToken = auditFormToken(auditRequest($over, $url.'/profile')['body']);
        auditRequest($over, $url.'/profile', ['_token' => $overToken, 'full_name' => 'Left behind', 'phone' => 'not a phone']);
        auditRequest($over, $url.'/login', ['_token' => $overToken, 'email' => 'host@home2home.test', 'password' => 'Password123!']);
        $nextPerson = auditRequest($over, $url.'/profile')['body'];
        expect(!str_contains($nextPerson, 'Left behind') && !str_contains($nextPerson, 'alert-danger'), 'A login must start without the last person\'s form.');
    } finally {
        $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$bagOwnerId]);
    }
    echo "PASS a session that ends or a login that starts clears flash and every form bag, and messages set afterwards survive\n";

    // Kept input belongs to one form: its own scope gets it once, any other drops it, and a call with no scope works as it always did.
    \Core\Session::old(['kept' => 1], 'listing:5');
    expect(\Core\Session::pullOld('listing:1') === [] && !isset($_SESSION['_old']), 'Another scope must drop the kept input.');
    \Core\Session::old(['kept' => 1], 'listing:5');
    expect(\Core\Session::pullOld() === [] && !isset($_SESSION['_old']), 'A form with no scope must drop scoped input.');
    \Core\Session::old(['kept' => 1], 'listing:5');
    expect(\Core\Session::pullOld('listing:5') === ['kept' => 1] && \Core\Session::pullOld('listing:5') === [], 'The same scope gets the input once.');
    \Core\Session::old(['kept' => 1]);
    expect(\Core\Session::pullOld() === ['kept' => 1], 'A call with no scope must keep working.');
    \Core\Session::old(['kept' => 1]);
    expect(\Core\Session::pullOld('listing:5') === ['kept' => 1], 'Input kept with no scope is tied to no record.');
    $_SESSION = [];
    // A refused save whose redirect never loaded (a Stop, or a record deleted in another tab) must not fill a different record's form, where saving would overwrite that record.
    $scopeHost = auditBrowser();
    auditSignIn($scopeHost, $url, auditRequest($scopeHost, $url.'/login')['body'], 'host@home2home.test');
    $scopeHostToken = auditFormToken(auditRequest($scopeHost, $url.'/profile')['body']);
    $typed = 'Typedforanother';
    $listingInput = ['title' => $typed, 'description' => 'x', 'address' => 'x', 'city' => 'x', 'district' => 'x', 'property_type_id' => '0', 'price_per_night' => '1', 'max_guests' => '1', 'bedrooms' => '1', 'beds' => '1', 'bathrooms' => '1', 'cancellation_policy_id' => '0'];
    $articleInput = ['title' => $typed, 'body' => 'short', 'category' => 'none'];
    $guideInput = ['arrival_instructions' => str_repeat('x', 3001), 'door_code' => $typed, 'wifi_name' => '', 'wifi_password' => '', 'note' => ''];
    $promotionInput = ['code' => strtoupper($typed), 'description' => '', 'discount_type' => 'percent', 'discount_value' => '1'];
    $userInput = ['full_name' => $typed, 'email' => 'host@home2home.test', 'phone' => '', 'password' => '', 'roles' => ['guest']];
    $scopedForms = [
        'admin user' => [$admin, $adminToken, '/admin/users/3', $userInput, '/admin/users/3/edit', ['/admin/users/2/edit', '/admin']],
        'host listing' => [$scopeHost, $scopeHostToken, '/host/listings/1', $listingInput, '/host/listings/1/edit', ['/host/listings/2/edit', '/host/listings/create']],
        'new host listing' => [$scopeHost, $scopeHostToken, '/host/listings/create', $listingInput, '/host/listings/create', ['/host/listings/2/edit']],
        'admin listing' => [$admin, $adminToken, '/admin/listings/1', $listingInput, '/admin/listings/1/edit', ['/admin/listings/2/edit']],
        'article' => [$admin, $adminToken, '/admin/articles/1', $articleInput, '/admin/articles/1/edit', ['/admin/articles/2/edit', '/admin/articles/create']],
        'new article' => [$admin, $adminToken, '/admin/articles', $articleInput, '/admin/articles/create', ['/admin/articles/2/edit']],
        'check-in guide' => [$scopeHost, $scopeHostToken, '/host/listings/1/checkin-guide', $guideInput, '/host/listings/1/checkin-guide', ['/host/listings/2/checkin-guide']],
        'pricing' => [$scopeHost, $scopeHostToken, '/host/listings/1/pricing', ['weekend_nightly_rate' => $typed], '/host/listings/1/pricing', ['/host/listings/2/pricing']],
        'promotion' => [$admin, $adminToken, '/admin/promotions', ['id' => '1'] + $promotionInput, '/admin/promotions?edit=1', ['/admin/promotions', '/admin/promotions?edit=99999']],
        'new promotion' => [$admin, $adminToken, '/admin/promotions', $promotionInput, '/admin/promotions', ['/admin/promotions?edit=1']],
    ];
    foreach ($scopedForms as $formName => [$formBrowser, $formToken, $saveTo, $fields, $ownForm, $otherForms]) {
        foreach ($otherForms as $otherForm) {
            auditRequest($formBrowser, $url.$saveTo, ['_token' => $formToken] + $fields);
            expect(stripos(auditRequest($formBrowser, $url.$otherForm)['body'], $typed) === false, "$formName: refused input filled $otherForm.");
        }
        auditRequest($formBrowser, $url.$saveTo, ['_token' => $formToken] + $fields);
        expect(stripos(auditRequest($formBrowser, $url.$ownForm)['body'], $typed) !== false, "$formName: its own form lost the refused input.");
    }
    auditRequest($scopeHost, $url.'/host/listings/99999', ['_token' => $scopeHostToken] + $listingInput);
    expect(auditRequest($scopeHost, $url.'/host/listings/99999/edit')['status'] === 404 && stripos(auditRequest($scopeHost, $url.'/host/listings/1/edit')['body'], $typed) === false, 'A save refused on a missing listing filled another listing\'s form.');
    echo 'PASS refused input returns only to the form of its own record across '.count($scopedForms)." forms, and a call with no scope still works\n";

    // Apache answers 500 to a bare 419, so the status line has to name it. Checked by hand on Apache; here the line itself and the JSON body.
    $expired = curl_init($url.'/login');
    curl_setopt_array($expired, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'x=1', CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_TIMEOUT => 20]);
    $expiredAnswer = (string) curl_exec($expired);
    expect(str_starts_with($expiredAnswer, "HTTP/1.1 419 Page Expired\r\n") && str_contains($expiredAnswer, '"success":false') && str_contains($expiredAnswer, 'application/json'), 'A stale JSON request must answer 419 Page Expired with the JSON body.');
    echo "PASS a stale JSON request sends the whole 419 Page Expired status line and the JSON body\n";

    auditOversizedPost(['display_startup_errors' => '1', 'output_buffering' => '4096'], true);
    auditOversizedPost(['display_startup_errors' => '0', 'output_buffering' => '0'], false);
    echo "PASS a POST over post_max_size says so with the limit, returns to its page and changes nothing\n";

    foreach (['', '   ', '0912345678', '0912 345 678', '+84 912 345 678', '84912345678', '(091) 234-5678'] as $goodPhone) {
        expect(\App\Models\Trust::phoneAllowed($goodPhone), "Phone refused: $goodPhone");
    }
    foreach (['abc', '0212345678', '091234567', '09123456789', '---', "0912345678\xff", "09\x0012345678", '"><img src=x>'] as $badPhone) {
        expect(!\App\Models\Trust::phoneAllowed($badPhone), 'Bad phone accepted.');
    }
    $phoneEmail = "audit-phone-$tag@home2home.test";
    $browser = auditBrowser();
    $form = auditRequest($browser, $url.'/register')['body'];
    $result = auditRequest($browser, $url.'/register', ['_token' => auditFormToken($form), 'full_name' => 'Audit', 'email' => $phoneEmail, 'phone' => 'abc', 'password' => 'Abcdefgh1']);
    expect($result['location'] === '/register' && $passwordModel->findByEmail($phoneEmail) === null, 'Register accepted a bad phone.');
    expect(str_contains(auditRequest($browser, $url.'/register')['body'], \App\Models\Trust::PHONE_RULE), 'Register must state the phone rule.');
    $result = auditRequest($admin, $url.'/admin/users', ['_token' => $adminToken, 'full_name' => 'Audit', 'email' => $phoneEmail, 'phone' => 'abc', 'password' => 'Abcdefgh1', 'roles' => ['guest']]);
    expect($result['location'] === '/admin' && $passwordModel->findByEmail($phoneEmail) === null, 'Admin create accepted a bad phone.');
    expect(str_contains(auditRequest($admin, $url.'/admin')['body'], \App\Models\Trust::PHONE_RULE), 'Admin create must state the phone rule.');
    $phoneFixture = $passwordModel->create($phoneEmail, 'Password123!', 'Audit phone fixture', '0912345678');
    $seedGuest = $passwordModel->findByEmail('guest@home2home.test');
    $phoneBookingId = null;
    try {
        auditRequest($admin, $url.'/admin/users/'.$phoneFixture, ['_token' => $adminToken, 'full_name' => 'Audit phone fixture', 'email' => $phoneEmail, 'phone' => 'abc', 'password' => '', 'roles' => ['guest']]);
        expect($passwordModel->findByEmail($phoneEmail)['phone'] === '0912345678', 'Admin edit accepted a bad phone.');
        auditRequest($admin, $url.'/admin/users/'.$phoneFixture, ['_token' => $adminToken, 'full_name' => 'Audit phone fixture', 'email' => $phoneEmail, 'phone' => '+84 912 345 679', 'password' => '', 'roles' => ['guest']]);
        expect($passwordModel->findByEmail($phoneEmail)['phone'] === '+84 912 345 679', 'Admin edit refused a good phone.');
        $phoneBrowser = auditBrowser();
        auditSignIn($phoneBrowser, $url, auditRequest($phoneBrowser, $url.'/login')['body'], $phoneEmail);
        $profileToken = auditFormToken(auditRequest($phoneBrowser, $url.'/profile')['body']);
        auditRequest($phoneBrowser, $url.'/profile', ['_token' => $profileToken, 'full_name' => 'Audit phone fixture', 'phone' => 'abc'], $url.'/profile');
        $returned = auditRequest($phoneBrowser, $url.'/profile')['body'];
        expect($passwordModel->findByEmail($phoneEmail)['phone'] === '+84 912 345 679' && str_contains($returned, \App\Models\Trust::PHONE_RULE) && str_contains($returned, 'value="abc"'), 'Profile must refuse a bad phone and keep what was typed.');
        auditRequest($phoneBrowser, $url.'/profile', ['_token' => $profileToken, 'full_name' => 'Audit phone fixture', 'phone' => ''], $url.'/profile');
        expect($passwordModel->findByEmail($phoneEmail)['phone'] === null, 'The phone stays optional on the profile.');
        // A value stored before this rule still has to display safely wherever it is shown.
        $badStored = '"><img src=x>';
        $passwordModel->updateProfile((int) $seedGuest['id'], $seedGuest['full_name'], $badStored, $seedGuest['avatar_url']);
        $service = new BookingService();
        $hostRow = $passwordModel->findByEmail('host@home2home.test');
        $booked = $service->book(1, (int) $seedGuest['id'], '2098-03-01', '2098-03-03', 1);
        $phoneBookingId = $booked['booking_id'];
        if ($booked['status'] === 'pending') { $service->hostTransition($phoneBookingId, (int) $hostRow['id'], 'confirmed'); }
        foreach ([$url.'/bookings/'.$phoneBookingId, $url.'/host'] as $page) {
            $shown = auditRequest($hostBrowser, $page);
            expect($shown['status'] === 200 && str_contains($shown['body'], e($badStored)) && !str_contains($shown['body'], $badStored), 'A stored bad phone must display escaped: '.$page);
        }
        expect(str_contains(auditRequest($hostBrowser, $url.'/bookings/'.$phoneBookingId)['body'], 'href="tel:'.e($badStored).'"'), 'A stored bad phone must stay inside its tel: link.');
    } finally {
        if ($phoneBookingId !== null) { $database->prepare('CALL sp_test_cleanup_booking(?)')->execute([$phoneBookingId]); }
        $passwordModel->updateProfile((int) $seedGuest['id'], $seedGuest['full_name'], $seedGuest['phone'], $seedGuest['avatar_url']);
        $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$phoneFixture]);
    }
    echo "PASS phone uses the identity verification rule on register, profile, admin create and edit, blank allowed; a stored bad value displays escaped\n";

    expect(input_text(['a' => "\xff"], 'a') === '' && input_text(['a' => "a\xffb"], 'a', '7') === '7', 'Invalid UTF-8 must read like an absent value.');
    $legitimate = 'Trần Thị Ánh ơi, 你好 🏠 €';
    expect(input_text(['a' => $legitimate], 'a') === $legitimate && input_text(['a' => "a\0b"], 'a') === "a\0b", 'Valid text must pass through untouched.');
    expect(input_password(['a' => "caf\xe9\xe9\xe9\xe9"], 'a') === "caf\xe9\xe9\xe9\xe9" && input_password(['a' => ['x']], 'a') === '' && input_password([], 'a') === '', 'A password must come through byte for byte.');
    $utf8Email = "audit-utf8-$tag@home2home.test";
    $latin1Password = "caf\xe9\xe9\xe9\xe9\xe9";
    $utf8Fixture = $passwordModel->create($utf8Email, $latin1Password, 'Audit UTF-8 fixture', null);
    $utf8Catalog = 'Audit UTF-8 '.$tag;
    try {
        $utf8Browser = auditBrowser();
        $form = auditRequest($utf8Browser, $url.'/login')['body'];
        expect(auditRequest($utf8Browser, $url.'/login', '_token='.auditFormToken($form).'&email='.urlencode($utf8Email).'&password=caf%E9%E9%E9%E9%E9')['location'] === '/', 'A password that is not valid UTF-8 must still log in.');
        $profileToken = auditFormToken(auditRequest($utf8Browser, $url.'/profile')['body']);
        auditRequest($utf8Browser, $url.'/profile', '_token='.$profileToken.'&full_name=Abc%FFdef&phone=', $url.'/profile');
        expect($passwordModel->findByEmail($utf8Email)['full_name'] === 'Audit UTF-8 fixture', 'Profile stored a name with invalid UTF-8.');
        auditRequest($utf8Browser, $url.'/profile');
        auditRequest($utf8Browser, $url.'/profile', ['_token' => $profileToken, 'full_name' => $legitimate, 'phone' => ''], $url.'/profile');
        expect($passwordModel->findByEmail($utf8Email)['full_name'] === $legitimate, 'A Vietnamese, CJK and emoji name must be saved unchanged.');
        $reporter = auditRequest($utf8Browser, $url.'/reports', '_token='.$profileToken.'&category=other&listing_id=1&description=Abcdefghij%FFklmnop', $url.'/listings/1');
        expect(str_contains(auditRequest($utf8Browser, $url.$reporter['location'])['body'], 'Thông tin báo cáo không hợp lệ.'), 'A report with invalid UTF-8 must be refused.');
        $utf8Register = auditBrowser();
        $form = auditRequest($utf8Register, $url.'/register')['body'];
        $refused = auditRequest($utf8Register, $url.'/register', '_token='.auditFormToken($form).'&full_name=Abc%FFdef&email='.urlencode("audit-utf8b-$tag@home2home.test").'&phone=&password=Abcdefgh1');
        expect($refused['location'] === '/register' && $passwordModel->findByEmail("audit-utf8b-$tag@home2home.test") === null, 'Register stored a name with invalid UTF-8.');
        auditRequest($admin, $url.'/admin/users/'.$utf8Fixture, ['_token' => $adminToken, 'full_name' => $legitimate, 'email' => $utf8Email, 'phone' => '', 'password' => "caf\xe9\xe9\xe9\xe9\xe9\xe9\xe9", 'roles' => ['guest']]);
        expect(password_verify("caf\xe9\xe9\xe9\xe9\xe9\xe9\xe9", $passwordModel->findByEmail($utf8Email)['password_hash']), 'Admin edit must not read a password with other bytes as blank.');
        auditRequest($admin, $url.'/admin/catalog', '_token='.$adminToken.'&kind=amenity&id=0&name=Abc%FFdef&detail=x&active=1', $url.'/admin/catalog');
        expect(str_contains(auditRequest($admin, $url.'/admin/catalog')['body'], 'Thông tin danh mục không hợp lệ.'), 'Catalog must refuse a name with invalid UTF-8.');
    } finally {
        $database->prepare('CALL sp_test_cleanup_user(?)')->execute([$utf8Fixture]);
    }
    echo "PASS invalid UTF-8 text reads as absent and is never stored as ?; valid text and password bytes pass through\n";
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
