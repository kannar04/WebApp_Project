<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Booking;
use App\Models\Review;
use App\Services\BookingService;
use Core\Controller;
use Core\Csrf;
use Core\Session;
final class BookingController extends Controller
{
    public function index(): void {$user=$this->requireAuth();$this->render('bookings/index',['title'=>'Chuyến đi của bạn','bookings'=>(new Booking())->forGuest((int)$user['id'])]);}
    public function create(string $id): never {Csrf::ensure();$user=$this->requireAuth();try{$bookingId=(new BookingService())->create((int)$id,(int)$user['id'],(string)($_POST['check_in']??''),(string)($_POST['check_out']??''),(int)($_POST['guests']??0));Session::flash('success','Yêu cầu đặt chỗ #'.$bookingId.' đã được gửi tới Host.');$this->redirect('/bookings');}catch(\Throwable $e){Session::flash('error',$e->getMessage());$this->redirect('/listings/'.$id);}}
    public function cancel(string $id): never {Csrf::ensure();$user=$this->requireAuth();try{$result=(new BookingService())->cancelByGuest((int)$id,(int)$user['id'],(string)($_POST['reason']??''));Session::flash('success','Đã hủy booking. Mức hoàn dự kiến: '.$result['refund_percent'].'%.');}catch(\Throwable $e){Session::flash('error',$e->getMessage());}$this->redirect('/bookings');}
    public function review(string $id): never {Csrf::ensure();$user=$this->requireAuth();$rating=(int)($_POST['rating']??0);$comment=trim((string)($_POST['comment']??''));if($rating<1||$rating>5||$comment===''){Session::flash('error','Vui lòng nhập số sao và nhận xét hợp lệ.');$this->redirect('/bookings');}$ok=(new Review())->create((int)$id,(int)$user['id'],$rating,$comment);Session::flash($ok?'success':'error',$ok?'Cảm ơn đánh giá của bạn.':'Chỉ booking đã hoàn thành và chưa đánh giá mới được thực hiện.');$this->redirect('/bookings');}
}

