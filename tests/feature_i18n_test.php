<?php
declare(strict_types=1);
// Nice-to-have i18n: Vietnamese/English interface.
// Static part: every interface string in views/JS goes through __()/h2hT() and has an English entry;
// every Vietnamese runtime message (controllers, services, procedures) is covered by a dictionary key or template.
// HTTP part (HOME2HOME_TEST_URL): English pages carry lang="en" and no untranslated dictionary text.
// Optional: --report-vietnamese lists the remaining Vietnamese text nodes per English page (seed data is expected there).
require dirname(__DIR__).'/core/bootstrap.php';

use Core\I18n;

$i18nConfig = require dirname(__DIR__).'/config/database.php';
if (PHP_SAPI !== 'cli' || !preg_match('/^(db_home2home_schema_test_[a-f0-9]{12}|db_h2h_ws_[a-z0-9_]+)$/D', $i18nConfig['database'])
    || !in_array($i18nConfig['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('I18n feature suite requires an isolated schema-test or db_h2h_ws_* database on loopback.');
}
$root = dirname(__DIR__);
$failures = [];
$checkpoint = 0;

function i18nExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function i18nIsVietnamese(string $text): bool
{
    return (bool) preg_match('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $text);
}

/** Path prefixes from H2H_I18N_SCOPE (comma separated); empty means the whole project (the merged gate). */
function i18nScope(): array
{
    $scope = trim((string) getenv('H2H_I18N_SCOPE'));
    return $scope === '' ? [] : array_map('trim', explode(',', $scope));
}

/** Records a failure unless a scope is set and the message names none of its paths. */
function i18nFail(array &$failures, string $message): void
{
    $scope = i18nScope();
    if ($scope === []) { $failures[] = $message; return; }
    foreach ($scope as $prefix) {
        if ($prefix !== '' && str_contains($message, $prefix)) { $failures[] = $message; return; }
    }
}

/** Cross-area expectations: fatal in the merged gate, a NOTE while one agent works on a scoped slice. */
function i18nSoft(bool $condition, string $message): void
{
    if ($condition) { return; }
    if (i18nScope() === []) { throw new RuntimeException($message); }
    echo "NOTE (scoped run) $message\n";
}
/** Prints PASS/FAIL for the checks since the last checkpoint; failures are listed together at the end. */
function i18nSection(array $failures, int &$checkpoint, string $summary): void
{
    $new = count($failures) - $checkpoint;
    echo ($new === 0 ? 'PASS ' : "FAIL ($new) ").$summary."\n";
    $checkpoint = count($failures);
}
/** @return string[] placeholder names in a template, sorted */
function i18nSlots(string $text): array
{
    preg_match_all('/\{([a-z_][a-z0-9_]*)\}/', $text, $matches);
    $slots = $matches[1];
    sort($slots);
    return $slots;
}

/** Value of a PHP single/double-quoted literal token without interpolation. */
function i18nPhpLiteral(string $raw): string
{
    $quote = $raw[0];
    $body = substr($raw, 1, -1);
    if ($quote === "'") {
        return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
    }
    return stripcslashes($body);
}

/** Significant tokens (no whitespace/comments) of a PHP file. */
function i18nTokens(string $file): array
{
    $tokens = [];
    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $tokens[] = $token;
    }
    return $tokens;
}

function i18nTokenText(mixed $token): string
{
    return is_array($token) ? $token[1] : (string) $token;
}

/** True when token $index is the first argument of a call named $name, e.g. __('...'). */
function i18nIsCallArgument(array $tokens, int $index, string $pattern): bool
{
    return $index >= 2 && i18nTokenText($tokens[$index - 1]) === '(' && preg_match($pattern, i18nTokenText($tokens[$index - 2]));
}

/** JS string/template literals with offsets, skipping comments and regex literals. */
function i18nJsStrings(string $source): array
{
    $strings = [];
    $length = strlen($source);
    $index = 0;
    $previous = '';
    while ($index < $length) {
        $char = $source[$index];
        $next = $source[$index + 1] ?? '';
        if ($char === '/' && $next === '/') {
            $end = strpos($source, "\n", $index);
            $index = $end === false ? $length : $end;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $end = strpos($source, '*/', $index + 2);
            $index = $end === false ? $length : $end + 2;
            continue;
        }
        if ($char === '"' || $char === "'" || $char === '`') {
            $start = $index;
            $value = '';
            $index++;
            while ($index < $length && $source[$index] !== $char) {
                if ($source[$index] === '\\') {
                    $value .= $source[$index].($source[$index + 1] ?? '');
                    $index += 2;
                    continue;
                }
                $value .= $source[$index];
                $index++;
            }
            $strings[] = ['value' => $value, 'offset' => $start, 'quote' => $char];
            $index++;
            $previous = 'x';
            continue;
        }
        if ($char === '/' && ($previous === '' || strpbrk($previous, '(,=:[!&|?{};+-*%<>~^') !== false)) {
            $index++;
            $inClass = false;
            while ($index < $length) {
                $current = $source[$index];
                if ($current === '\\') { $index += 2; continue; }
                if ($current === '[') { $inClass = true; }
                elseif ($current === ']') { $inClass = false; }
                elseif (($current === '/' && !$inClass) || $current === "\n") { break; }
                $index++;
            }
            $index++;
            $previous = 'x';
            continue;
        }
        if (!ctype_space($char)) { $previous = $char; }
        $index++;
    }
    return $strings;
}

function i18nJsLiteral(string $value): string
{
    return strtr($value, ['\\\\' => '\\', "\\'" => "'", '\\"' => '"', '\\n' => "\n"]);
}

// ---------------------------------------------------------------- dictionaries
$dictionary = [];
$sources = [];
$patternEnglish = [];
$patternSources = [];
$scriptDictionary = [];
foreach (glob($root.'/lang/en/*.php') as $file) {
    $entries = require $file;
    i18nExpect(is_array($entries), 'Dictionary must return an array: '.basename($file));
    foreach ($entries as $vietnamese => $english) {
        $where = basename($file).': '.$vietnamese;
        if (!is_string($vietnamese) || trim($vietnamese) === '' || !is_string($english) || trim($english) === '') {
            i18nFail($failures, 'Empty dictionary entry in lang/en/'.$where);
            continue;
        }
        if (i18nSlots($vietnamese) !== i18nSlots($english)) { i18nFail($failures, 'Placeholder mismatch in lang/en/'.$where); }
        if (str_starts_with(basename($file), 'patterns')) {
            $slots = i18nSlots($vietnamese);
            if ($slots === [] ) { i18nFail($failures, 'Pattern without {placeholder} (use an exact entry) in lang/en/'.$where); }
            if (count($slots) !== count(array_unique($slots))) { i18nFail($failures, 'Pattern repeats a placeholder in lang/en/'.$where); }
            if (isset($patternEnglish[$vietnamese]) && $patternEnglish[$vietnamese] !== $english) {
                i18nFail($failures, 'Conflicting English for template "'.$vietnamese.'" in lang/en/'.basename($file).' and lang/en/'.$patternSources[$vietnamese]);
            }
            $patternEnglish[$vietnamese] = $english;
            $patternSources[$vietnamese] ??= basename($file);
            continue;
        }
        if (basename($file) === 'js.php') {
            $scriptDictionary[$vietnamese] = $english;
            continue;
        }
        if (isset($dictionary[$vietnamese]) && $dictionary[$vietnamese] !== $english) {
            i18nFail($failures, 'Conflicting English for "'.$vietnamese.'" in lang/en/'.basename($file).' and lang/en/'.$sources[$vietnamese]);
        }
        $dictionary[$vietnamese] = $english;
        $sources[$vietnamese] = basename($file);
    }
}
$patterns = I18n::patterns();
$coverageTexts = array_merge(array_keys($dictionary), array_keys($patterns));
i18nSection($failures, $checkpoint, 'dictionaries: '.count($dictionary).' exact, '.count($patterns).' templates, '.count($scriptDictionary).' script entries');

// ---------------------------------------------------------------- translator behaviour
I18n::setLocale('vi');
i18nExpect(__('Đăng nhập') === 'Đăng nhập', 'Vietnamese must return the source text.');
i18nExpect(__('Xin chào {name}', ['name' => 'An']) === 'Xin chào An', 'Vietnamese placeholders must be filled.');
I18n::setLocale('en');
i18nExpect(__('Một câu chưa có trong từ điển') === 'Một câu chưa có trong từ điển', 'Missing keys must fall back to the source.');
if (isset($dictionary['Đăng nhập'])) { i18nExpect(__('Đăng nhập') === $dictionary['Đăng nhập'], 'English lookup failed.'); }
foreach ($patterns as $template => $english) {
    $sample = $template;
    $expected = $english;
    foreach (i18nSlots($template) as $slot) {
        $sample = str_replace('{'.$slot.'}', 'X'.$slot.'9', $sample);
        $expected = str_replace('{'.$slot.'}', 'X'.$slot.'9', $expected);
    }
    $translated = I18n::matchPattern($sample);
    if ($translated !== $expected) { i18nFail($failures, 'Template does not round-trip its own sample in lang/en/'.$patternSources[$template].': '.$template); }
}
I18n::setLocale('vi');
foreach (['/', '/bookings?page=2', '/listings/1'] as $path) { i18nExpect(I18n::safeReturnPath($path) === $path, 'Safe return path rejected: '.$path); }
foreach (['//evil.example', 'https://evil.example/', '/\\evil', "/a\nb", '', null, ['x']] as $path) {
    i18nExpect(I18n::safeReturnPath($path) === '/', 'Unsafe return path accepted.');
}
i18nSection($failures, $checkpoint, "translator: source fallback, placeholders, templates and safe return paths");

// ---------------------------------------------------------------- views: every Vietnamese string goes through __()
$viewKeys = 0;
$dynamicCalls = 0;
$allowedNative = ['Tiếng Việt', 'Chuyển sang tiếng Việt'];
$viewFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app/Views', FilesystemIterator::SKIP_DOTS));
foreach ($viewFiles as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $root)) + 1);
    $tokens = i18nTokens($file->getPathname());
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) { continue; }
        [$id, $text, $line] = $token;
        if ($id === T_STRING && $text === '__' && i18nTokenText($tokens[$index + 1] ?? '') === '(') {
            $argument = $tokens[$index + 2] ?? null;
            if (!is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) { $dynamicCalls++; continue; }
            $key = i18nPhpLiteral($argument[1]);
            $viewKeys++;
            if (!isset($dictionary[$key])) { i18nFail($failures, "Missing English for view text in $relative:$line: $key"); }
            continue;
        }
        if ($id === T_INLINE_HTML && i18nIsVietnamese($text)) {
            i18nFail($failures, "Vietnamese HTML outside __() in $relative:$line: ".mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 80));
        } elseif (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && i18nIsVietnamese($text)) {
            $value = $id === T_CONSTANT_ENCAPSED_STRING ? i18nPhpLiteral($text) : $text;
            if (in_array($value, $allowedNative, true)) { continue; }
            if ($id !== T_CONSTANT_ENCAPSED_STRING || !i18nIsCallArgument($tokens, $index, '/^__$/')) {
                i18nFail($failures, "Vietnamese string outside __() in $relative:$line: ".mb_substr($value, 0, 80));
            }
        }
    }
}
i18nSection($failures, $checkpoint, "views: $viewKeys literal __() keys translated, $dynamicCalls dynamic __() calls, no bare Vietnamese");

