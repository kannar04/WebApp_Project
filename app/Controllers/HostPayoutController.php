<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Models\HostPayout;
use Core\Controller;
use Core\Csrf;
use Core\Session;

final class HostPayoutController extends Controller
{
    private const GENERIC_ERROR='Không thể hoàn tất thao tác. Vui lòng thử lại.';

    public function hostIndex(): void
    {
        $user=$this->requireRole('host');$model=new HostPayout();$id=(int)$user['id'];
        $this->render('host/payouts',['title'=>'Tài khoản nhận tiền','account'=>$model->account($id),'summary'=>$model->hostSummary($id),
            'payouts'=>$model->hostPayouts($id),'feePercent'=>HostPayout::feePercent(),'old'=>Session::pullOld('host-payout-account')]);
    }

    public function hostSaveAccount(): never
    {
        Csrf::ensure();$user=$this->requireRole('host');
        $input=['bank_id'=>strtoupper($this->text('bank_id',20)),'bank_name'=>$this->text('bank_name',100),
            'account_no'=>preg_replace('/\s+/u','',$this->text('account_no',30))??'', 'account_name'=>mb_strtoupper($this->text('account_name',150),'UTF-8')];
        try{
            if(!preg_match('/^[A-Z0-9]{2,20}$/D',$input['bank_id'])){throw new \DomainException('Mã ngân hàng VietQR phải gồm 2–20 chữ cái hoặc số.');}
            if(mb_strlen($input['bank_name'])<2){throw new \DomainException('Vui lòng nhập tên ngân hàng.');}
            if(!preg_match('/^[0-9]{4,19}$/D',$input['account_no'])){throw new \DomainException('Số tài khoản ngân hàng phải gồm 4–19 chữ số.');}
            if(mb_strlen($input['account_name'])<2){throw new \DomainException('Vui lòng nhập tên chủ tài khoản.');}
            (new HostPayout())->saveAccount((int)$user['id'],$input);
            Session::flash('success','Đã gửi tài khoản nhận tiền để Admin duyệt. Mọi thay đổi đều cần duyệt lại.');
        }catch(\Throwable $exception){Session::old($input,'host-payout-account');Session::flash('error',$exception instanceof \DomainException?$exception->getMessage():self::GENERIC_ERROR);}
        $this->redirect('/host/payouts');
    }

    public function adminIndex(): void
    {
        $user=$this->requireRole('admin');$status=is_string($_GET['status']??null)&&in_array($_GET['status'],['pending','approved','rejected'],true)?$_GET['status']:'';
        $model=new HostPayout();$payouts=$model->adminPayouts((int)$user['id']);
        foreach($payouts as &$payout){
            $payout['qr_url']='';
            if($payout['row_type']==='payout'&&$payout['payout_status']==='pending'){
                $bank=['bank_id'=>$payout['bank_id'],'account_no'=>$payout['account_no'],'account_name'=>$payout['account_name']];
                if(\App\Services\VietQr::enabled($bank)){$payout['qr_url']=\App\Services\VietQr::imageUrl($bank,(float)$payout['net_amount'],(string)$payout['reference']);}
            }
        }unset($payout);
        $this->render('admin/host-payouts',['title'=>'Chi trả Host','accounts'=>$model->adminAccounts((int)$user['id'],$status),'payouts'=>$payouts,
            'totals'=>$model->adminTotals((int)$user['id']),'status'=>$status,'feePercent'=>HostPayout::feePercent()]);
    }

    public function adminDecideAccount(string $id): never
    {
        Csrf::ensure();$user=$this->requireRole('admin');$decision=$this->text('decision',12);$reason=$this->text('reason',500);
        try{if(!in_array($decision,['approved','rejected'],true)){throw new \DomainException('Quyết định không hợp lệ.');}
            (new HostPayout())->decideAccount((int)$id,(int)$user['id'],$decision,$reason);Session::flash('success',$decision==='approved'?'Đã duyệt tài khoản nhận tiền của Host.':'Đã từ chối tài khoản nhận tiền.');
        }catch(\Throwable $exception){Session::flash('error',$exception instanceof \DomainException?$exception->getMessage():self::GENERIC_ERROR);}
        $this->redirect('/admin/host-payouts');
    }

    public function adminSaveSettings(): never
    {
        Csrf::ensure();$user=$this->requireRole('admin');$feePercent=$this->text('fee_percent',6);
        try{if(!preg_match('/^(?:100(?:\.0{1,2})?|\d{1,2}(?:\.\d{1,2})?)$/D',$feePercent)){throw new \DomainException('Phí nền tảng phải từ 0 đến 100%, tối đa hai số thập phân.');}
            (new HostPayout())->saveGlobalFee((int)$user['id'],$feePercent);Session::flash('success','Đã cập nhật phí nền tảng chung. Các lệnh đã tạo không thay đổi.');}
        catch(\Throwable $exception){Session::flash('error',$exception instanceof \DomainException?$exception->getMessage():self::GENERIC_ERROR);}
        $this->redirect('/admin/host-payouts');
    }

    public function adminCreate(string $bookingId): never
    {
        Csrf::ensure();$user=$this->requireRole('admin');
        try{$payout=(new HostPayout())->createPayout((int)$bookingId,(int)$user['id']);Session::flash('success','Đã tạo lệnh chi trả '.number_format((float)($payout['net_amount']??0),0,',','.').'đ cho booking #'.(int)$bookingId.'.');}
        catch(\Throwable $exception){Session::flash('error',$exception instanceof \DomainException?$exception->getMessage():self::GENERIC_ERROR);}
        $this->redirect('/admin/host-payouts');
    }

    public function adminMarkPaid(string $id): never
    {
        Csrf::ensure();$user=$this->requireRole('admin');$transactionNo=$this->text('transaction_no',100);$note=$this->text('note',500);
        try{if(mb_strlen($transactionNo)<3){throw new \DomainException('Vui lòng nhập mã giao dịch ngân hàng.');}
            (new HostPayout())->markPaid((int)$id,(int)$user['id'],$transactionNo,$note);Session::flash('success','Đã ghi nhận chuyển tiền cho Host.');}
        catch(\Throwable $exception){Session::flash('error',$exception instanceof \DomainException?$exception->getMessage():self::GENERIC_ERROR);}
        $this->redirect('/admin/host-payouts');
    }

    private function text(string $field,int $limit): string{return mb_substr(trim(input_text($_POST,$field)),0,$limit+1);}
}
