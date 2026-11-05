<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Booking;
use App\Models\BookingExtras;
use App\Models\Review;
use App\Services\BookingService;
use Core\Controller;
use Core\Csrf;
use Core\Session;
final class BookingController extends Controller
{
    public function index(): void {$user=$this->requireAuth();$this->render('bookings/index',['title'=>'Chuyến đi của bạn','bookings'=>(new Booking())->forGuest((int)$user['id']),'payments'=>(new BookingExtras())->paymentMap((int)$user['id'],'guest'),'reviewOld'=>Session::pull('_review_old',[])]);}
    public function show(string $id): void
    {
        $user=$this->requireAuth();
        try { $booking=(new Booking())->detail((int)$id,(int)$user['id']); }
        catch (\DomainException $exception) {
            http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy booking']); return;
        }
        $back=\Core\Auth::hasRole('admin') ? '/admin' : ((int)$booking['host_id']===(int)$user['id'] ? '/host' : '/bookings');
        $conversation=\App\Services\ConversationService::bookingPanel((int)$booking['id'],(int)$user['id'],$booking);
        $this->render('bookings/show',['title'=>'Booking #'.$id,'booking'=>$booking,'back'=>$back,'actorId'=>(int)$user['id'],'conversation'=>$conversation,
            'trustBadges'=>(new \App\Models\Trust())->badges([(int)$booking['guest_id'],(int)$booking['host_id']],(int)$user['id']),
            'checkinGuide'=>(new \App\Models\Trust())->guideForBooking((int)$booking['id'],(int)$user['id']),
            'extras'=>(new BookingExtras())->bookingDetail((int)$id,(int)$user['id']),
            'paymentAttempts'=>(new \App\Models\Payment())->bookingAttempts((int)$id,(int)$user['id'])]);
    }
    public function create(string $id): never {Csrf::ensure();$user=$this->requireAuth();try{$booking=(new BookingService())->book((int)$id,(int)$user['id'],(is_string($_POST['check_in']??null)?$_POST['check_in']:''),(is_string($_POST['check_out']??null)?$_POST['check_out']:''),(int)filter_var($_POST['guests']??null,FILTER_VALIDATE_INT),(is_string($_POST['promo_code']??null)?$_POST['promo_code']:''),$this->shownTotal());$bookingId=$booking['booking_id'];Session::flash('success',$booking['status']==='confirmed'?'Đặt ngay thành công! Booking #'.$bookingId.' đã được xác nhận. Bạn thanh toán khi nhận phòng.':'Yêu cầu đặt chỗ #'.$bookingId.' đã được gửi tới Host.');$this->redirect('/bookings');}catch(\Throwable $e){Session::old($this->bookingOld((int)$id));Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');$this->redirect('/listings/'.$id);}}
    public function cancel(string $id): never {Csrf::ensure();$user=$this->requireAuth();try{$result=(new BookingService())->cancelByGuest((int)$id,(int)$user['id'],input_text($_POST,'reason'));Session::flash('success',!$result['was_paid']?'Đã hủy booking.':($result['refund_amount']<=0?'Đã hủy booking. Theo chính sách hủy, khoản đã thanh toán không được hoàn.':'Đã hủy booking. Mức hoàn dự kiến: '.$result['refund_percent'].'%.'));}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');}$this->redirect('/bookings');}
    public function review(string $id): never {Csrf::ensure();$user=$this->requireAuth();$rating=(int)input_text($_POST,'rating','0');$comment=trim(input_text($_POST,'comment'));if($rating<1||$rating>5||$comment===''){Session::flash('error','Vui lòng nhập số sao và nhận xét hợp lệ.');$this->redirect('/bookings');}if(mb_strlen($comment)>Review::MAX_COMMENT_LENGTH){$this->reviewFailed((int)$id,$rating,$comment,'Nhận xét tối đa 5000 ký tự.');}try{$ok=(new Review())->create((int)$id,(int)$user['id'],$rating,$comment);}catch(\Throwable $e){$this->reviewFailed((int)$id,$rating,$comment,$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');}Session::flash($ok?'success':'error',$ok?'Cảm ơn đánh giá của bạn.':'Chỉ booking đã hoàn thành và chưa đánh giá mới được thực hiện.');$this->redirect('/bookings');}
    /** What the Guest typed for a refused request, only in the shape the form accepts; the next submit is checked again as usual. */
    /** The total on the Guest's screen when they pressed book, or null when the quote had not shown one. */
    private function shownTotal(): ?string
    {
        $total = input_text($_POST, 'shown_total');
        return \Core\Validator::isDecimal($total) ? $total : null;
    }

    private function bookingOld(int $listingId): array
    {
        $old=['listing_id'=>$listingId,'guests'=>(int)filter_var($_POST['guests']??null,FILTER_VALIDATE_INT)];
        foreach(['check_in','check_out'] as $field){
            $value=input_text($_POST,$field);
            $old[$field]=preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)?$value:'';
        }
        $promo=input_text($_POST,'promo_code');
        $old['promo_code']=mb_check_encoding($promo,'UTF-8')?mb_substr(trim($promo),0,20):'';
        return $old;
    }
    /** Back to the review form with what the Guest typed, up to the limit, as the other forms keep their input. */
    private function reviewFailed(int $bookingId, int $rating, string $comment, string $message): never
    {
        Session::put('_review_old',['booking_id'=>$bookingId,'rating'=>$rating,'comment'=>mb_substr($comment,0,Review::MAX_COMMENT_LENGTH)]);
        Session::flash('error',$message);
        $this->redirect('/bookings');
    }
}

