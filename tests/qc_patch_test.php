<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
require dirname(__DIR__).'/core/bootstrap.php';
use App\Models\BookingMessage;
use App\Services\SmsVerification;
use App\Services\UploadService;
function qcCheck(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
qcCheck(SmsVerification::normalize('090 123 4567')==='+84901234567','Vietnamese local numbers must normalize to E.164.');
qcCheck(SmsVerification::normalize('+84 (90) 123-4567')==='+84901234567','International formatting must normalize.');
foreach (['','abc','+00012345678','090123','+841234567890123456'] as $invalid) {
    qcCheck(SmsVerification::normalize($invalid)===null,'Malformed number accepted.');
}
qcCheck(BookingMessage::normalize("\r\nXin chào\r\n")==='Xin chào','Messages must preserve UTF-8 and normalize newlines.');
qcCheck(BookingMessage::normalize("\u{200B}\u{00A0}")===null,'Invisible messages must be rejected.');
qcCheck(BookingMessage::normalize(str_repeat('a',2001))===null,'Oversized message accepted.');
qcCheck((new UploadService())->images([],'qc')===[],'An optional image field must remain optional.');
foreach ([['error'=>array_fill(0,21,UPLOAD_ERR_NO_FILE)],['error'=>[0],'tmp_name'=>[['bad']],'name'=>['x'],'size'=>[1]]] as $invalid) {
    try { (new UploadService())->images($invalid,'qc');throw new RuntimeException('Malformed upload accepted.'); }
    catch (DomainException $expected) {}
}
// Rendering untrusted conversation text must escape HTML, including the first server-rendered paint.
Core\Session::start();
$booking=['id'=>42];
$conversation=['state'=>['other_name'=>'<script>bad()</script>','my_role'=>'guest','can_send'=>1,'status'=>'pending'],
    'messages'=>[['is_mine'=>0,'sender_name'=>'<img onerror=bad()>','created_at'=>'2026-11-09 10:00:00','is_hidden'=>0,'body'=>'<script>bad()</script>','read_at'=>null]],'draft'=>'</textarea><script>bad()</script>'];
ob_start();require dirname(__DIR__).'/app/Views/messages/thread.php';$html=(string)ob_get_clean();
qcCheck(!str_contains($html,'<script>bad()'),'Conversation contains executable untrusted markup.');
qcCheck(str_contains($html,'&lt;script&gt;bad()'),'Escaped message text is missing.');
qcCheck(str_contains($html,'data-updates-url=') && str_contains($html,'data-message-form'),'AJAX hooks missing.');
qcCheck(!str_contains($html,'Đã gửi tin nhắn'),'Unnecessary sent banner reintroduced.');
Core\I18n::setLocale('en');
qcCheck(Core\I18n::translate('Chờ chủ nhà xác nhận')==='Awaiting host confirmation','Booking state translation missing.');
qcCheck((Core\I18n::scriptDictionary()['Tìm theo địa chỉ']??'')==='Find by address','Client translation missing.');
echo "QC core checks passed. Database and provider integration require configured services.\n";