// ---------------------------------------------------------------- JS: every Vietnamese string goes through h2hT()
$scriptKeys = 0;
foreach (glob($root.'/public/assets/js/*.js') as $file) {
    $source = file_get_contents($file);
    foreach (i18nJsStrings($source) as $string) {
        if (!i18nIsVietnamese($string['value'])) { continue; }
        $line = substr_count(substr($source, 0, $string['offset']), "\n") + 1;
        $where = 'public/assets/js/'.basename($file).':'.$line;
        if ($string['quote'] === '`' || !str_ends_with(rtrim(substr($source, 0, $string['offset'])), 'h2hT(')) {
            i18nFail($failures, "Vietnamese JS string outside h2hT('...') in $where: ".mb_substr($string['value'], 0, 80));
            continue;
        }
        $scriptKeys++;
        $key = i18nJsLiteral($string['value']);
        if (!isset($scriptDictionary[$key])) { i18nFail($failures, "Missing lang/en/js.php entry ($where): $key"); }
    }
}
i18nSection($failures, $checkpoint, "scripts: $scriptKeys h2hT() keys translated");

// ---------------------------------------------------------------- runtime messages are covered
function i18nCovered(string $text, array $dictionary, array $coverageTexts): bool
{
    if (isset($dictionary[$text])) { return true; }
    $fragment = trim($text, " \t\n\r:,.;-–—()#");
    if ($fragment === '' || !i18nIsVietnamese($fragment)) { return true; }
    // Only accented letters, no ASCII letter or space: a character table (e.g. slug folding), not a sentence.
    if (!preg_match('/[A-Za-z\s]/', $fragment)) { return true; }
    foreach ($coverageTexts as $candidate) {
        if (str_contains($candidate, $fragment)) { return true; }
    }
    return false;
}
$runtimeLiterals = 0;
$internal = '/^\\\\?(RuntimeException|LogicException|InvalidArgumentException|UnexpectedValueException|PDOException)$/';
foreach (['app/Controllers', 'app/Services', 'app/Models', 'core'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'I18n.php') { continue; }
        $tokens = i18nTokens($file->getPathname());
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) || !i18nIsVietnamese($token[1])) { continue; }
            if (i18nIsCallArgument($tokens, $index, $internal)) { continue; }
            $runtimeLiterals++;
            $value = $token[0] === T_CONSTANT_ENCAPSED_STRING ? i18nPhpLiteral($token[1]) : $token[1];
            if (!i18nCovered($value, $dictionary, $coverageTexts)) {
                i18nFail($failures, 'Runtime text without English entry/template in '.$directory.'/'.$file->getFilename().':'.$token[2].': '.mb_substr($value, 0, 100));
            }
        }
    }
}
$sqlLiterals = 0;
foreach (glob($root.'/database/procedures/[0-9]*.sql') as $file) {
    $source = preg_replace(['~/\*.*?\*/~s', '/--[^\n]*/'], '', file_get_contents($file));
    preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/u", $source, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[1] as [$literal, $offset]) {
        if (!i18nIsVietnamese($literal)) { continue; }
        $sqlLiterals++;
        $value = str_replace(["''", "\\'"], "'", $literal);
        if (!i18nCovered($value, $dictionary, $coverageTexts)) {
            i18nFail($failures, 'Procedure text without English entry/template in database/procedures/'.basename($file).':'.(substr_count(substr($source, 0, $offset), "\n") + 1).': '.mb_substr($value, 0, 100));
        }
    }
}
i18nSection($failures, $checkpoint, "runtime coverage: $runtimeLiterals PHP literals and $sqlLiterals procedure literals have English entries/templates");

