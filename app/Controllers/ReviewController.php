<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Review;
use Core\Controller;
use Core\Csrf;
use Core\Session;

final class ReviewController extends Controller
{
    public function host(): void
    {
        $user=$this->requireRole('host');
        $this->render('reviews/manage',['title'=>'Đánh giá của khách','adminMode'=>false,'reviews'=>(new Review())->forHost((int)$user['id'])]);
    }
    public function admin(): void
    {
        $user=$this->requireRole('admin');
        $this->render('reviews/manage',['title'=>'Quản lý đánh giá','adminMode'=>true,'reviews'=>(new Review())->forAdmin((int)$user['id'])]);
    }
    public function reply(string $id): never
    {
        Csrf::ensure(); $user=$this->requireRole('host');
        try {
            $reply=$_POST['reply']??'';
            if (!is_string($reply) || mb_strlen($reply)>5000 || trim($reply)==='') { throw new \DomainException('Phản hồi cần từ 1 đến 5000 ký tự.'); }
            (new Review())->reply((int)$id,(int)$user['id'],$reply); Session::flash('success','Đã lưu phản hồi.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/host/reviews');
    }
    public function moderate(string $id): never
    {
        Csrf::ensure(); $user=$this->requireRole('admin');
        try {
            $status=$_POST['status']??'';
            if (!is_string($status)) { throw new \DomainException('Trạng thái không hợp lệ.'); }
            (new Review())->moderate((int)$id,(int)$user['id'],$status); Session::flash('success','Đã cập nhật đánh giá; lịch sử và giới hạn một đánh giá/booking được giữ.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/admin/reviews');
    }
}
