<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\BookingEmail;
use Core\ProcedureConnection;

final class BookingEmailService
{
    public function run(int $limit=50,?int $bookingId=null): array
    {
        if($limit<1||$limit>100){throw new \InvalidArgumentException('Batch size must be between 1 and 100.');}
        if(ProcedureConnection::connection()->inTransaction()){throw new \RuntimeException('Email delivery must run outside booking transactions.');}
        $mailer=new MailService();
        $config=require dirname(__DIR__,2).'/config/mail.php';
        if(!$mailer->configured()||!self::validBaseUrl($config['base_url'])){
            throw new \RuntimeException('Email is disabled or not configured. See README.md (Booking email). Queue left untouched.');
        }
        $queue=new BookingEmail();$result=['sent'=>0,'failed'=>0,'skipped'=>0,'busy'=>false];
        if(!$queue->lock(true)){$result['busy']=true;return $result;}
        try {
            foreach(($bookingId===null?$queue->pending($limit):$queue->pendingForBooking($bookingId,$limit)) as $row){
                $reason=null;
                if($row['booking_status']!=='confirmed'||$row['check_out']<=date('Y-m-d')){$reason='booking_not_current';}
                elseif($row['recipient_status']!=='active'||$row['recipient_deleted_at']!==null){$reason='recipient_inactive';}
                elseif(!filter_var($row['email'],FILTER_VALIDATE_EMAIL)){$reason='invalid_recipient';}
                if($reason!==null){$queue->finish((int)$row['id'],false,true,$reason);$result['skipped']++;continue;}
                if(!$queue->attempt((int)$row['id'])){continue;}
                $message=$this->message($row,$config['base_url'],$config['from']);
                $sent=$mailer->send($row['email'],$message['subject'],$message['text'],$message['html'],$message['message_id']);
                $queue->finish((int)$row['id'],$sent);
                $result[$sent?'sent':'failed']++;
            }
        } finally {$queue->lock(false);}
        return $result;
    }

    public static function validBaseUrl(string $url): bool
    {
        $parts=parse_url($url);
        return is_array($parts)&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['query'])&&!isset($parts['fragment'])
            && (($parts['scheme']??'')==='https'||(($parts['scheme']??'')==='http'&&in_array($parts['host'],['127.0.0.1','localhost','::1'],true)));
    }

    private function message(array $row,string $baseUrl,string $sender): array
    {
        $id=(int)$row['booking_id'];$link=$baseUrl.'/bookings/'.$id;
        $guest=(int)$row['user_id']===(int)$row['guest_id'];
        $intro=$guest?'Đặt chỗ của bạn đã được xác nhận.':'Một đặt chỗ tại chỗ ở của bạn đã được xác nhận.';
        $amount=$row['currency']==='VND'?BookingService::money((float)$row['total_amount']):number_format((float)$row['total_amount'],2,',','.').' '.$row['currency'];
        $facts=['Mã đặt chỗ'=>'#'.$id,'Chỗ ở'=>$row['listing_title'],'Địa chỉ'=>$row['address'].', '.$row['city'],
            'Nhận phòng'=>date('d/m/Y',strtotime($row['check_in'])),'Trả phòng'=>date('d/m/Y',strtotime($row['check_out'])),
            'Số khách'=>(string)(int)$row['guest_count'],'Khách đặt'=>$row['guest_name'],'Host'=>$row['host_name'],'Tổng giá trị đặt chỗ'=>$amount];
        $text='Xin chào '.$row['recipient_name'].",\n\n".$intro."\n\n";
        $rows='';
        foreach($facts as $label=>$value){
            $text.=$label.': '.$value."\n";
            $rows.='<tr><th style="padding:10px 12px;text-align:left;vertical-align:top;color:#52645b;font-weight:400;border-bottom:1px solid #e5e8e1">'.e($label).'</th><td style="padding:10px 12px;border-bottom:1px solid #e5e8e1">'.e($value).'</td></tr>';
        }
        $note='Email này xác nhận đặt chỗ, không phải xác nhận đã thanh toán. Xem trạng thái thanh toán trong chi tiết chuyến đi.';
        $text.="\n".$note."\n\nChi tiết chuyến đi: ".$link."\n\nHome2Home\n";
        $html='<!doctype html><html lang="vi"><head><meta charset="utf-8"></head><body style="margin:0;background:#f8f7f3;font-family:Arial,sans-serif;color:#233c35"><main style="max-width:600px;margin:24px auto;padding:28px;background:#fffefa"><p style="color:#176b56;font-size:20px;font-weight:bold">Home2Home</p><h1 style="font-size:24px">Đặt chỗ #'.$id.' đã xác nhận</h1><p>Xin chào '.e($row['recipient_name']).',</p><p>'.e($intro).'</p><table style="width:100%;border-collapse:collapse;font-size:14px">'.$rows.'</table><p style="font-size:13px;color:#52645b;line-height:1.6">'.e($note).'</p><p><a style="display:inline-block;background:#176b56;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none" href="'.e($link).'">Xem chi tiết chuyến đi</a></p></main></body></html>';
        $domain=substr($sender,strrpos($sender,'@')+1);
        return ['subject'=>'Home2Home - Xác nhận đặt chỗ #'.$id,'text'=>$text,'html'=>$html,
            'message_id'=>'<booking-confirmed-'.(int)$row['id'].'-'.substr(hash('sha256',$baseUrl),0,12).'@'.$domain.'>'];
    }
}
