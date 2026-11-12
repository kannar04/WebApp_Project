<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Models\ContactVerification;
use App\Services\EmailVerification;
use App\Services\SmsVerification;
use Core\Auth;
use Core\Csrf;
use Core\Session;

final class ContactVerificationController extends \Core\Controller
{
    public function emailResend(): never
    {
        Csrf::ensure('/verify-email');
        try { (new EmailVerification())->resend(); Session::flash('success','Đã gửi lại mã xác minh email.'); }
        catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/verify-email');
    }
    public function emailForm(): void
    {
        header('Cache-Control: no-store');
        $this->render('auth/verify-email',['title'=>'Xác minh email','challenge'=>$_SESSION['_email_challenge']??null], 'layouts/auth');
    }
    public function emailConfirm(): never
    {
        Csrf::ensure('/verify-email');
        try {
            $id=(new EmailVerification())->confirm(input_text($_POST,'code'));
            if (!Auth::check() && !Auth::signIn($id)) { throw new \DomainException('Không thể đăng nhập. Vui lòng đăng nhập lại.'); }
            Session::flash('success','Email đã được xác minh. Hãy xác minh số điện thoại trong hồ sơ trước khi đặt phòng.');
            $this->redirect('/profile#contact-verification');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/verify-email');
    }
    public function emailRequest(): never
    {
        Csrf::ensure(); $user=$this->requireAuth();
        try { (new EmailVerification())->request($user,(int)$user['id']); $this->redirect('/verify-email'); }
        catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/profile#contact-verification');
    }
    public function phoneRequest(): never
    {
        Csrf::ensure(); $user=$this->requireAuth();
        try {
            $state=(new ContactVerification())->status((int)$user['id']);
            if (empty($state['email_verified'])) { throw new \DomainException('Hãy xác minh email trước khi nhận mã SMS.'); }
            $phone=SmsVerification::normalize((string)$user['phone']);
            if ($phone===null) { throw new \DomainException('Hãy lưu số điện thoại hợp lệ trong hồ sơ trước khi nhận mã SMS.'); }
            if (time()-(int)($_SESSION['_sms_sent_at']??0)<60) { throw new \DomainException('Vui lòng chờ 60 giây trước khi gửi lại SMS.'); }
            $_SESSION['_sms_sent_at']=time();
            (new SmsVerification())->start($phone);
            $_SESSION['_sms_pending']=['user'=>(int)$user['id'],'phone'=>$user['phone'],'to'=>$phone,'expires'=>time()+600,'attempts'=>0];
            Session::flash('success','Đã gửi mã SMS. Nhập mã trong vòng 10 phút.');
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/profile#contact-verification');
    }
    public function phoneConfirm(): never
    {
        Csrf::ensure(); $user=$this->requireAuth();
        try {
            $pending=$_SESSION['_sms_pending']??null;
            if (!$pending || $pending['user']!==(int)$user['id'] || $pending['phone']!==$user['phone'] || $pending['expires']<time() || $pending['attempts']>=5) {
                throw new \DomainException('Yêu cầu SMS đã hết hạn hoặc số điện thoại đã đổi. Hãy nhận mã mới.');
            }
            $_SESSION['_sms_pending']['attempts']++;
            if (!(new SmsVerification())->check($pending['to'],input_text($_POST,'code'))) { throw new \DomainException('Mã SMS không đúng hoặc đã hết hạn.'); }
            (new ContactVerification())->phoneVerified((int)$user['id'],$pending['phone']);
            unset($_SESSION['_sms_pending']);
            Session::flash('success','Số điện thoại đã được xác minh. Bạn có thể đặt phòng.');
            $return=(string)Session::pull('_verification_return','');
            if (preg_match('#^/listings/[1-9][0-9]*/review(?:\?|$)#',$return) && \Core\I18n::safeReturnPath($return)===$return) { $this->redirect($return); }
        } catch (\DomainException $exception) { Session::flash('error',$exception->getMessage()); }
        $this->redirect('/profile#contact-verification');
    }
}
