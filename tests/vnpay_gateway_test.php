<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/core/bootstrap.php';
use App\Services\VnpayGateway as Gateway;
function vnpCheck(bool $ok,string $message):void {if(!$ok){throw new RuntimeException($message);}}
$config=['vnpay_tmn_code'=>'TEST1234','vnpay_hash_secret'=>'fixture-secret-not-a-real-merchant','vnpay_pay_url'=>'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'];
$attempt=['amount'=>'10000.25','reference'=>'H2H42XABCDE','booking_id'=>42];
$url=Gateway::paymentUrl($config,$attempt,'http://127.0.0.1:8090/payments/vnpay/return','127.0.0.1','vi',new DateTimeImmutable('2026-11-10 00:00:00',new DateTimeZone('UTC')));
parse_str(parse_url($url,PHP_URL_QUERY),$fields);
vnpCheck($fields['vnp_Version']==='2.1.0'&&!isset($fields['vnp_SecureHashType']),'API 2.1.0 must omit the legacy hash-type parameter.');
vnpCheck($fields['vnp_Amount']==='1000025','Amount must be scaled exactly without premature rounding.');
vnpCheck($fields['vnp_CreateDate']==='20261110070000'&&$fields['vnp_ExpireDate']==='20261110071500','Timestamps must use GMT+7 and a 15-minute validity window.');
vnpCheck($fields['vnp_Locale']==='vn'&&!isset($fields['vnp_BankCode']),'Let VNPay select the bank; Vietnamese locale is vn.');
vnpCheck(Gateway::signedData(['vnp_TxnRef'=>'42','vnp_OrderInfo'=>'Thanh toan: 42'])==='vnp_OrderInfo=Thanh+toan%3A+42&vnp_TxnRef=42','Signing must sort keys and use PHP urlencode as in the official example.');
vnpCheck(Gateway::verify($config,$fields),'Generated request signature must validate.');
$tampered=$fields;$tampered['vnp_Amount']='100';vnpCheck(!Gateway::verify($config,$tampered),'Modified amount must fail verification.');
$tampered=$fields;$tampered['vnp_TmnCode']='OTHER123';vnpCheck(!Gateway::verify($config,$tampered),'Another merchant must fail verification.');
$tampered=$fields;$tampered['vnp_Amount']=[];vnpCheck(!Gateway::verify($config,$tampered),'Array-shaped fields must fail verification.');
foreach(['0','-1','1.234','10000000000','1e4',INF] as $amount){try{Gateway::gatewayAmount($amount);throw new RuntimeException('Invalid amount accepted.');}catch(DomainException $expected){}}
vnpCheck(Gateway::gatewayAmount('9999999999.99')==='999999999999','Upper documented amount boundary must work.');
vnpCheck(Gateway::returnedAmount('1000025')==='10000.25','Callback amount must retain its exact value.');
foreach(['0','-100','100.00',str_repeat('9',13),['100']] as $amount){vnpCheck(Gateway::returnedAmount($amount)===null,'Malformed callback amount accepted.');}
vnpCheck(!Gateway::enabled(array_replace($config,['vnpay_tmn_code'=>'wrong'])),'Terminal ID must be eight alphanumeric characters.');
vnpCheck(!Gateway::enabled(array_replace($config,['vnpay_pay_url'=>'http://sandbox.vnpayment.vn/paymentv2/vpcpay.html'])),'Gateway must use HTTPS.');
vnpCheck(!Gateway::enabled(array_replace($config,['vnpay_pay_url'=>$config['vnpay_pay_url'].'?old=1'])),'Gateway URL must not contain an old query string.');
// Protect display-only ReturnURL against accidentally reintroducing a database update.
$controller=file_get_contents(dirname(__DIR__).'/app/Controllers/PaymentController.php');
$return=substr($controller,strpos($controller,'public function vnpayReturn()'),strpos($controller,'public function vnpayIpn()')-strpos($controller,'public function vnpayReturn()'));
vnpCheck(!str_contains($return,'recordVnpay(')&&!str_contains($return,'vnpayResult('),'ReturnURL must not record payment status.');
echo "VNPay gateway checks passed: 2.1.0 fields, amount boundaries, timestamps, encoding, signatures, tampering and display-only return.\n";
