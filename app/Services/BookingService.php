<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DomainException;
use PDO;

final class BookingService
{
    private PDO $db;
    public function __construct(){ $this->db=Database::connection(); }

    public function quote(int $listingId,string $checkIn,string $checkOut,int $guests): array
    {
        [$listing,$start,$end,$nights]=$this->validateRequest($listingId,$checkIn,$checkOut,$guests,false);
        $subtotal=(float)$listing['base_nightly_rate']*$nights;
        return ['nights'=>$nights,'nightly_price'=>(float)$listing['base_nightly_rate'],'subtotal'=>$subtotal,'fee'=>(float)$listing['fee_amount'],'total'=>$subtotal+(float)$listing['fee_amount']];
    }

    public function create(int $listingId,int $guestId,string $checkIn,string $checkOut,int $guests): int
    {
        $this->db->beginTransaction();
        try{
            [$listing,$start,$end,$nights]=$this->validateRequest($listingId,$checkIn,$checkOut,$guests,true);
            if((int)$listing['host_id']===$guestId){throw new DomainException('Bạn không thể đặt chỗ ở của chính mình.');}
            $subtotal=(float)$listing['base_nightly_rate']*$nights;$fee=(float)$listing['fee_amount'];$total=$subtotal+$fee;
            $policy=json_encode(['name'=>$listing['policy_name'],'cutoff_hours'=>(int)$listing['cutoff_hours'],'early_refund_pct'=>(float)$listing['early_refund_pct'],'late_refund_pct'=>(float)$listing['late_refund_pct'],'refund_fees'=>(bool)$listing['refund_fees']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $s=$this->db->prepare("INSERT INTO bookings(listing_id,guest_id,check_in,check_out,guest_count,status,currency,fee_amount,total_amount,policy_snapshot,created_at,updated_at) VALUES(?,?,?,?,?,'pending','VND',?,?,?,NOW(6),NOW(6))");
            $s->execute([$listingId,$guestId,$checkIn,$checkOut,$guests,$fee,$total,$policy]);$id=(int)$this->db->lastInsertId();
            $night=$this->db->prepare('INSERT INTO booking_nights(booking_id,stay_date,nightly_amount) VALUES(?,?,?)');
            foreach(new DatePeriod($start,new DateInterval('P1D'),$end) as $date){$night->execute([$id,$date->format('Y-m-d'),$listing['base_nightly_rate']]);}
            $this->event($id,$guestId,null,'pending','Khách gửi yêu cầu đặt chỗ.');
            $this->notify((int)$listing['host_id'],$id,'booking_created','Bạn có yêu cầu đặt chỗ mới cho “'.$listing['title'].'”.');
            $this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }

    public function hostTransition(int $bookingId,int $hostId,string $target): void
    {
        if(!in_array($target,['confirmed','rejected','completed'],true)){throw new DomainException('Trạng thái không hợp lệ.');}
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT b.*,l.host_id,l.title FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=? FOR UPDATE");$s->execute([$bookingId]);$b=$s->fetch();
            if(!$b||(int)$b['host_id']!==$hostId){throw new DomainException('Bạn không có quyền cập nhật booking này.');}
            $allowed=['pending'=>['confirmed','rejected'],'confirmed'=>['completed']];
            if(!in_array($target,$allowed[$b['status']]??[],true)){throw new DomainException('Chuyển trạng thái booking không hợp lệ.');}
            if($target==='confirmed'){
                $c=$this->db->prepare("SELECT COUNT(*) FROM bookings WHERE listing_id=? AND id<>? AND status='confirmed' AND check_in < ? AND check_out > ?");$c->execute([$b['listing_id'],$bookingId,$b['check_out'],$b['check_in']]);
                if((int)$c->fetchColumn()>0){throw new DomainException('Ngày này vừa được booking khác xác nhận.');}
                $blocked=$this->db->prepare("SELECT COUNT(*) FROM listing_availability WHERE listing_id=? AND is_open=0 AND stay_date>=? AND stay_date<?");$blocked->execute([$b['listing_id'],$b['check_in'],$b['check_out']]);
                if((int)$blocked->fetchColumn()>0){throw new DomainException('Khoảng ngày có ngày đang bị chặn.');}
            }
            $this->db->prepare('UPDATE bookings SET status=?,updated_at=NOW(6) WHERE id=?')->execute([$target,$bookingId]);
            $this->event($bookingId,$hostId,$b['status'],$target,'Host cập nhật trạng thái.');
            $messages=['confirmed'=>'Yêu cầu đặt chỗ đã được xác nhận.','rejected'=>'Yêu cầu đặt chỗ đã bị từ chối.','completed'=>'Chuyến ở đã hoàn thành.'];
            $this->notify((int)$b['guest_id'],$bookingId,'booking_'.$target,$messages[$target]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }

    public function cancelByGuest(int $bookingId,int $guestId,string $reason=''): array
    {
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT b.*,l.host_id FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=? FOR UPDATE");$s->execute([$bookingId]);$b=$s->fetch();
            if(!$b||(int)$b['guest_id']!==$guestId){throw new DomainException('Booking không tồn tại hoặc không thuộc về bạn.');}
            if(!in_array($b['status'],['pending','confirmed'],true)){throw new DomainException('Booking ở trạng thái này không thể hủy.');}
            $policy=json_decode((string)$b['policy_snapshot'],true,512,JSON_THROW_ON_ERROR);$refundPercent=0;if($b['status']==='pending'){$refundPercent=100;}else{$hours=(strtotime($b['check_in'].' 14:00:00')-time())/3600;$refundPercent=$hours>=(int)$policy['cutoff_hours']?(float)$policy['early_refund_pct']:(float)$policy['late_refund_pct'];}
            $refundableBase=(float)$b['total_amount']-(!empty($policy['refund_fees'])?0:(float)$b['fee_amount']);$refund=round(max(0,$refundableBase)*$refundPercent/100,2);
            $this->db->prepare("UPDATE bookings SET status='cancelled',updated_at=NOW(6) WHERE id=?")->execute([$bookingId]);
            $calculation=json_encode(['policy'=>$policy,'refund_percent'=>$refundPercent,'refundable_base'=>$refundableBase],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $this->db->prepare('INSERT INTO booking_cancellations(booking_id,cancelled_by,reason,refund_amount,calculation_details,created_at) VALUES(?,?,?,?,?,NOW(6))')->execute([$bookingId,$guestId,trim($reason),$refund,$calculation]);
            $this->event($bookingId,$guestId,$b['status'],'cancelled','Khách hủy booking.');$this->notify((int)$b['host_id'],$bookingId,'booking_cancelled','Khách đã hủy booking.');
            $this->db->commit();return ['refund_percent'=>$refundPercent,'refund_amount'=>$refund];
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }

    public function adminTransition(int $bookingId,int $adminId,string $target): void
    {
        if(!in_array($target,['confirmed','rejected','completed'],true)){throw new DomainException('Trạng thái không hợp lệ.');}
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare("SELECT b.*,l.title FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=? FOR UPDATE");$s->execute([$bookingId]);$b=$s->fetch();
            if(!$b){throw new DomainException('Booking không tồn tại.');}
            $allowed=['pending'=>['confirmed','rejected'],'confirmed'=>['completed']];
            if(!in_array($target,$allowed[$b['status']]??[],true)){throw new DomainException('Chuyển trạng thái booking không hợp lệ.');}
            if($target==='confirmed'){
                $c=$this->db->prepare("SELECT COUNT(*) FROM bookings WHERE listing_id=? AND id<>? AND status='confirmed' AND check_in < ? AND check_out > ?");$c->execute([$b['listing_id'],$bookingId,$b['check_out'],$b['check_in']]);
                if((int)$c->fetchColumn()>0){throw new DomainException('Ngày này đã có booking xác nhận.');}
            }
            $this->db->prepare('UPDATE bookings SET status=?,updated_at=NOW(6) WHERE id=?')->execute([$target,$bookingId]);
            $this->event($bookingId,$adminId,$b['status'],$target,'Admin cập nhật trạng thái.');
            $this->notify((int)$b['guest_id'],$bookingId,'booking_'.$target,'Admin đã cập nhật booking thành '.$target.'.');
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }

    private function validateRequest(int $listingId,string $checkIn,string $checkOut,int $guests,bool $lock): array
    {
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$checkIn);$end=DateTimeImmutable::createFromFormat('!Y-m-d',$checkOut);$today=new DateTimeImmutable('today');
        if(!$start||$start->format('Y-m-d')!==$checkIn||!$end||$end->format('Y-m-d')!==$checkOut){throw new DomainException('Ngày nhận hoặc trả phòng không hợp lệ.');}
        if($start<$today){throw new DomainException('Không thể đặt ngày trong quá khứ.');}if($end<=$start){throw new DomainException('Ngày trả phòng phải sau ngày nhận phòng.');}
        $sql="SELECT l.*,cp.name policy_name,cp.cutoff_hours,cp.early_refund_pct,cp.late_refund_pct,cp.refund_fees FROM listings l JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id WHERE l.id=? AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL".($lock?' FOR UPDATE':'');
        $s=$this->db->prepare($sql);$s->execute([$listingId]);$listing=$s->fetch();if(!$listing){throw new DomainException('Chỗ ở không khả dụng.');}if($guests<1||(int)$listing['max_guests']<$guests){throw new DomainException('Số khách vượt quá sức chứa.');}
        $blocked=$this->db->prepare("SELECT COUNT(*) FROM listing_availability WHERE listing_id=? AND is_open=0 AND stay_date>=? AND stay_date<?");$blocked->execute([$listingId,$checkIn,$checkOut]);if((int)$blocked->fetchColumn()>0){throw new DomainException('Khoảng ngày đã chọn có ngày không cho thuê.');}
        $conflict=$this->db->prepare("SELECT COUNT(*) FROM bookings WHERE listing_id=? AND status IN ('pending','confirmed') AND check_in < ? AND check_out > ?");$conflict->execute([$listingId,$checkOut,$checkIn]);if((int)$conflict->fetchColumn()>0){throw new DomainException('Khoảng ngày này đã có yêu cầu đặt chỗ khác.');}
        return [$listing,$start,$end,(int)$start->diff($end)->days];
    }
    private function event(int $bookingId,int $actorId,?string $from,string $to,string $note): void {$this->db->prepare('INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at) VALUES(?,?,?,?,?,NOW(6))')->execute([$bookingId,$actorId,$from,$to,$note]);}
    private function notify(int $userId,int $bookingId,string $type,string $content): void {$this->db->prepare("INSERT INTO notifications(user_id,booking_id,booking_event_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(?,?,NULL,'in_app',?,'Cập nhật booking',?,'sent',1,NOW(6))")->execute([$userId,$bookingId,$type,$content]);}
}

