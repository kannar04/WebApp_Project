<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__).'/core/bootstrap.php';

$config=require dirname(__DIR__).'/config/payment.php';
$adminRaw=(string)($config['host_payout_system_admin_id']??'');
if(!ctype_digit($adminRaw)||(int)$adminRaw<1){fwrite(STDERR,"HOST_PAYOUT_SYSTEM_ADMIN_ID must identify an active Admin.\n");exit(1);}
$adminId=(int)$adminRaw;$model=new App\Models\HostPayout();
if(!$model->schedulerLock(true)){echo "Another Host payout run is active; skipped.\n";exit(0);}
$created=0;$failed=0;$amount=0.0;
try{
    foreach($model->hourlyCandidates($adminId) as $candidate){
        try{$payout=$model->createPayout((int)$candidate['booking_id'],$adminId);$created++;$amount+=(float)($payout['net_amount']??0);}
        catch(Throwable $exception){$failed++;fwrite(STDERR,'Booking #'.(int)$candidate['booking_id'].': '.$exception->getMessage()."\n");}
    }
}finally{$model->schedulerLock(false);}
echo 'Host payout run completed: '.$created.' created, '.$failed.' failed, '.number_format($amount,2,'.','').' VND queued.'.PHP_EOL;
exit($failed>0?1:0);
