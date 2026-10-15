<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\Listing;
use App\Models\Payment;
use App\Models\User;
use App\Services\BookingService;
use App\Services\VietQr;
use App\Services\VnpayGateway;
use Core\ProcedureConnection;

/*
 * Online payment: VietQR bank transfer (Guest reports it, Admin confirms or rejects) and VNPay (signed return URL and IPN).
 * Works on the workspace dev database directly and inside fresh_setup_test.php, which gives the server a fake receiving
 * account and a per-run VNPay secret. Fixture dates are today+4300..4330 days. Everything created here is removed in `finally`.
 */

function payExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function payCall(string $call, array $parameters=[]): array
{
    $statement=ProcedureConnection::connection()->prepare($call);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/** Runs $operation(...$arguments) and requires a business rejection whose message contains $needle. */
function payRejects(callable $operation, array $arguments, string $needle, string $label): void
{
    try { $operation(...$arguments); }
    catch (DomainException $exception) {
        if (!str_contains($exception->getMessage(),$needle)) {
            throw new RuntimeException($label.': unexpected message "'.$exception->getMessage().'"');
        }
        return;
    }
    throw new RuntimeException($label.': the operation was accepted.');
}

function payInspect(int $bookingId): array
{
    $rows=payCall('CALL sp_test_payment_inspect(?)',[$bookingId]);
    payExpect(count($rows)===1,'Booking fixture #'.$bookingId.' is missing.');
    return $rows[0];
}

function payAttempt(int $transactionId): array
{
    $rows=payCall('CALL sp_test_payment_attempt(?)',[$transactionId]);
    payExpect(count($rows)===1,'Payment attempt #'.$transactionId.' is missing.');
    return $rows[0];
}

/** A confirmed booking of the fixture listing ($offset nights from the base date, two nights). */
function payConfirmedBooking(BookingService $service, int $listingId, int $guestId, int $hostId, DateTimeImmutable $base, int $offset): int
{
    $bookingId=$service->create($listingId,$guestId,$base->modify('+'.$offset.' days')->format('Y-m-d'),$base->modify('+'.($offset+2).' days')->format('Y-m-d'),1);
    $service->hostTransition($bookingId,$hostId,'confirmed');
    return $bookingId;
}

/** The vnp_* fields VNPay sends back for an attempt, signed with $secret (a wrong secret forges the result). */
function payVnpayResult(array $attempt, string $tmnCode, string $secret, string $responseCode, string $amountVnd, string $transactionNo): array
{
    $fields=['vnp_Amount'=>$amountVnd.'00','vnp_BankCode'=>'NCB','vnp_BankTranNo'=>'VNP'.$transactionNo,'vnp_CardType'=>'ATM',
        'vnp_OrderInfo'=>'Thanh toan booking '.$attempt['booking_id'],'vnp_PayDate'=>date('YmdHis'),'vnp_ResponseCode'=>$responseCode,
        'vnp_TmnCode'=>$tmnCode,'vnp_TransactionNo'=>$transactionNo,'vnp_TransactionStatus'=>$responseCode==='00'?'00':'02','vnp_TxnRef'=>$attempt['reference']];
    $fields['vnp_SecureHash']=hash_hmac('sha512',VnpayGateway::signedData($fields),$secret);
    return $fields;
}

function payHttp(CurlHandle $curl, string $baseUrl, string $path, ?array $fields=null): array
{
    curl_setopt_array($curl,[CURLOPT_URL=>$baseUrl.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>60]);
    if ($fields===null) { curl_setopt($curl,CURLOPT_HTTPGET,true); }
    else { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($fields)); }
    $body=curl_exec($curl);
    if ($body===false) { throw new RuntimeException('HTTP request failed: '.$path.' '.curl_error($curl)); }
    $location=(string)(curl_getinfo($curl,CURLINFO_REDIRECT_URL)?:'');
    return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>(string)$body,'redirect'=>$location,
        'location'=>(string)(parse_url($location,PHP_URL_PATH)??'')];
}

function payClient(): CurlHandle
{
    $curl=curl_init();
    curl_setopt($curl,CURLOPT_COOKIEFILE,'');
    return $curl;
}

