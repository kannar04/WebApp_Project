<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/core/bootstrap.php';
$db=Core\Database::connection();
$sql=file_get_contents(__DIR__.'/migrations/20261108_google_auth.sql');
$delimiter=';';$buffer='';$count=0;
foreach(preg_split('/\R/',$sql) as $line){
    if(preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',$line,$match)){
        if(trim($buffer)!==''){throw new RuntimeException('Unexpected SQL before delimiter.');}
        $delimiter=$match[1];continue;
    }
    if(trim($line)===''||str_starts_with(ltrim($line),'--')){continue;}
    $buffer.=$line."\n";
    if(str_ends_with(rtrim($buffer),$delimiter)){$db->exec(substr(rtrim($buffer),0,-strlen($delimiter)));$buffer='';$count++;}
}
if(trim($buffer)!==''){throw new RuntimeException('Unterminated migration statement.');}
echo "Google auth migration applied: $count statements. Existing accounts preserved.\n";
