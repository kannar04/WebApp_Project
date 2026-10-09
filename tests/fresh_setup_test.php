<?php
declare(strict_types=1);

// Run from an independent copy of eligible Git files; never initializes the shared DB.
require dirname(__DIR__).'/core/bootstrap.php';
require dirname(__DIR__).'/database/procedures/install.php';
$root = dirname(__DIR__);
$config = require $root.'/config/database.php';
$app = require $root.'/config/app.php';
if (PHP_SAPI !== 'cli' || $app['env'] !== 'local' || !in_array($config['host'], ['localhost','127.0.0.1','::1'], true)) {
    throw new RuntimeException('Fresh setup tests require local CLI/loopback.');
}
$testDatabase = 'db_home2home_schema_test_'.bin2hex(random_bytes(6));
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$exists->execute([$testDatabase]);
if ((int)$exists->fetchColumn() !== 0) { throw new RuntimeException('Generated test database already exists.'); }
$created = false; $process = null; $curl = null; $serverOutput = null;
try {
    $schema = str_replace('db_home2home', $testDatabase, file_get_contents($root.'/database/schema.sql'));
    $pdo->exec($schema); $created = true;
    $seed = str_replace('db_home2home', $testDatabase, file_get_contents($root.'/database/seed.sql'));
    $pdo->exec($seed); $pdo->exec($seed);
    installHome2HomeProcedures($pdo);
    echo "PASS independent workspace: schema/seed repeat and production-only routine installation\n";
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    if ($listener === false) { throw new RuntimeException('Could not reserve test server port.'); }
    $address = stream_socket_get_name($listener, false); fclose($listener);
    $baseUrl = 'http://'.$address;
    $environment = array_merge(getenv(), ['APP_ENV'=>'local','APP_DEBUG'=>'false','APP_URL'=>$baseUrl,
        'DB_HOST'=>$config['host'],'DB_PORT'=>(string)$config['port'],'DB_DATABASE'=>$testDatabase,
        'DB_USERNAME'=>$config['username'],'DB_PASSWORD'=>$config['password']]);
    $serverOutput = tmpfile();
    $process = proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/public',$root.'/public/router.php'],
        [0=>['pipe','r'],1=>$serverOutput,2=>$serverOutput], $pipes, $root, $environment, ['bypass_shell'=>true]);
    if (!is_resource($process)) { throw new RuntimeException('Could not start isolated PHP server.'); }
    fclose($pipes[0]);
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]);
    $request = static function (string $path, ?array $data = null) use ($curl,$baseUrl): array {
        curl_setopt($curl, CURLOPT_URL, $baseUrl.$path);
        curl_setopt($curl, CURLOPT_POST, $data !== null);
        if ($data !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data)); }
        else { curl_setopt($curl, CURLOPT_HTTPGET, true); }
        $body = curl_exec($curl);
        return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'url'=>curl_getinfo($curl,CURLINFO_EFFECTIVE_URL),'body'=>$body];
    };
    $expect = static function (bool $valid, string $message): void { if (!$valid) { throw new RuntimeException($message); } };
    for ($attempt=0; $attempt<30; $attempt++) {
        $page = $request('/');
        if ($page['status'] === 200) { break; }
        usleep(100000);
    }
    $expect($page['status'] === 200 && str_contains($page['body'],'Ngôi nhà thông Đà Lạt'), 'Fresh home failed.');
    foreach (['/login','/listings/1','/assets/css/app.css','/assets/js/app.js'] as $path) { $expect($request($path)['status'] === 200, 'Fresh page/asset failed: '.$path); }
    $start = (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
    $end = (new DateTimeImmutable('today'))->modify('+12 days')->format('Y-m-d');
    $quote = $request('/api/listings/1/quote?'.http_build_query(['check_in'=>$start,'check_out'=>$end,'guests'=>2]));
    $expect($quote['status'] === 200 && (float)json_decode($quote['body'],true)['data']['total'] === 2650000.0, 'Fresh quote failed.');
    echo "PASS fresh HTTP home/detail/login/assets and server quote\n";
    foreach (['guest'=>'/profile','host'=>'/host','admin'=>'/admin'] as $role=>$destination) {
        $login = $request('/login');
        $expect((bool)preg_match('/name="_token" value="([^"]+)"/', $login['body'], $match), 'Fresh login CSRF missing.');
        $loggedIn = $request('/login', ['_token'=>$match[1],'email'=>$role.'@home2home.test','password'=>'Password123!']);
        $expect($loggedIn['status'] === 200, 'Fresh role login failed: '.$role);
        $page = $request($destination);
        $expect($page['status'] === 200, 'Fresh role page failed: '.$role);
        if ($role === 'guest') { $expect($request('/admin')['status'] === 403, 'Fresh Guest accessed Admin.'); }
        $expect((bool)preg_match('/name="_token" value="([^"]+)"/', $page['body'], $match), 'Fresh logout CSRF missing.');
        $expect($request('/logout', ['_token'=>$match[1]])['status'] === 200, 'Fresh logout failed.');
    }
    echo "PASS fresh Guest/Host/Admin login, pages, Guest permission denial and logout\n";
} finally {
    if ($curl !== null) { curl_close($curl); }
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_resource($serverOutput)) { fclose($serverOutput); }
    if ($created && preg_match('/^db_home2home_schema_test_[a-f0-9]{12}$/D', $testDatabase)) {
        $pdo->exec('DROP DATABASE `'.$testDatabase.'`');
        echo "PASS exact owned test database cleanup; shared database untouched\n";
    }
}