if ($failures !== []) {
    echo "FAIL i18n static checks (".count($failures)."):\n  ".implode("\n  ", $failures)."\n";
    exit(1);
}

// ---------------------------------------------------------------- HTTP: English pages
$baseUrl = rtrim((string) getenv('HOME2HOME_TEST_URL'), '/');
if ($baseUrl === '') {
    echo "SKIP i18n HTTP checks: HOME2HOME_TEST_URL not set\n";
    exit(0);
}
$report = in_array('--report-vietnamese', $argv, true);

function i18nRequest(CurlHandle $curl, string $url, ?array $data = null): array
{
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data)); }
    else { curl_setopt($curl, CURLOPT_HTTPGET, true); }
    $body = (string) curl_exec($curl);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
        'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 'body' => $body];
}

function i18nToken(string $body): string
{
    i18nExpect((bool) preg_match('/name="_token" value="([^"]+)"/', $body, $match), 'CSRF token missing.');
    return $match[1];
}

/** Text nodes and user-visible attribute values of an HTML page (scripts/styles excluded). */
function i18nVisibleTexts(string $html): array
{
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);
    $texts = [];
    foreach ($xpath->query('//text()[not(ancestor::script) and not(ancestor::style)]') as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->nodeValue));
        if ($text !== '') { $texts[] = $text; }
    }
    foreach ($xpath->query('//@placeholder|//@title|//@aria-label|//@alt|//@data-confirm|//input[@type="submit"]/@value') as $attribute) {
        $text = trim($attribute->nodeValue);
        if ($text !== '') { $texts[] = $text; }
    }
    return $texts;
}

