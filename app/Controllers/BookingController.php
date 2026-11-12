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
use App\Services\TripContext;
use App\Models\Listing;
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
        $this->render('bookings/show',['title'=>'Booking #'.$id,'booking'=>$booking,'back'=>$back,'actorId'=>(int)$user['id'],'conversation'=>$conversation,'cancelOld'=>Session::pullOld('cancel:'.(int)$id),
            'trustBadges'=>(new \App\Models\Trust())->badges([(int)$booking['guest_id'],(int)$booking['host_id']],(int)$user['id']),
            'checkinGuide'=>(new \App\Models\Trust())->guideForBooking((int)$booking['id'],(int)$user['id']),
            'extras'=>(new BookingExtras())->bookingDetail((int)$id,(int)$user['id']),
            'paymentAttempts'=>(new \App\Models\Payment())->bookingAttempts((int)$id,(int)$user['id'])]);
    }
    public function reviewStay(string $id): void
    {
        $listing=(new Listing())->findPublic((int)$id);
        if (!$listing) { http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']); return; }
        $trip=TripContext::inputs($_GET); $quote=null; $error=null; $reviewToken='';
        try {
            $quote=(new BookingService())->quote((int)$id,$trip['check_in']??'',$trip['check_out']??'',(int)filter_var($trip['guests']??null,FILTER_VALIDATE_INT),$trip['promo_code']??'');
            // Remove an unusable promo only after showing its rejection in the quote.
            $reviewedTrip=$trip; if (empty($quote['promo']['code'])) { unset($reviewedTrip['promo_code']); }
            if (\Core\Auth::check()) {
                if ((int)$listing['host_id']===(int)\Core\Auth::id()) { throw new \DomainException('Bạn không thể đặt chỗ ở của chính mình.'); }
                if (!\Core\Auth::hasRole('guest')) { throw new \DomainException('Tài khoản của bạn không có vai trò Guest nên không thể đặt chỗ. Hãy liên hệ Admin nếu cần.'); }
                $liveReviews=[];
                foreach ($_SESSION['stay_reviews']??[] as $storedToken=>$storedReview) { if ($storedReview['expires']>time()) { $liveReviews[$storedToken]=$storedReview; } }
                $_SESSION['stay_reviews']=$liveReviews;
                $_SESSION['stay_reviews']=array_slice($_SESSION['stay_reviews'],-19,null,true);
                $reviewToken=bin2hex(random_bytes(24));
                $_SESSION['stay_reviews'][$reviewToken]=['listing'=>(int)$id,'user'=>(int)\Core\Auth::id(),'trip'=>$reviewedTrip,
                    'fingerprint'=>TripContext::fingerprint($quote,$listing),'expires'=>time()+1800];
            }
        } catch (\DomainException $exception) { $error=$exception->getMessage(); http_response_code(422); }
        $contactStatus=\Core\Auth::check()?(new \App\Models\ContactVerification())->status((int)\Core\Auth::id()):[];
        if (\Core\Auth::check() && (empty($contactStatus['email_verified']) || empty($contactStatus['phone_verified']))) {
            $_SESSION['_verification_return']=TripContext::path((int)$id,$trip,true);
        }
        $this->render('bookings/review',['contactStatus'=>$contactStatus, 'title'=>'Xem lại chuyến đi của bạn','listing'=>$listing,'trip'=>$trip,'quote'=>$quote,'error'=>$error,'reviewToken'=>$reviewToken]);
    }

    public function create(string $id): never
    {
        $trip=TripContext::inputs($_POST);
        if (!\Core\Auth::check()) {
            Session::flash('error','Vui lòng đăng nhập để tiếp tục.');
            $this->redirect('/login?'.http_build_query(['next'=>TripContext::path((int)$id,$trip,true)]));
        }
        Csrf::ensure();
        $user=$this->requireAuth();
        try { (new \App\Models\ContactVerification())->ensureBookable((int)$user['id']); }
        catch (\DomainException $exception) {
            $_SESSION['_verification_return']=TripContext::path((int)$id,$trip,true);
            Session::flash('error',$exception->getMessage()); $this->redirect('/profile#contact-verification');
        }
        if(!in_array('guest',$user['roles'],true)){Session::flash('error','Tài khoản của bạn không có vai trò Guest nên không thể đặt chỗ. Hãy liên hệ Admin nếu cần.');$this->redirect('/listings/'.(int)$id);}
        $token=is_string($_POST['review_token']??null)?$_POST['review_token']:'';
        if ($token==='') {
            try{$booking=(new BookingService())->book((int)$id,(int)$user['id'],(is_string($_POST['check_in']??null)?$_POST['check_in']:''),(is_string($_POST['check_out']??null)?$_POST['check_out']:''),(int)filter_var($_POST['guests']??null,FILTER_VALIDATE_INT),(is_string($_POST['promo_code']??null)?$_POST['promo_code']:''),$this->shownTotal());$bookingId=$booking['booking_id'];Session::flash('success',$booking['status']==='confirmed'?'Đặt ngay thành công! Booking #'.$bookingId.' đã được xác nhận. Bạn thanh toán khi nhận phòng.':'Yêu cầu đặt chỗ #'.$bookingId.' đã được gửi tới Host.');$this->redirect('/bookings');}catch(\Throwable $e){Session::old($this->bookingOld((int)$id));Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');$this->redirect('/listings/'.$id);}
        }
        $review=$_SESSION['stay_reviews'][$token]??null;
        if (!$review || $review['listing']!==(int)$id || $review['user']!==(int)$user['id'] || $review['expires']<time()) {
            Session::flash('error','Vui lòng xem lại thông tin trước khi xác nhận.');
            $this->redirect(TripContext::path((int)$id,$trip,true));
        }
        if (isset($review['booking_id'])) { $this->redirect('/bookings/'.$review['booking_id']); }
        $trip=$review['trip'];
        try {
            $booking=(new BookingService())->bookReviewed((int)$id,(int)$user['id'],$trip,$review['fingerprint']);
            $_SESSION['stay_reviews'][$token]['booking_id']=$booking['booking_id'];
            Session::flash('success',$booking['status']==='confirmed'?'Chuyến đi đã được xác nhận.':'Yêu cầu đã được gửi. Chờ Host xác nhận.');
            $this->redirect('/bookings/'.$booking['booking_id']);
        } catch (\Throwable $exception) {
            Session::flash('error',$exception instanceof \DomainException?$exception->getMessage():'Không thể hoàn tất thao tác. Vui lòng thử lại.');
            $this->redirect(TripContext::path((int)$id,$trip,true));
        }
    }
    public function cancel(string $id): never {Csrf::ensure();$user=$this->requireAuth();$reason=input_text($_POST,'reason');if(mb_strlen($reason)>5000){Session::flash('error','Lý do hủy tối đa 5000 ký tự.');Session::old(['reason'=>$reason],'cancel:'.(int)$id);$this->redirect('/bookings/'.(int)$id);}try{$result=(new BookingService())->cancelByGuest((int)$id,(int)$user['id'],$reason);Session::flash('success',!$result['was_paid']?'Đã hủy booking.':($result['refund_amount']<=0?'Đã hủy booking. Theo chính sách hủy, khoản đã thanh toán không được hoàn.':'Đã hủy booking. Mức hoàn dự kiến: '.$result['refund_percent'].'%.'));}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');}$this->redirect('/bookings');}
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
        $old['promo_code']=mb_check_encoding($promo,'UTF-8')?mb_substr(BookingService::cleanPromoCode($promo),0,20):'';
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

