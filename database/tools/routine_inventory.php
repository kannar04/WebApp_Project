<?php
declare(strict_types=1);
// Read-only migration inventory. Does not execute business queries or edit files.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);
$files = array_slice($argv, 1);
$output = [];
foreach ($files as $file) {
    $source = file_get_contents($root.'/'.$file);
    $offset = 0; $method = 'script'; $expectName = false;
    foreach (token_get_all($source) as $token) {
        $value = is_array($token) ? $token[1] : $token;
        if (is_array($token) && $token[0] === T_FUNCTION) { $expectName = true; }
        elseif ($expectName && is_array($token) && $token[0] === T_STRING) { $method = $value; $expectName = false; }
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $sql = substr($value, 1, -1);
            $sql = $value[0] === "'" ? str_replace(["\\'", "\\\\"], ["'", "\\"], $sql) : stripcslashes($sql);
            if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql)) {
                $output[] = ['file'=>$file,'method'=>$method,'offset'=>$offset,'literal'=>$value,'sql'=>$sql];
            }
        }
        $offset += strlen($value);
    }
}
echo json_encode($output, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