$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);

$home = i18nRequest($curl, $baseUrl.'/');
i18nExpect($home['status'] === 200 && str_contains($home['body'], '<html lang="vi"'), 'Default interface must be Vietnamese.');
$switched = i18nRequest($curl, $baseUrl.'/locale', ['_token' => i18nToken($home['body']), 'locale' => 'en', 'return_to' => '/login']);
i18nExpect($switched['status'] === 200 && str_ends_with(parse_url($switched['url'], PHP_URL_PATH), '/login'), 'Locale switch must return to the page.');
i18nExpect(str_contains($switched['body'], '<html lang="en"'), 'Switched page must be English.');
$rejected = i18nRequest($curl, $baseUrl.'/locale', ['_token' => i18nToken($switched['body']), 'locale' => 'fr', 'return_to' => '//evil.example/']);
i18nExpect($rejected['status'] === 200 && parse_url($rejected['url'], PHP_URL_HOST) === parse_url($baseUrl, PHP_URL_HOST)
    && str_contains($rejected['body'], '<html lang="en"'), 'Unsupported locale/off-site return must stay on site in the current language.');
$forged = i18nRequest($curl, $baseUrl.'/locale', ['_token' => 'forged', 'locale' => 'vi', 'return_to' => '/']);
i18nExpect(str_contains($forged['body'], '<html lang="en"'), 'Locale switch without CSRF must not change the language.');
$quote = i18nRequest($curl, $baseUrl.'/api/listings/1/quote?check_in=2000-01-02&check_out=2000-01-01&guests=1');
$quoteMessage = (string) (json_decode($quote['body'], true)['message'] ?? '');
i18nSoft($quoteMessage !== '' && !i18nIsVietnamese($quoteMessage), 'JSON messages must follow the English interface.');
echo "PASS locale switch: default vi, POST /locale + CSRF, unsupported/off-site/forged rejected, English JSON message\n";

