<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/core/bootstrap.php';
$db=Core\Database::connection();
if(!(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='oauth_identities'")->fetchColumn()){require __DIR__.'/install_google_auth.php';}
$sql=file_get_contents(__DIR__.'/migrations/20261108_facebook_auth.sql');
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
echo "Facebook auth migration applied: $count statements. Existing accounts preserved.\n";
