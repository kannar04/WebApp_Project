<?php
declare(strict_types=1);
namespace App\Services;
use App\Models\User;
use App\Models\PasswordReset;

final class PasswordRecoveryService
{
    public function configured(): bool
    {
        $mail=require dirname(__DIR__,2).'/config/mail.php';
        $parts=parse_url($mail['reset_base_url']);
        return $mail['enabled'] && filter_var($mail['from'],FILTER_VALIDATE_EMAIL)!==false && $parts!==false
            && in_array($parts['scheme']??'',['http','https'],true) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']) && !empty($parts['host'])
            && (($parts['scheme']??'')==='https' || in_array($parts['host'],['localhost','127.0.0.1','::1'],true));
    }

    public function request(string $email): void
    {
        if (!$this->configured()) { throw new \DomainException('Khôi phục mật khẩu đang chờ cấu hình gửi email. Vui lòng liên hệ quản trị viên.'); }
        $user=(new User())->findByEmail($email);
        if (!$user || $user['status']!=='active' || $user['deleted_at']!==null) { return; }
        $rawToken=bin2hex(random_bytes(32));
        try { (new PasswordReset())->issue((int)$user['id'],$rawToken); }
        catch (\DomainException $exception) { return; } // Generic response avoids account/throttle enumeration.
        $config=require dirname(__DIR__,2).'/config/mail.php';
        $link=$config['reset_base_url'].'/reset-password?token='.$rawToken;
        // No raw token is logged, returned to the browser or written into a public file.
        $sent=@mail($user['email'],'Home2Home password recovery',"Open this one-use link within 45 minutes:\n".$link,
            ['From'=>$config['from'],'Content-Type'=>'text/plain; charset=UTF-8']);
        if (!$sent) { error_log('Home2Home recovery transport failed; check private mail configuration.'); }
    }
}