preg_match_all("/router->get\\('([^']+)'/", file_get_contents($root.'/routes/web.php'), $routeMatches);
$paths = [];
foreach ($routeMatches[1] as $path) {
    if (str_starts_with($path, '/api/') || str_contains($path, 'reset-password')) { continue; }
    $paths[] = preg_replace('/\{[a-zA-Z_]+\}/', '1', $path);
}
$paths[] = '/khong-ton-tai-'.bin2hex(random_bytes(3));
$pagesChecked = 0;
$untranslated = [];
$vietnameseReport = [];
foreach (['anonymous' => null, 'guest' => 'guest', 'host' => 'host', 'admin' => 'admin'] as $label => $role) {
    if ($role !== null) {
        $login = i18nRequest($curl, $baseUrl.'/login');
        $loggedIn = i18nRequest($curl, $baseUrl.'/login', ['_token' => i18nToken($login['body']), 'email' => $role.'@home2home.test', 'password' => 'Password123!']);
        i18nExpect($loggedIn['status'] === 200 && str_contains($loggedIn['body'], '<html lang="en"'), 'English login failed for '.$label);
    } else {
        $wrong = i18nRequest($curl, $baseUrl.'/login', ['_token' => i18nToken(i18nRequest($curl, $baseUrl.'/login')['body']), 'email' => 'guest@home2home.test', 'password' => 'wrong-password']);
        i18nSoft((bool) preg_match('/<div class="alert alert-danger"[^>]*>(.*?)<\/div>/s', $wrong['body'], $alert) && !i18nIsVietnamese(strip_tags($alert[1])),
            'Flash messages must be shown in English.');
    }
    foreach ($paths as $path) {
        $page = i18nRequest($curl, $baseUrl.$path);
        if (!in_array($page['status'], [200, 404], true) || !str_contains($page['type'], 'text/html')) { continue; }
        // Redirects to the login page are checked once as their own route.
        if ($path !== '/login' && str_ends_with((string) parse_url($page['url'], PHP_URL_PATH), '/login')) { continue; }
        $pagesChecked++;
        if (!str_contains($page['body'], '<html lang="en"')) { $untranslated[] = "$label $path: html lang is not en"; }
        foreach (i18nVisibleTexts($page['body']) as $text) {
            if (isset($dictionary[$text]) && $dictionary[$text] !== $text) { $untranslated[$label.$path.$text] = "$label $path: $text"; }
            elseif ($report && i18nIsVietnamese($text)) { $vietnameseReport["$label $path"][$text] = true; }
        }
    }
    if ($role !== null) {
        $current = i18nRequest($curl, $baseUrl.'/profile');
        i18nRequest($curl, $baseUrl.'/logout', ['_token' => i18nToken($current['body'])]);
    }
}
curl_close($curl);
foreach ($vietnameseReport as $page => $texts) {
    echo "REPORT $page\n  ".implode("\n  ", array_keys($texts))."\n";
}
i18nExpect($pagesChecked >= 20, 'Too few English pages checked: '.$pagesChecked);
if ($untranslated !== [] && i18nScope() !== []) {
    echo "NOTE (scoped run) untranslated dictionary text on English pages (".count($untranslated)."):
  ".implode("
  ", array_values($untranslated))."
";
} elseif ($untranslated !== []) {
    echo "FAIL untranslated dictionary text on English pages (".count($untranslated)."):\n  ".implode("\n  ", array_slice(array_values($untranslated), 0, 100))."\n";
    exit(1);
}
echo "PASS English pages: $pagesChecked Guest/Host/Admin/anonymous pages render lang=en with no untranslated dictionary text\n";
