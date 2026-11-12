<?php
declare(strict_types=1);
namespace App\Services;

/** Twilio Verify owns OTP generation, expiry and attempt limits; OTPs never enter local storage. */
final class SmsVerification
{
    public static function normalize(string $phone): ?string
    {
        $phone=preg_replace('/[\s().-]+/','',trim($phone));
        if (preg_match('/^0[0-9]{9}$/D',$phone)) { $phone='+84'.substr($phone,1); }
        return preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone) ? $phone : null;
    }
    public function start(string $phone): void
    {
        $result=$this->request('Verifications',['To'=>$phone,'Channel'=>'sms']);
        if (($result['status']??'')!=='pending') { throw new \DomainException('Chưa gửi được mã SMS. Vui lòng thử lại sau.'); }
    }
    public function check(string $phone,string $code): bool
    {
        if (!preg_match('/^[0-9]{4,10}$/D',$code)) { return false; }
        return ($this->request('VerificationCheck',['To'=>$phone,'Code'=>$code])['status']??'')==='approved';
    }
    private function request(string $resource,array $fields): array
    {
        $account=trim((string)getenv('TWILIO_ACCOUNT_SID')); $token=trim((string)getenv('TWILIO_AUTH_TOKEN')); $service=trim((string)getenv('TWILIO_VERIFY_SERVICE_SID'));
        if (!preg_match('/^AC[a-f0-9]{32}$/iD',$account) || $token==='' || !preg_match('/^VA[a-f0-9]{32}$/iD',$service)) {
            throw new \DomainException('Dịch vụ SMS chưa được cấu hình. Vui lòng liên hệ quản trị viên.');
        }
        if (!function_exists('curl_init')) {
            throw new \DomainException('Dịch vụ SMS chưa khả dụng. Vui lòng liên hệ quản trị viên.');
        }
        $request=curl_init('https://verify.twilio.com/v2/Services/'.$service.'/'.$resource);
        if ($request === false) {
            throw new \DomainException('Không thể kết nối dịch vụ SMS. Vui lòng thử lại sau.');
        }
        curl_setopt_array($request,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_USERPWD=>$account.':'.$token,
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10]);
        if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) { curl_setopt($request,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA); }
        $body=curl_exec($request); $status=curl_getinfo($request,CURLINFO_RESPONSE_CODE); curl_close($request);
        if ($status===404 && $resource==='VerificationCheck') { return ['status'=>'expired']; }
        if ($status<200 || $status>=300 || !is_string($body)) { throw new \DomainException('Không thể xác minh SMS lúc này. Hãy kiểm tra số điện thoại và thử lại sau.'); }
        $result = json_decode($body, true);
        if (!is_array($result) || !is_string($result['status'] ?? null)) {
            throw new \DomainException('Phản hồi dịch vụ SMS không hợp lệ. Vui lòng thử lại sau.');
        }
        return $result;
    }
}
