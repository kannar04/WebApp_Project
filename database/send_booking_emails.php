<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/core/bootstrap.php';
$limit=50;$test=null;
foreach(array_slice($argv,1) as $argument){
    if(preg_match('/^--limit=(\d{1,3})$/D',$argument,$match)&&(int)$match[1]>=1&&(int)$match[1]<=100){$limit=(int)$match[1];continue;}
    if(str_starts_with($argument,'--test=')&&filter_var(substr($argument,7),FILTER_VALIDATE_EMAIL)){$test=substr($argument,7);continue;}
    fwrite(STDERR,"Usage: php database/send_booking_emails.php [--limit=1..100] [--test=you@example.com]\n");exit(2);
}
try {
    if($test!==null){
        $mailer=new App\Services\MailService();
        if(!$mailer->configured()){throw new RuntimeException('Email is disabled or not configured. See README.md (Booking email).');}
        $sent=$mailer->send($test,'Home2Home - Mail configuration test','This is a Home2Home mail configuration test. No booking was created or changed.');
        echo $sent?"Test email accepted by the configured transport.\n":"Test email failed. Check private SMTP settings and server logs.\n";exit($sent?0:1);
    }
    $result=(new App\Services\BookingEmailService())->run($limit);
    echo $result['busy']?"Another booking email worker is running; skipped.\n":sprintf("Booking emails: %d accepted, %d failed, %d skipped.\n",$result['sent'],$result['failed'],$result['skipped']);
    exit($result['failed']>0?1:0);
} catch(Throwable $exception) {
    fwrite(STDERR,$exception instanceof RuntimeException&&!($exception instanceof PDOException)?$exception->getMessage()."\n":"Booking email job failed. Check the database migration and private configuration.\n");exit(1);
}