function payToken(CurlHandle $curl, string $baseUrl, string $path): string
{
    $page=payHttp($curl,$baseUrl,$path);
    payExpect($page['status']===200 && (bool)preg_match('/name="csrf-token" content="([^"]+)"/',$page['body'],$match),'CSRF token missing on '.$path);
    return $match[1];
}

function payLogin(CurlHandle $curl, string $baseUrl, string $email, string $password): void
{
    $page=payHttp($curl,$baseUrl,'/login');
    payExpect((bool)preg_match('/name="_token" value="([^"]+)"/',$page['body'],$match),'Login form token missing.');
    $result=payHttp($curl,$baseUrl,'/login',['_token'=>$match[1],'email'=>$email,'password'=>$password]);
    payExpect($result['status']===302 && $result['location']!=='/login','HTTP login failed for '.$email);
}

$app=require dirname(__DIR__).'/config/app.php';
$config=require dirname(__DIR__).'/config/database.php';
payExpect(PHP_SAPI==='cli' && $app['env']==='local' && in_array($config['host'],['localhost','127.0.0.1','::1'],true),'Local test only.');
payExpect($config['database']!=='db_home2home','Refusing to run against the shared demo database.');
$paymentConfig=require dirname(__DIR__).'/config/payment.php';

$users=new User();
$host=$users->findByEmail('host@home2home.test'); $guest=$users->findByEmail('guest@home2home.test'); $admin=$users->findByEmail('admin@home2home.test');
payExpect($host && $guest && $admin,'Local seed accounts required.');
$hostId=(int)$host['id']; $guestId=(int)$guest['id']; $adminId=(int)$admin['id'];
$listings=new Listing(); $payments=new Payment(); $service=new BookingService();
$listingId=null; $completed=false;
$base=new DateTimeImmutable('today +4300 days');

