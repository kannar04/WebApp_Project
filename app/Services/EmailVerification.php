<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\ContactVerification;

final class EmailVerification
{
    public function resend(): void
    {
        $challenge=$_SESSION['_email_challenge']??null;
        $actor=\Core\Auth::id();
        if (!$challenge || $challenge['actor']!==$actor) { throw new \DomainException('Phiên xác minh đã thay đổi. Vui lòng yêu cầu mã mới.'); }
        $data=(new ContactVerification())->challenge($challenge['id'],$actor);
        $this->request($data,$actor);
    }
    public function request(array $data, ?int $user=null): void
    {
        $mail=new MailService();
        if (!$mail->configured()) { throw new \DomainException('Chưa cấu hình gửi email xác minh. Vui lòng liên hệ quản trị viên.'); }
        if (time()-(int)($_SESSION['_email_request_at']??0)<60) { throw new \DomainException('Vui lòng chờ 60 giây trước khi gửi lại mã.'); }
        $id=bin2hex(random_bytes(32)); $code=(string)random_int(100000,999999);
        $data['email']=mb_strtolower(trim($data['email']));
        (new ContactVerification())->issue($id,$user,$data,hash('sha256',$id.$code));
        $_SESSION['_email_request_at']=time();
        $_SESSION['_email_challenge']=['id'=>$id,'email'=>$data['email'],'actor'=>$user];
        if (!$mail->send($data['email'],'Mã xác minh Home2Home',$code." là mã xác minh email Home2Home của bạn. Mã có hiệu lực 10 phút. Không chia sẻ mã với người khác.")) {
            throw new \DomainException('Chưa gửi được email. Hãy kiểm tra địa chỉ và đăng ký lại sau 60 giây.');
        }
    }
    public function confirm(string $code): int
    {
        $challenge=$_SESSION['_email_challenge']??null;
        if (!$challenge || !preg_match('/^[0-9]{6}$/D',$code)) { throw new \DomainException('Nhập mã gồm 6 chữ số đã gửi qua email.'); }
        $actor=\Core\Auth::id();
        if ($actor!==$challenge['actor']) { throw new \DomainException('Phiên xác minh đã thay đổi. Vui lòng yêu cầu mã mới.'); }
        $id=(new ContactVerification())->consume($challenge['id'],hash('sha256',$challenge['id'].$code),$actor);
        if ($id<1) { throw new \DomainException('Mã không đúng, đã hết hạn hoặc đã nhập sai 5 lần. Hãy yêu cầu mã mới.'); }
        unset($_SESSION['_email_challenge']);
        return $id;
    }
}
