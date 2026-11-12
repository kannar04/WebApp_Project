<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/core/bootstrap.php';
$sql=file_get_contents(__DIR__.'/migrations/20261112_host_payouts.sql');
$delimiter=';';$buffer='';$statements=[];
foreach(preg_split('/\R/',$sql) as $line){
    if(preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i',$line,$match)){
        if(trim($buffer)!==''){throw new RuntimeException('Unexpected SQL before delimiter.');}
        $delimiter=$match[1];continue;
    }
    if(trim($line)===''||str_starts_with(ltrim($line),'--')){continue;}
    $buffer.=$line."\n";
    if(str_ends_with(rtrim($buffer),$delimiter)){$statements[]=substr(rtrim($buffer),0,-strlen($delimiter));$buffer='';}
}
if(trim($buffer)!==''){throw new RuntimeException('Unterminated migration statement.');}
if(in_array('--check',$argv,true)){echo 'Migration parsed: '.count($statements)." statements. No database changes.\n";exit;}
$db=Core\Database::connection();
foreach($statements as $statement){$db->exec($statement);}
echo 'Host payouts migration applied: '.count($statements)." statements. Existing bookings preserved.\n";
