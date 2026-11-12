<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\DirectConversation;
use Core\Csrf;
use Core\Session;

final class DirectMessageController extends \Core\Controller
{
    public function open(): never
    {
        Csrf::ensure(); $user=$this->requireAuth();
        try {
            $listing=filter_var($_POST['listing_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($listing===false) { throw new \DomainException('Chỗ ở không hợp lệ.'); }
            $chat=(new DirectConversation())->open((int)$user['id'],$listing);
            $this->redirect('/messages/direct/'.$chat);
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/messages');
    }
    public function show(string $id): void
    {
        $user=$this->requireAuth(); $model=new DirectConversation();
        try { $state=$model->state((int)$id,(int)$user['id']); $messages=$model->thread((int)$id,(int)$user['id']); }
        catch (\DomainException $exception) { http_response_code(404);$this->render('errors/404',['title'=>'Không tìm thấy cuộc trò chuyện']);return; }
        $this->render('messages/direct',['title'=>'Tin nhắn · '.$state['other_name'],'chatId'=>(int)$id,
            'booking'=>['id'=>(int)$id], 'conversation'=>['state'=>$state,'messages'=>$messages,'draft'=>(string)Session::pull('_direct_draft:'.(int)$id,'')],
            'messageAction'=>'/messages/direct/'.(int)$id, 'updatesUrl'=>'/api/messages/direct/'.(int)$id]);
    }
    public function updates(string $id): never
    {
        $user=$this->requireAuth(); header('Cache-Control: no-store');$model=new DirectConversation();
        try { $this->json(true,'',['state'=>$model->state((int)$id,(int)$user['id']),'messages'=>$model->thread((int)$id,(int)$user['id'])]); }
        catch (\DomainException $exception) { $this->json(false,$exception->getMessage(),null,[],404); }
    }
    public function send(string $id): never
    {
        Csrf::ensure();$user=$this->requireAuth();$body=\App\Models\BookingMessage::normalize($_POST['body']??null);
        $ajax=str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json');
        try {
            if ($body===null) { throw new \DomainException('Tin nhắn cần từ 1 đến 2000 ký tự.'); }
            (new DirectConversation())->send((int)$id,(int)$user['id'],$body);
            if ($ajax) { $this->json(true,''); }
        } catch (\DomainException $exception) {
            if ($ajax) { $this->json(false,$exception->getMessage(),null,[],422); }
            Session::put('_direct_draft:'.(int)$id,mb_substr(input_text($_POST,'body'),0,2000));
            Session::flash('error',$exception->getMessage());
        }
        $this->redirect('/messages/direct/'.(int)$id);
    }
}
