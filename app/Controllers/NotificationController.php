<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\Notification;
use Core\Controller;
use Core\Csrf;
use Core\Session;

final class NotificationController extends Controller
{
    public function index(): void
    {
        $user=$this->requireAuth();
        $this->render('notifications/index',['title'=>'Thông báo','notifications'=>(new Notification())->forUser((int)$user['id'])]);
    }
    public function read(string $id): never
    {
        Csrf::ensure(); $user=$this->requireAuth();
        try { (new Notification())->markRead((int)$id,(int)$user['id']); Session::flash('success','Đã đánh dấu đã đọc.'); }
        catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/notifications');
    }
}