try {
    // ---- Fixture listing: 100.000đ/night + 20.000đ fee, so two nights cost 220.000đ.
    $data=['property_type_id'=>(int)$listings->types()[0]['id'],'cancellation_policy_id'=>(int)$listings->policies()[0]['id'],
        'title'=>'Audit fixture PAY '.bin2hex(random_bytes(6)),'address'=>'Test only','city'=>'Audit','description'=>'Online payment fixture',
        'nightly_price'=>100000,'cleaning_fee'=>20000,'max_guests'=>2,'room_count'=>1,'bedroom_count'=>1,'bed_count'=>1,'check_in_time'=>'14:00','check_out_time'=>'11:00'];
    $listingId=$listings->create($hostId,$data);
    payCall('CALL sp_test_listing_approve(?)',[$listingId]);

    // ---- Who may pay, and when.
    $bookingA=$service->create($listingId,$guestId,$base->format('Y-m-d'),$base->modify('+2 days')->format('Y-m-d'),1);
    $checkout=$payments->checkout($bookingA,$guestId);
    payExpect($checkout!==null && (int)$checkout['can_pay_online']===0 && $checkout['booking_status']==='pending','A pending booking was offered online payment.');
    payRejects([$payments,'start'],[$bookingA,$guestId,'vietqr'],'đã được xác nhận','Pending booking started a payment');
    payRejects([$payments,'checkout'],[$bookingA,$hostId],'Không có quyền','Host opened the Guest payment page');
    $service->hostTransition($bookingA,$hostId,'confirmed');
    $checkout=$payments->checkout($bookingA,$guestId);
    payExpect((int)$checkout['can_pay_online']===1 && (float)$checkout['amount_due']===220000.0 && $checkout['transfer_id']===null,'Confirmed booking checkout is wrong: '.json_encode($checkout));
    payRejects([$payments,'start'],[$bookingA,$guestId,'momo'],'không hợp lệ','Unknown payment provider accepted');
    payRejects([$payments,'start'],[$bookingA,$hostId,'vietqr'],'Không có quyền','Host started a payment for the Guest');
    echo "PASS payment access: only the Guest, only once the booking is confirmed, only VietQR/VNPay\n";

    // ---- VietQR: reuse, report, reject with reason, pay again, Admin confirms.
    $first=$payments->start($bookingA,$guestId,'vietqr');
    payExpect($first['provider']==='vietqr' && $first['status']==='pending' && (float)$first['amount']===220000.0
        && (bool)preg_match('/^H2H'.$bookingA.'X[0-9A-F]{5}$/D',$first['reference']),'VietQR attempt is wrong: '.json_encode($first));
    payExpect((int)$payments->start($bookingA,$guestId,'vietqr')['transaction_id']===(int)$first['transaction_id'],'Reloading the QR page created a second transfer code.');
    $firstId=(int)$first['transaction_id'];
    payExpect($payments->attempt($firstId,$adminId)!==null && (int)$payments->attempt($firstId,$guestId)['guest_id']===$guestId,'Guest/Admin could not open the transfer.');
    payRejects([$payments,'attempt'],[$firstId,$hostId],'Không tìm thấy','Host opened the Guest transfer');
    payRejects([$payments,'submitTransfer'],[$firstId,$hostId],'Không tìm thấy','Host reported the Guest transfer');
    $payments->submitTransfer($firstId,$guestId);
    $state=payInspect($bookingA);
    payExpect($state['attempt_states']==='vietqr:submitted' && (int)$state['admin_submitted_notices']>=1 && $state['payment_status']==='unpaid','Transfer report was not recorded: '.json_encode($state));
    payRejects([$payments,'submitTransfer'],[$firstId,$guestId],'báo chuyển khoản rồi','Transfer reported twice');
    payRejects([$payments,'start'],[$bookingA,$guestId,'vnpay'],'chờ Admin xác nhận','VNPay started while a transfer awaits the Admin');
    $queue=array_column($payments->adminTransfers($adminId),'transaction_id');
    payExpect(in_array($firstId,array_map('intval',$queue),true),'Reported transfer is missing from the Admin queue.');
    payRejects([$payments,'adminTransfers'],[$guestId],'quyền quản trị','Guest read the Admin transfer queue');
    payRejects([$payments,'decideTransfer'],[$firstId,$guestId,'confirm',''],'quyền quản trị','Guest confirmed a transfer');
    payRejects([$payments,'decideTransfer'],[$firstId,$adminId,'reject','abc'],'ít nhất 5','Rejection without a reason accepted');
    $payments->decideTransfer($firstId,$adminId,'reject','Chưa thấy tiền vào tài khoản');
    $state=payInspect($bookingA);
    payExpect($state['attempt_states']==='vietqr:failed' && (int)$state['rejected_notices']===1 && (int)$state['transfer_audits']===1 && $state['payment_status']==='unpaid',
        'Rejected transfer state is wrong: '.json_encode($state));
    payRejects([$payments,'decideTransfer'],[$firstId,$adminId,'confirm',''],'đã được xử lý','A handled transfer was decided again');
    $second=$payments->start($bookingA,$guestId,'vietqr');
    $secondId=(int)$second['transaction_id'];
    payExpect($secondId!==$firstId && $second['reference']!==$first['reference'],'Paying again after a rejection reused the closed transfer.');
    $payments->submitTransfer($secondId,$guestId);
    $payments->decideTransfer($secondId,$adminId,'confirm','');
    $state=payInspect($bookingA);
    payExpect($state['payment_status']==='paid' && $state['payment_method']==='vietqr' && (int)$state['paid_by']===$adminId && (int)$state['payment_events']===1
        && (int)$state['paid_notices']===1 && (int)$state['transfer_audits']===2 && $state['attempt_states']==='vietqr:failed,vietqr:succeeded'
        && $state['payment_note']==='VietQR '.$second['reference'],'Confirmed transfer state is wrong: '.json_encode($state));
    payExpect((int)$payments->checkout($bookingA,$guestId)['can_pay_online']===0,'A paid booking still offers online payment.');
    payRejects([$payments,'start'],[$bookingA,$guestId,'vietqr'],'đã được ghi nhận thanh toán','A paid booking started another payment');
    payExpect(count($payments->bookingAttempts($bookingA,$hostId))===2 && !in_array($secondId,array_map('intval',array_column($payments->adminTransfers($adminId),'transaction_id')),true),
        'Attempt history or Admin queue is wrong after confirmation.');
    echo "PASS VietQR transfer: one code per amount, Guest report + Admin notices, reasoned rejection, pay again, Admin confirmation, audit log\n";

    // ---- VNPay results (signature already checked by PHP): unknown, wrong amount, cancelled, success, repeat, duplicate.
    $bookingB=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,5);
    $v1=$payments->start($bookingB,$guestId,'vnpay'); $v2=$payments->start($bookingB,$guestId,'vnpay'); $v3=$payments->start($bookingB,$guestId,'vnpay');
    payExpect($v1['provider']==='vnpay' && (int)$v2['transaction_id']!==(int)$v1['transaction_id'],'Each VNPay checkout needs its own reference.');
    payExpect($payments->vnpayResult('H2H0XNOPE','220000.00','00','00','1','NCB')['result']==='not_found','Unknown reference was accepted.');
    $result=$payments->vnpayResult($v1['reference'],'1.00','00','00','1','NCB');
    payExpect($result['result']==='invalid_amount' && payAttempt((int)$v1['transaction_id'])['status']==='pending','Wrong amount changed the attempt.');
    $result=$payments->vnpayResult($v1['reference'],'220000.00','24','02','0','');
    payExpect($result['result']==='ok' && $result['transaction_status']==='failed' && $result['response_code']==='24' && (int)$result['booking_id']===$bookingB,'Cancelled VNPay result is wrong.');
    payExpect($payments->vnpayResult($v1['reference'],'220000.00','00','00','1','NCB')['result']==='already','A closed attempt was paid later.');
    $result=$payments->vnpayResult($v2['reference'],'220000.00','00','00','14000001','NCB');
    $state=payInspect($bookingB);
    payExpect($result['result']==='ok' && $result['transaction_status']==='succeeded' && $state['payment_status']==='paid' && $state['payment_method']==='vnpay'
        && (int)$state['paid_by']===$guestId && (int)$state['paid_notices']===1 && (int)$state['payment_events']===1,'Successful VNPay result is wrong: '.json_encode($state));
    $stored=payAttempt((int)$v2['transaction_id']);
    payExpect($stored['gateway_transaction_no']==='14000001' && $stored['bank_code']==='NCB' && $stored['gateway_response_code']==='00','VNPay transaction details were not stored.');
    payExpect(payAttempt((int)$v3['transaction_id'])['status']==='cancelled','Other open attempts were not closed after payment.');
    payExpect($payments->vnpayResult($v2['reference'],'220000.00','00','00','14000001','NCB')['result']==='already' && (int)payInspect($bookingB)['payment_events']===1,
        'Repeating the VNPay result recorded the payment twice.');
    $result=$payments->vnpayResult($v3['reference'],'220000.00','00','00','14000002','NCB');
    $state=payInspect($bookingB);
    payExpect($result['result']==='ok' && payAttempt((int)$v3['transaction_id'])['status']==='succeeded' && (int)$state['admin_duplicate_notices']>=1
        && (int)$state['payment_events']===1 && (int)$state['paid_notices']===1,'Duplicate VNPay payment was not flagged for a refund: '.json_encode($state));
    $bookingC=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,10);
    $c1=$payments->start($bookingC,$guestId,'vnpay');
    $service->cancelByGuest($bookingC,$guestId,'Audit fixture cancel while paying');
    $payments->vnpayResult($c1['reference'],'220000.00','00','00','14000003','NCB');
    $state=payInspect($bookingC);
    payExpect($state['booking_status']==='cancelled' && $state['payment_status']==='paid' && (int)$state['admin_refund_notices']>=1,'Money received after cancelling was not flagged for a refund: '.json_encode($state));
    payRejects([$payments,'start'],[$bookingC,$guestId,'vnpay'],'đã được ghi nhận thanh toán','A cancelled booking started a payment');
    echo "PASS VNPay results: unknown/amount guards, cancelled, success once, repeat-safe, duplicate and after-cancel payments flagged for refund\n";

    // ---- Signing and QR links (pure PHP).
    $gateway=VnpayGateway::enabled($paymentConfig) ? $paymentConfig
        : ['vnpay_tmn_code'=>'H2HTEST1','vnpay_hash_secret'=>bin2hex(random_bytes(16)),'vnpay_pay_url'=>'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'];
    $url=VnpayGateway::paymentUrl($gateway,['amount'=>'220000.00','reference'=>'H2H1XABCDE','booking_id'=>1],'http://127.0.0.1/payments/vnpay/return','127.0.0.1','en',
        new DateTimeImmutable('2026-10-15 05:00:00',new DateTimeZone('UTC')));
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    payExpect(str_starts_with($url,$gateway['vnpay_pay_url'].'?') && $query['vnp_Amount']==='22000000' && $query['vnp_CreateDate']==='20261015120000'
        && $query['vnp_ExpireDate']==='20261015121500' && $query['vnp_Locale']==='en' && $query['vnp_TxnRef']==='H2H1XABCDE' && strlen($query['vnp_SecureHash'])===128,
        'VNPay payment URL is wrong: '.$url);
    $signed=payVnpayResult(['booking_id'=>1,'reference'=>'H2H1XABCDE'],$gateway['vnpay_tmn_code'],$gateway['vnpay_hash_secret'],'00','220000','1');
    payExpect(VnpayGateway::verify($gateway,$signed),'A correctly signed VNPay result was rejected.');
    payExpect(!VnpayGateway::verify($gateway,array_merge($signed,['vnp_Amount'=>'100'])),'A tampered amount passed the signature check.');
    payExpect(!VnpayGateway::verify($gateway,payVnpayResult(['booking_id'=>1,'reference'=>'H2H1XABCDE'],$gateway['vnpay_tmn_code'],'wrong-secret','00','220000','1')),'A forged signature passed.');
    payExpect(!VnpayGateway::verify(array_merge($gateway,['vnpay_tmn_code'=>'OTHER']),$signed) && !VnpayGateway::verify($gateway,array_merge($signed,['vnp_TxnRef'=>['x']])),
        'Another merchant or a non-string field passed the signature check.');
    $bank=['bank_id'=>'970436','account_no'=>'0001234567','account_name'=>'HOME2HOME TEST'];
    payExpect(VietQr::enabled($bank) && !VietQr::enabled(['bank_id'=>'97 0436','account_no'=>'0001234567']) && !VietQr::enabled(['bank_id'=>'970436','account_no'=>'']),'VietQR configuration check is wrong.');
    payExpect(VietQr::imageUrl($bank,220000.0,'H2H1XABCDE')==='https://img.vietqr.io/image/970436-0001234567-compact2.png?amount=220000&addInfo=H2H1XABCDE&accountName=HOME2HOME%20TEST',
        'VietQR image link is wrong.');
    echo "PASS VNPay signing (GMT+7 dates, ×100 amount, HMAC-SHA512, tamper/forgery/merchant guards) and VietQR links\n";

    // ---- HTTP: pages, CSRF, roles, Admin decision, VNPay redirect, forged/valid return and IPN.
    $baseUrl=rtrim((string)(getenv('HOME2HOME_TEST_URL')?:($app['url']?:'')),'/');
    $reachable=false;
    if ($baseUrl!=='') {
        $probe=curl_init($baseUrl.'/');
        curl_setopt_array($probe,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>3]);
        $reachable=curl_exec($probe)!==false && (int)curl_getinfo($probe,CURLINFO_RESPONSE_CODE)===200;
        curl_close($probe);
    }
    if (!$reachable || !VietQr::enabled($paymentConfig) || !VnpayGateway::enabled($paymentConfig)) {
        echo "SKIP online payment HTTP checks: needs a server and the bank/VNPay settings (fresh_setup_test.php provides both)\n";
    } else {
        $password='Password123!';
        $anonymous=payClient();
        $guestClient=payClient(); payLogin($guestClient,$baseUrl,'guest@home2home.test',$password);
        $hostClient=payClient(); payLogin($hostClient,$baseUrl,'host@home2home.test',$password);
        $adminClient=payClient(); payLogin($adminClient,$baseUrl,'admin@home2home.test',$password);
        $bookingD=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,15);
        $payPath='/bookings/'.$bookingD.'/pay';
        $response=payHttp($anonymous,$baseUrl,$payPath);
        payExpect($response['status']===302 && $response['location']==='/login','Anonymous user reached the payment page.');
        payExpect(payHttp($hostClient,$baseUrl,$payPath)['status']===404,'Host reached the Guest payment page.');
        $page=payHttp($guestClient,$baseUrl,$payPath);
        payExpect($page['status']===200 && str_contains($page['body'],$payPath.'/vietqr') && str_contains($page['body'],$payPath.'/vnpay')
            && str_contains($page['body'],'220.000đ'),'Guest payment page is missing the two methods or the amount.');
        payExpect(str_contains(payHttp($guestClient,$baseUrl,'/bookings/'.$bookingD)['body'],$payPath),'Booking detail has no online payment button.');
        $guestToken=payToken($guestClient,$baseUrl,$payPath);
        payHttp($guestClient,$baseUrl,$payPath.'/vietqr',[]);
        payExpect((int)payInspect($bookingD)['attempts']===0,'Payment POST without CSRF token created an attempt.');
        $response=payHttp($guestClient,$baseUrl,$payPath.'/vietqr',['_token'=>$guestToken]);
        payExpect($response['status']===302 && (bool)preg_match('#^/payments/(\d+)$#',$response['location'],$match),'Starting a VietQR transfer did not open the transfer page.');
        $transferId=(int)$match[1]; $transfer=payAttempt($transferId);
        $page=payHttp($guestClient,$baseUrl,'/payments/'.$transferId);
        payExpect($page['status']===200 && str_contains($page['body'],$transfer['reference']) && str_contains($page['body'],'img.vietqr.io/image/970436-0001234567-compact2.png')
            && str_contains($page['body'],'0001234567') && str_contains($page['body'],'/payments/'.$transferId.'/transferred'),'Transfer page is missing the QR, account or reference.');
        payExpect(payHttp($hostClient,$baseUrl,'/payments/'.$transferId)['status']===404,'Host opened the Guest transfer page.');
        $response=payHttp($guestClient,$baseUrl,'/payments/'.$transferId.'/transferred',['_token'=>$guestToken]);
        payExpect($response['status']===302 && payAttempt($transferId)['status']==='submitted','Guest could not report the transfer over HTTP.');
        $adminPage=payHttp($adminClient,$baseUrl,'/admin/payments');
        payExpect($adminPage['status']===200 && str_contains($adminPage['body'],$transfer['reference']) && str_contains($adminPage['body'],'/admin/payments/transfers/'.$transferId),
            'Admin payments page does not list the reported transfer.');
        $response=payHttp($guestClient,$baseUrl,'/admin/payments/transfers/'.$transferId,['_token'=>$guestToken,'decision'=>'confirm']);
        payExpect($response['status']===403 && payAttempt($transferId)['status']==='submitted','Guest confirmed a transfer over HTTP.');
        $adminToken=payToken($adminClient,$baseUrl,'/admin/payments');
        $response=payHttp($adminClient,$baseUrl,'/admin/payments/transfers/'.$transferId,['_token'=>$adminToken,'decision'=>'confirm','note'=>'','return_to'=>'detail','booking_id'=>(string)$bookingD]);
        $state=payInspect($bookingD);
        payExpect($response['status']===302 && $response['location']==='/bookings/'.$bookingD && $state['payment_status']==='paid' && $state['payment_method']==='vietqr',
            'Admin confirmation over HTTP failed: '.json_encode($state));
        $detail=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingD)['body'];
        payExpect(str_contains($detail,'Chuyển khoản VietQR') && str_contains($detail,$transfer['reference']) && !str_contains($detail,$payPath.'"'),'Paid booking detail is wrong.');

        $bookingE=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,20);
        $response=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingE.'/pay/vnpay',['_token'=>$guestToken]);
        parse_str((string)parse_url($response['redirect'],PHP_URL_QUERY),$query);
        payExpect($response['status']===302 && str_starts_with($response['redirect'],$paymentConfig['vnpay_pay_url'].'?') && VnpayGateway::verify($paymentConfig,$query)
            && $query['vnp_Amount']==='22000000' && $query['vnp_ReturnUrl']===$baseUrl.'/payments/vnpay/return','VNPay redirect is wrong: '.$response['redirect']);
        $vnpay=['booking_id'=>$bookingE,'reference'=>$query['vnp_TxnRef']];
        $forged=payVnpayResult($vnpay,$paymentConfig['vnpay_tmn_code'],'not-the-secret','00','220000','15000001');
        $response=payHttp($guestClient,$baseUrl,'/payments/vnpay/return?'.http_build_query($forged));
        payExpect($response['status']===302 && $response['location']==='/bookings' && payInspect($bookingE)['payment_status']==='unpaid','Forged VNPay return was accepted.');
        $ipn=json_decode(payHttp($anonymous,$baseUrl,'/payments/vnpay/ipn?'.http_build_query($forged))['body'],true);
        payExpect(($ipn['RspCode']??'')==='97','Forged IPN did not answer 97.');
        $valid=payVnpayResult($vnpay,$paymentConfig['vnpay_tmn_code'],$paymentConfig['vnpay_hash_secret'],'00','220000','15000001');
        $response=payHttp($guestClient,$baseUrl,'/payments/vnpay/return?'.http_build_query($valid));
        $state=payInspect($bookingE);
        payExpect($response['status']===302 && $response['location']==='/bookings/'.$bookingE && $state['payment_status']==='paid' && $state['payment_method']==='vnpay',
            'Signed VNPay return did not mark the booking paid: '.json_encode($state));
        payExpect(str_contains(payHttp($guestClient,$baseUrl,'/bookings/'.$bookingE)['body'],'Thanh toán VNPay thành công'),'VNPay success message is missing.');
        $ipn=json_decode(payHttp($anonymous,$baseUrl,'/payments/vnpay/ipn?'.http_build_query($valid))['body'],true);
        payExpect(($ipn['RspCode']??'')==='02' && (int)payInspect($bookingE)['payment_events']===1,'Repeated IPN did not answer 02 or recorded twice.');
        $unknown=payVnpayResult(['booking_id'=>$bookingE,'reference'=>'H2H0XNOPE'],$paymentConfig['vnpay_tmn_code'],$paymentConfig['vnpay_hash_secret'],'00','220000','15000002');
        payExpect((json_decode(payHttp($anonymous,$baseUrl,'/payments/vnpay/ipn?'.http_build_query($unknown))['body'],true)['RspCode']??'')==='01','Unknown IPN reference did not answer 01.');
        $wrongAmount=payVnpayResult($vnpay,$paymentConfig['vnpay_tmn_code'],$paymentConfig['vnpay_hash_secret'],'00','1','15000003');
        payExpect((json_decode(payHttp($anonymous,$baseUrl,'/payments/vnpay/ipn?'.http_build_query($wrongAmount))['body'],true)['RspCode']??'')==='04','Wrong IPN amount did not answer 04.');
        foreach ([$anonymous,$guestClient,$hostClient,$adminClient] as $client) { curl_close($client); }
        echo "PASS online payment HTTP: Guest-only pages, CSRF, QR page with account and reference, report, Admin queue/403/confirm, VNPay redirect signature, forged return/IPN 97, valid return, IPN 02/01/04\n";
    }
    $completed=true;
} finally {
    if ($listingId!==null) {
        try { payCall('CALL sp_test_payment_cleanup(?)',[$listingId]); }
        catch (Throwable $exception) {
            fwrite(STDERR,'Online payment cleanup failed: '.$exception->getMessage()."\n");
            if ($completed) { throw new RuntimeException('Online payment fixture cleanup failed.'); }
        }
    }
}
echo "PASS online payment fixtures removed (listing, bookings, attempts, payments, notifications, audit rows)\n";
