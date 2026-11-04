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
    public function index(): void {$user=$this->requireAuth();$this->render('bookings/index',['title'=>'Chuyến đi của bạn','bookings'=>(new Booking())->forGuest((int)$user['id']),'payments'=>(new BookingExtras())->paymentMap((int)$user['id'],'guest')]);}
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
    public function reviewStay(string $id): void
    {
        $listing=(new Listing())->findPublic((int)$id);
        if (!$listing) { http_response_code(404); $this->render('errors/404',['title'=>'Không tìm thấy chỗ ở']); return; }
        $trip=TripContext::inputs($_GET); $quote=null; $error=null; $reviewToken='';
        try {
            $quote=(new BookingService())->quote((int)$id,$trip['check_in']??'',$trip['check_out']??'',(int)filter_var($trip['guests']??null,FILTER_VALIDATE_INT),$trip['promo_code']??'');
            // Remove an unusable promo only after showing its rejection in the quote.
            if (empty($quote['promo']['code'])) { unset($trip['promo_code']); }
            if (\Core\Auth::check()) {
                $_SESSION['stay_reviews']=array_filter($_SESSION['stay_reviews']??[],fn($r)=>$r['expires']>time());
                $_SESSION['stay_reviews']=array_slice($_SESSION['stay_reviews'],-19,null,true);
                $reviewToken=bin2hex(random_bytes(24));
                $_SESSION['stay_reviews'][$reviewToken]=['listing'=>(int)$id,'user'=>(int)\Core\Auth::id(),'trip'=>$trip,
                    'fingerprint'=>TripContext::fingerprint($quote,$listing),'expires'=>time()+1800];
            }
        } catch (\DomainException $exception) { $error=$exception->getMessage(); http_response_code(422); }
        $this->render('bookings/review',['title'=>'Xem lại chuyến đi của bạn','listing'=>$listing,'trip'=>$trip,'quote'=>$quote,'error'=>$error,'reviewToken'=>$reviewToken]);
    }

    public function create(string $id): never
    {
        $trip=TripContext::inputs($_POST);
        if (!\Core\Auth::check()) {
            $this->redirect('/login?'.http_build_query(['next'=>TripContext::path((int)$id,$trip,true)]));
        }
        Csrf::ensure();
        $user=$this->requireAuth();
        $token=is_string($_POST['review_token']??null)?$_POST['review_token']:'';
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
    public function cancel(string $id): never {Csrf::ensure();$user=$this->requireAuth();try{$result=(new BookingService())->cancelByGuest((int)$id,(int)$user['id'],(string)($_POST['reason']??''));Session::flash('success','Đã hủy booking. Mức hoàn dự kiến: '.$result['refund_percent'].'%.');}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException ? $e->getMessage() : 'Không thể hoàn tất thao tác. Vui lòng thử lại.');}$this->redirect('/bookings');}
    public function review(string $id): never {Csrf::ensure();$user=$this->requireAuth();$rating=(int)($_POST['rating']??0);$comment=trim((string)($_POST['comment']??''));if($rating<1||$rating>5||$comment===''){Session::flash('error','Vui lòng nhập số sao và nhận xét hợp lệ.');$this->redirect('/bookings');}$ok=(new Review())->create((int)$id,(int)$user['id'],$rating,$comment);Session::flash($ok?'success':'error',$ok?'Cảm ơn đánh giá của bạn.':'Chỉ booking đã hoàn thành và chưa đánh giá mới được thực hiện.');$this->redirect('/bookings');}
}

