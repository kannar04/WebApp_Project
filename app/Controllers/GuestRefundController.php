<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\GuestRefund;
use App\Services\VietQr;
use Core\Controller;
use Core\Csrf;
use Core\Session;

final class GuestRefundController extends Controller
{
    private const GENERIC_ERROR='Không thể hoàn tất thao tác. Vui lòng thử lại.';
    public function guestIndex(): void{$user=$this->requireRole('guest');$model=new GuestRefund();$id=(int)$user['id'];$this->render('refunds/index',['title'=>'Nhận tiền hoàn','account'=>$model->account($id),'refunds'=>$model->guestRefunds($id),'old'=>Session::pullOld('guest-refund-account')]);}
    public function guestSaveAccount(): never
    {
        Csrf::ensure();$user=$this->requireRole('guest');$input=['bank_id'=>strtoupper($this->text('bank_id',20)),'bank_name'=>$this->text('bank_name',100),'account_no'=>preg_replace('/\s+/u','',$this->text('account_no',30))??'','account_name'=>mb_strtoupper($this->text('account_name',150),'UTF-8')];
        try{if(!preg_match('/^[A-Z0-9]{2,20}$/D',$input['bank_id'])){throw new \DomainException('Mã ngân hàng VietQR phải gồm 2–20 chữ cái hoặc số.');}if(mb_strlen($input['bank_name'])<2){throw new \DomainException('Vui lòng nhập tên ngân hàng.');}if(!preg_match('/^[0-9]{4,19}$/D',$input['account_no'])){throw new \DomainException('Số tài khoản phải gồm 4–19 chữ số.');}if(mb_strlen($input['account_name'])<2){throw new \DomainException('Vui lòng nhập tên chủ tài khoản.');}(new GuestRefund())->saveAccount((int)$user['id'],$input);Session::flash('success','Đã gửi tài khoản nhận hoàn tiền để Admin duyệt.');}
        catch(\Throwable $e){Session::old($input,'guest-refund-account');Session::flash('error',$e instanceof \DomainException?$e->getMessage():self::GENERIC_ERROR);} $this->redirect('/refunds');
    }
    public function adminIndex(): void
    {
        $user=$this->requireRole('admin');$status=is_string($_GET['status']??null)&&in_array($_GET['status'],['pending','approved','rejected'],true)?$_GET['status']:'';$model=new GuestRefund();$refunds=$model->adminRefunds((int)$user['id']);
        foreach($refunds as &$refund){$refund['qr_url']='';if($refund['row_type']==='refund'&&$refund['refund_status']==='pending'){$bank=['bank_id'=>$refund['bank_id'],'account_no'=>$refund['account_no'],'account_name'=>$refund['account_name']];if(VietQr::enabled($bank)){$refund['qr_url']=VietQr::imageUrl($bank,(float)$refund['amount'],(string)$refund['reference']);}}}unset($refund);
        $this->render('admin/guest-refunds',['title'=>'Hoàn tiền Guest','accounts'=>$model->adminAccounts((int)$user['id'],$status),'refunds'=>$refunds,'status'=>$status]);
    }
    public function adminDecideAccount(string $id): never{Csrf::ensure();$user=$this->requireRole('admin');$decision=$this->text('decision',12);$reason=$this->text('reason',500);try{if(!in_array($decision,['approved','rejected'],true)){throw new \DomainException('Quyết định không hợp lệ.');}(new GuestRefund())->decideAccount((int)$id,(int)$user['id'],$decision,$reason);Session::flash('success',$decision==='approved'?'Đã duyệt tài khoản nhận hoàn tiền.':'Đã từ chối tài khoản nhận hoàn tiền.');}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException?$e->getMessage():self::GENERIC_ERROR);}$this->redirect('/admin/guest-refunds');}
    public function adminCreate(string $bookingId): never{Csrf::ensure();$user=$this->requireRole('admin');$reason=$this->text('note',500);$shown=input_text($_POST,'shown_amount');try{$refund=(new GuestRefund())->create((int)$bookingId,(int)$user['id'],$reason,preg_match('/^\d+(?:\.\d{1,2})?$/D',$shown)?$shown:null);Session::flash('success','Đã tạo lệnh hoàn '.number_format((float)($refund['amount']??0),0,',','.').'đ. Chỉ xác nhận sau khi ngân hàng báo chuyển thành công.');}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException?$e->getMessage():self::GENERIC_ERROR);}$this->redirect('/admin/guest-refunds');}
    public function adminMarkPaid(string $id): never{Csrf::ensure();$user=$this->requireRole('admin');$transaction=$this->text('transaction_no',100);$note=$this->text('note',500);try{if(mb_strlen($transaction)<3){throw new \DomainException('Vui lòng nhập mã giao dịch ngân hàng.');}(new GuestRefund())->markPaid((int)$id,(int)$user['id'],$transaction,$note);Session::flash('success','Đã ghi nhận chuyển khoản hoàn tiền cho Guest.');}catch(\Throwable $e){Session::flash('error',$e instanceof \DomainException?$e->getMessage():self::GENERIC_ERROR);}$this->redirect('/admin/guest-refunds');}
    private function text(string $field,int $limit): string{return mb_substr(trim(input_text($_POST,$field)),0,$limit+1);}
}
