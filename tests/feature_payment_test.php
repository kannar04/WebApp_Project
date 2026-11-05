<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\BookingExtras;
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
 * account and a per-run VNPay secret. Fixture dates are today+4300..4500 days. Everything created here is removed in `finally`.
 */

/** Admin payment rows of one booking. The Admin search also matches titles, and fixture titles hold random digits. */
function payAdminRows(int $adminId,int $bookingId): array
{
    $rows=[];
    foreach ((new App\Models\BookingExtras())->adminPayments($adminId,'',(string)$bookingId) as $row) {
        if ((int)$row['booking_id']===$bookingId) { $rows[]=$row; }
    }
    return $rows;
}

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

/** payConfirmedBooking on $listingId, paid through VNPay. */
function payPaidBooking(BookingService $service, Payment $payments, int $listingId, int $guestId, int $hostId, DateTimeImmutable $base, int $offset, string $transactionNo): int
{
    $bookingId=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,$offset);
    $attempt=$payments->start($bookingId,$guestId,'vnpay');
    $payments->vnpayResult($attempt['reference'],(string)$attempt['amount'],'00','00',$transactionNo,'NCB');
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
$listingId=null; $completed=false; $policyListings=[];
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
    payExpect($payments->vnpayResult($v1['reference'],'220000.00','24','02','0','')['result']==='already' && payAttempt((int)$v1['transaction_id'])['status']==='failed','A repeated failure was recorded again.');
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
    payExpect(str_contains((string)$state['guest_paid_body'],'đã hủy') && str_contains((string)$state['guest_paid_body'],'Admin sẽ hoàn lại') && !str_contains((string)$state['guest_paid_body'],'thành công') && $state['guest_paid_title']==='Khoản thanh toán sẽ được hoàn lại',
        'The Guest was only told the payment succeeded on a cancelled booking: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $result=$payments->vnpayResult($c1['reference'],'220000.00','00','00','14000003','NCB');
    payExpect($result['result']==='already' && $result['return_reason']==='cancelled' && (float)$result['refund_owed']===220000.0,'VNPay result does not say the money goes back: '.json_encode($result));
    payRejects([$payments,'start'],[$bookingC,$guestId,'vnpay'],'đã được ghi nhận thanh toán','A cancelled booking started a payment');
    // A signed success after a signed non-success on the same reference is money taken: recorded, or flagged when the booking is already paid.
    $failedFirst=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,125);
    $failedAttempt=$payments->start($failedFirst,$guestId,'vnpay');
    $payments->vnpayResult($failedAttempt['reference'],'220000.00','24','02','0','');
    $result=$payments->vnpayResult($failedAttempt['reference'],'220000.00','00','00','14000070','NCB');
    $state=payInspect($failedFirst); $stored=payAttempt((int)$failedAttempt['transaction_id']);
    payExpect($result['result']==='ok' && $result['transaction_status']==='succeeded' && $state['payment_status']==='paid' && (int)$state['paid_notices']===1 && (int)$state['payment_events']===1
        && $stored['gateway_transaction_no']==='14000070' && $stored['gateway_response_code']==='00','A success after a failure on one reference was dropped: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payExpect($payments->vnpayResult($failedAttempt['reference'],'220000.00','24','02','0','')['result']==='already' && payAttempt((int)$failedAttempt['transaction_id'])['status']==='succeeded' && (int)payInspect($failedFirst)['payment_events']===1,'A failure after a success changed the payment.');
    $failedDuplicate=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,128);
    $failedDupFirst=$payments->start($failedDuplicate,$guestId,'vnpay'); $failedDupOther=$payments->start($failedDuplicate,$guestId,'vnpay');
    $payments->vnpayResult($failedDupFirst['reference'],'220000.00','24','02','0','');
    $payments->vnpayResult($failedDupOther['reference'],'220000.00','00','00','14000071','NCB');
    $result=$payments->vnpayResult($failedDupFirst['reference'],'220000.00','00','00','14000072','NCB');
    $state=payInspect($failedDuplicate);
    payExpect($result['result']==='ok' && (int)$result['duplicate_payment']===1 && (int)$state['payment_events']===1 && (int)$state['admin_duplicate_notices']>=1,'A late success on a paid booking was not flagged: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    echo "PASS VNPay results: unknown/amount guards, cancelled, success once, repeat-safe, duplicate and after-cancel payments flagged for refund, success after a failure recorded\n";

    // ---- Whole dong: a booking still holding a decimal amount pays one rounded figure on VietQR and VNPay.
    $bookingF=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,30);
    payCall('CALL sp_test_payment_set_amount(?,?)',[$bookingF,'220000.50']);
    $wholeVnpay=$payments->start($bookingF,$guestId,'vnpay');
    $wholeQr=$payments->start($bookingF,$guestId,'vietqr');
    payExpect((float)$wholeVnpay['amount']===220001.0 && (float)$wholeQr['amount']===220001.0,'Attempts of a decimal booking are not whole dong: '.json_encode([$wholeVnpay,$wholeQr]));
    $wholeGateway=['vnpay_tmn_code'=>'H2HTEST1','vnpay_hash_secret'=>'fixture-secret','vnpay_pay_url'=>'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'];
    parse_str((string)parse_url(VnpayGateway::paymentUrl($wholeGateway,$wholeVnpay,'http://127.0.0.1/payments/vnpay/return','127.0.0.1','vi',new DateTimeImmutable('now')),PHP_URL_QUERY),$query);
    $wholeLink=VietQr::imageUrl(['bank_id'=>'970436','account_no'=>'0001234567','account_name'=>'HOME2HOME TEST'],(float)$wholeQr['amount'],$wholeQr['reference']);
    payExpect($query['vnp_Amount']==='22000100' && str_contains($wholeLink,'amount=220001&'),'VietQR and VNPay do not ask for the same whole-dong amount.');
    payExpect((int)$payments->start($bookingF,$guestId,'vietqr')['transaction_id']===(int)$wholeQr['transaction_id'],'Reloading the QR page of a decimal booking changed the transfer code.');
    foreach (['220000.00','220000.50','220002.00'] as $wrongAmount) {
        payExpect($payments->vnpayResult($wholeVnpay['reference'],$wrongAmount,'00','00','14000010','NCB')['result']==='invalid_amount' && payInspect($bookingF)['payment_status']==='unpaid','A genuinely different amount was accepted: '.$wrongAmount);
    }
    $result=$payments->vnpayResult($wholeVnpay['reference'],'220001.00','00','00','14000010','NCB');
    $state=payInspect($bookingF);
    payExpect($result['result']==='ok' && $result['transaction_status']==='succeeded' && $state['payment_status']==='paid' && (float)$state['amount_due']===220001.0,'A correct whole-dong payment was not recorded: '.json_encode($state));
    $wholeRefund=$service->cancelByGuest($bookingF,$guestId,'Audit fixture whole dong')['refund_amount'];
    payExpect(fmod($wholeRefund,1.0)===0.0,'The refund of a decimal booking is not whole dong: '.$wholeRefund);
    $bookingG=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,33);
    $oldVnpay=$payments->start($bookingG,$guestId,'vnpay');
    payCall('CALL sp_test_payment_set_amount(?,?)',[$bookingG,'220000.50']);
    payExpect((float)payAttempt((int)$oldVnpay['transaction_id'])['amount']===220000.5,'Fixture did not keep the decimal attempt.');
    $result=$payments->vnpayResult($oldVnpay['reference'],'220001.00','00','00','14000011','NCB');
    payExpect($result['result']==='ok' && $result['transaction_status']==='succeeded' && (float)payInspect($bookingG)['amount_due']===220001.0,'An attempt started with a decimal amount became invalid_amount.');
    $bookingH=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,36);
    $oldQr=$payments->start($bookingH,$guestId,'vietqr');
    payCall('CALL sp_test_payment_set_amount(?,?)',[$bookingH,'220000.50']);
    payExpect((int)$payments->start($bookingH,$guestId,'vietqr')['transaction_id']===(int)$oldQr['transaction_id'],'A decimal QR attempt was not reused.');
    $payments->submitTransfer((int)$oldQr['transaction_id'],$guestId);
    $payments->decideTransfer((int)$oldQr['transaction_id'],$adminId,'confirm','');
    payExpect((float)payInspect($bookingH)['amount_due']===220001.0,'Confirming a decimal transfer did not record whole dong.');
    echo "PASS whole dong: decimal bookings pay one rounded figure on VietQR and VNPay, correct amount never invalid, different amount still invalid, refund whole dong\n";

    // ---- VietQR plus VNPay on one booking: only one counts, a late second payment is reported and closed alone.
    $bookingI=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,39);
    $lateVnpay=$payments->start($bookingI,$guestId,'vnpay');
    $lateQr=$payments->start($bookingI,$guestId,'vietqr');
    $lateVnpayId=(int)$lateVnpay['transaction_id']; $lateQrId=(int)$lateQr['transaction_id'];
    $payments->submitTransfer($lateQrId,$guestId);
    payExpect(payAttempt($lateVnpayId)['status']==='cancelled' && payInspect($bookingI)['attempt_states']==='vnpay:cancelled,vietqr:submitted','Reporting a transfer left the VNPay attempt open.');
    $payments->decideTransfer($lateQrId,$adminId,'confirm','');
    $result=$payments->vnpayResult($lateVnpay['reference'],'220000.00','00','00','14000012','NCB');
    $state=payInspect($bookingI);
    payExpect($result['result']==='ok' && (int)$result['duplicate_payment']===1 && $state['payment_status']==='paid' && $state['payment_method']==='vietqr' && (int)$state['payment_events']===1
        && (int)$state['paid_notices']===1 && (int)$state['guest_duplicate_notices']===1 && (int)$state['admin_duplicate_notices']>=1,'Second payment was not reported as a duplicate: '.json_encode($state));
    $result=$payments->vnpayResult($lateVnpay['reference'],'220000.00','00','00','14000012','NCB');
    payExpect($result['result']==='already' && (int)$result['duplicate_payment']===1 && (int)payInspect($bookingI)['guest_duplicate_notices']===1,'Repeating a duplicate result lost the flag or notified twice.');
    $listed=payAdminRows($adminId,$bookingI);
    payExpect(count($listed)===1 && (int)$listed[0]['duplicate_id']===$lateVnpayId && $listed[0]['payment_state']==='paid','Admin payments list does not show the open duplicate.');
    $attemptRows=$payments->bookingAttempts($bookingI,$adminId);
    payExpect((int)$attemptRows[0]['transaction_id']===$lateQrId && (int)$attemptRows[0]['is_open_duplicate']===0 && (int)$attemptRows[1]['is_open_duplicate']===1,'Booking attempts do not mark the open duplicate.');
    payRejects([$payments,'resolveDuplicate'],[$lateVnpayId,$guestId,'Đã hoàn'],'quyền quản trị','Guest closed a duplicate');
    payRejects([$payments,'resolveDuplicate'],[$lateVnpayId,$hostId,'Đã hoàn'],'quyền quản trị','Host closed a duplicate');
    payRejects([$payments,'resolveDuplicate'],[$lateVnpayId,$adminId,'   '],'ghi chú','Closing a duplicate without a note accepted');
    payRejects([$payments,'resolveDuplicate'],[$lateQrId,$adminId,'Đã hoàn'],'không phải thanh toán trùng','The payment that counts was closed as a duplicate');
    payRejects([$payments,'resolveDuplicate'],[(int)$v2['transaction_id'],$adminId,'Đã hoàn'],'không phải thanh toán trùng','A first VNPay success was closed as a duplicate');
    $resolved=$payments->resolveDuplicate($lateVnpayId,$adminId,'Đã hoàn qua ngân hàng');
    payExpect($resolved['payment_status']==='paid' && str_contains((string)payInspect($bookingI)['guest_duplicate_refunded_body'],'vẫn được giữ là đã thanh toán'),'Closing a duplicate on a paid booking does not say it stays paid.');
    $stored=payAttempt($lateVnpayId); $state=payInspect($bookingI);
    payExpect($stored['status']==='cancelled' && (int)$stored['decided_by']===$adminId && $stored['note']==='Đã hoàn khoản thanh toán trùng: Đã hoàn qua ngân hàng' && $state['payment_status']==='paid'
        && (int)$state['payment_events']===1 && (int)$state['guest_duplicate_refunded_notices']===1 && (int)$state['duplicate_audits']===1,'Closing the duplicate changed more than that transaction: '.json_encode($state));
    payExpect(payAdminRows($adminId,$bookingI)[0]['duplicate_id']===null,'A closed duplicate is still listed as open.');
    payRejects([$payments,'resolveDuplicate'],[$lateVnpayId,$adminId,'Đã hoàn'],'không phải thanh toán trùng','A duplicate was closed twice');
    $result=$payments->vnpayResult($lateVnpay['reference'],'220000.00','00','00','14000012','NCB');
    payExpect($result['result']==='already' && payAttempt($lateVnpayId)['status']==='cancelled','Replaying the result reopened a closed duplicate.');
    payExpect((int)$result['duplicate_payment']===0 && (int)$result['duplicate_refunded']===1 && $result['payment_status']==='paid','Replaying a closed duplicate still reports it as open: '.json_encode($result));
    // The main payment was refunded before the duplicate was closed: nothing may claim the booking is still paid.
    $bookingRD=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,113);
    $firstDup=$payments->start($bookingRD,$guestId,'vnpay'); $secondDup=$payments->start($bookingRD,$guestId,'vnpay');
    $payments->vnpayResult($firstDup['reference'],'220000.00','00','00','14000060','NCB');
    $payments->vnpayResult($secondDup['reference'],'220000.00','00','00','14000061','NCB');
    (new BookingExtras())->refund($bookingRD,$adminId,'Hoàn khoản chính');
    $resolved=$payments->resolveDuplicate((int)$secondDup['transaction_id'],$adminId,'Hoàn cả khoản trùng');
    $state=payInspect($bookingRD);
    payExpect($resolved['payment_status']==='refunded' && $state['payment_status']==='refunded' && str_contains((string)$state['guest_duplicate_refunded_body'],'cũng đã được hoàn') && !str_contains((string)$state['guest_duplicate_refunded_body'],'đã thanh toán'),
        'Closing a duplicate on a refunded booking claims it is still paid: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    // VNPay lands first while the transfer waits: the Admin cannot confirm a second success, and closing the transfer leaves the booking paid.
    $bookingJ=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,42);
    $firstVnpay=$payments->start($bookingJ,$guestId,'vnpay');
    $waitingQr=$payments->start($bookingJ,$guestId,'vietqr');
    $waitingQrId=(int)$waitingQr['transaction_id'];
    $payments->submitTransfer($waitingQrId,$guestId);
    $result=$payments->vnpayResult($firstVnpay['reference'],'220000.00','00','00','14000013','NCB');
    payExpect($result['transaction_status']==='succeeded' && (int)$result['duplicate_payment']===0 && payInspect($bookingJ)['payment_status']==='paid','A first payment was reported as a duplicate.');
    payRejects([$payments,'decideTransfer'],[$waitingQrId,$adminId,'confirm',''],'đã được ghi nhận thanh toán','Admin confirmed a second success');
    $state=payInspect($bookingJ);
    payExpect(payAttempt($waitingQrId)['status']==='submitted' && (int)$state['payment_events']===1 && $state['payment_method']==='vnpay','A refused confirmation changed the booking.');
    $queued=[];
    foreach ($payments->adminTransfers($adminId) as $row) { if ((int)$row['transaction_id']===$waitingQrId) { $queued=$row; } }
    payExpect(($queued['payment_status']??'')==='paid','Admin queue does not show the booking is already paid.');
    payRejects([$payments,'decideTransfer'],[$waitingQrId,$adminId,'reject','abc'],'đóng giao dịch trùng','Closing a duplicate transfer without a reason accepted');
    $closed=$payments->decideTransfer($waitingQrId,$adminId,'reject','Booking đã thanh toán qua VNPay');
    $state=payInspect($bookingJ);
    $stored=payAttempt($waitingQrId);
    payExpect((int)$closed['duplicate_closed']===1 && $stored['status']==='cancelled' && (int)$stored['decided_by']===$adminId && str_contains((string)$stored['note'],'Admin sẽ hoàn lại cho khách') && str_contains((string)$stored['note'],'Booking đã thanh toán qua VNPay')
        && $state['payment_status']==='paid' && (int)$state['payment_events']===1,'Closing the duplicate transfer changed the booking: '.json_encode($state));
    payExpect((int)$state['rejected_notices']===0 && (int)$state['transfer_close_audits']===1 && str_contains((string)$state['guest_transfer_closed_body'],'Admin sẽ hoàn lại cho bạn') && !str_contains((string)$state['guest_transfer_closed_body'],'Chưa xác nhận'),
        'Closing a duplicate transfer told the Guest the money never came: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payExpect(!$payments->decideTransfer((int)$payments->start(payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,110),$guestId,'vietqr')['transaction_id'],$adminId,'reject','Chưa thấy tiền vào')['duplicate_closed'],'A plain rejection was reported as closing a duplicate.');
    echo "PASS duplicate payments: transfer report closes VNPay, late payment flagged to Guest and Admin, Admin confirm refused, Admin closes only that transaction, replay cannot reopen\n";

    // ---- Cancelling a paid booking: Admins learn the refund owed in the same transaction; the refund records the policy amount.
    foreach ($listings->policies() as $policy) {
        $policyListing=$listings->create($hostId,array_merge($data,['cancellation_policy_id'=>(int)$policy['id'],'title'=>'Audit fixture PAY '.bin2hex(random_bytes(6))]));
        $policyListings[(int)$policy['early_refund_pct']]=$policyListing;
        payCall('CALL sp_test_listing_approve(?)',[$policyListing]);
    }
    payExpect(isset($policyListings[50],$policyListings[0]),'Seed policies with 50% and 0% refunds required.');
    $extras=new BookingExtras();
    $bookingM=payPaidBooking($service,$payments,$policyListings[50],$guestId,$hostId,$base,50,'14000020');
    $db=ProcedureConnection::connection();
    $db->beginTransaction();
    $service->cancelByGuest($bookingM,$guestId,'Audit fixture rolled back');
    payExpect((int)payInspect($bookingM)['admin_refund_notices']>=1,'Cancelling a paid booking did not notify Admins.');
    $db->rollBack();
    $state=payInspect($bookingM);
    payExpect((int)$state['admin_refund_notices']===0 && $state['booking_status']==='confirmed' && $state['cancel_refund']===null,'Admin notices are not in the cancellation transaction: '.json_encode($state));
    $cancelled=$service->cancelByGuest($bookingM,$guestId,'Audit fixture half refund');
    $state=payInspect($bookingM);
    payExpect($cancelled['was_paid'] && $cancelled['refund_amount']===100000.0 && (int)$state['admin_refund_notices']>=1 && str_contains((string)$state['admin_refund_body'],'100.000đ')
        && str_contains((string)$state['admin_refund_body'],'50%') && (float)$state['cancel_refund']===100000.0,'Admin notice does not state the refund owed: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $row=payAdminRows($adminId,$bookingM)[0];
    payExpect((float)$row['refund_amount']===100000.0 && (float)$row['amount_due']===220000.0 && (float)$extras->bookingDetail($bookingM,$adminId)['refund_amount']===100000.0,'Admin views do not show the policy refund.');
    $totalsBefore=$extras->adminPaymentTotals($adminId);
    $extras->refund($bookingM,$adminId,'Đã hoàn theo chính sách');
    $state=payInspect($bookingM);
    $totalsAfter=$extras->adminPaymentTotals($adminId);
    payExpect($state['payment_status']==='refunded' && (float)$state['refund_audit_amount']===100000.0 && str_contains((string)$state['guest_refund_body'],'100.000đ'),'Refund did not record the policy amount: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payExpect((float)$totalsAfter['refunded_amount']-(float)$totalsBefore['refunded_amount']===100000.0 && (float)$totalsBefore['collected_amount']-(float)$totalsAfter['collected_amount']===100000.0,'Admin totals count the full payment as refunded.');
    $bookingN=payPaidBooking($service,$payments,$policyListings[0],$guestId,$hostId,$base,50,'14000021');
    $cancelled=$service->cancelByGuest($bookingN,$guestId,'Audit fixture no refund');
    $state=payInspect($bookingN);
    payExpect($cancelled['was_paid'] && $cancelled['refund_amount']===0.0 && (int)$state['admin_cancelled_paid_notices']>=1 && (int)$state['admin_refund_notices']===0
        && str_contains((string)$state['admin_refund_body'],'0đ') && str_contains((string)$state['guest_cancel_body'],'không được hoàn'),'A paid booking with no refund is not reported clearly: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payRejects([$extras,'refund'],[$bookingN,$adminId,'Không có khoản hoàn'],'không có khoản hoàn','A refund of nothing was recorded');
    payExpect(payInspect($bookingN)['payment_status']==='paid','A refused refund changed the payment.');
    $bookingO=payConfirmedBooking($service,$policyListings[50],$guestId,$hostId,$base,55);
    payExpect(!$service->cancelByGuest($bookingO,$guestId,'Audit fixture unpaid')['was_paid'] && (int)payInspect($bookingO)['admin_refund_notices']===0,'Cancelling an unpaid booking asked Admins for a refund.');
    echo "PASS cancel after payment: Admin notice with the refund owed in the same transaction, refund and totals use the policy amount, zero refund shown clearly and refused\n";

    // ---- A taken-down listing: no new online payment; money that already left is recorded and flagged for a refund.
    $removedListing=$policyListings[100];
    $bookingR=payConfirmedBooking($service,$removedListing,$guestId,$hostId,$base,65);
    $bookingS=payConfirmedBooking($service,$removedListing,$guestId,$hostId,$base,68);
    $bookingT=payConfirmedBooking($service,$removedListing,$guestId,$hostId,$base,71);
    $openVnpay=$payments->start($bookingR,$guestId,'vnpay');
    $openQr=$payments->start($bookingS,$guestId,'vietqr');
    payCall('CALL sp_test_payment_listing_removed(?,?)',[$removedListing,1]);
    $checkout=$payments->checkout($bookingT,$guestId);
    payExpect((int)$checkout['can_pay_online']===0 && (int)$checkout['listing_removed']===1,'A taken-down listing still offers online payment.');
    payExpect((int)$extras->bookingDetail($bookingT,$guestId)['can_pay_online']===0 && (int)$extras->paymentMap($guestId,'guest')[$bookingT]['can_pay_online']===0,'Booking pages still offer online payment on a taken-down listing.');
    payRejects([$payments,'start'],[$bookingT,$guestId,'vietqr'],'đã bị gỡ','VietQR started on a taken-down listing');
    payRejects([$payments,'start'],[$bookingT,$guestId,'vnpay'],'đã bị gỡ','VNPay started on a taken-down listing');
    payExpect((int)payInspect($bookingT)['attempts']===0,'A refused payment left an attempt behind.');
    payExpect((int)$payments->attempt((int)$openQr['transaction_id'],$guestId)['listing_removed']===1,'Transfer page does not know the listing was taken down.');
    payRejects([$payments,'submitTransfer'],[(int)$openQr['transaction_id'],$guestId],'đã bị gỡ','Transfer reported on a taken-down listing');
    $result=$payments->vnpayResult($openVnpay['reference'],'220000.00','00','00','14000030','NCB');
    $state=payInspect($bookingR);
    payExpect($result['return_reason']==='removed' && str_contains((string)$state['guest_paid_body'],'đã bị gỡ') && str_contains((string)$state['guest_paid_body'],'Admin sẽ hoàn lại') && !str_contains((string)$state['guest_paid_body'],'thành công'),'The Guest was only told the payment succeeded on a taken-down listing: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payExpect($result['result']==='ok' && $state['payment_status']==='paid' && (int)$state['admin_refund_notices']>=1 && str_contains((string)$state['admin_refund_body'],'đã gỡ'),'VNPay money on a taken-down listing was not flagged: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $payments->decideTransfer((int)$openQr['transaction_id'],$adminId,'confirm','');
    $state=payInspect($bookingS);
    payExpect(str_contains((string)$state['guest_paid_body'],'đã bị gỡ') && str_contains((string)$state['guest_paid_body'],'Admin sẽ hoàn lại'),'The Guest was not told a transfer on a taken-down listing goes back: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payExpect($state['payment_status']==='paid' && (int)$state['admin_refund_notices']>=1 && str_contains((string)$state['admin_refund_body'],'đã gỡ'),'Confirmed transfer on a taken-down listing was not flagged: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payCall('CALL sp_test_payment_listing_removed(?,?)',[$removedListing,0]);
    payExpect((int)$payments->checkout($bookingT,$guestId)['can_pay_online']===1 && $payments->start($bookingT,$guestId,'vietqr')['status']==='pending','A listing that is back cannot be paid.');
    $normal=payInspect($bookingG);
    payExpect((int)$normal['admin_refund_notices']===0,'An ordinary payment asked Admins for a refund.');
    echo "PASS taken-down listing: checkout, start and report refuse with a clear message, money already sent is recorded and flagged to Admins\n";

    // ---- Nothing collected, nothing refunded; money that lands after the cancellation goes back whole.
    $bookingModel=new \App\Models\Booking();
    $unpaidPending=$service->create($policyListings[100],$guestId,$base->modify('+77 days')->format('Y-m-d'),$base->modify('+79 days')->format('Y-m-d'),1);
    $unpaidConfirmed=payConfirmedBooking($service,$policyListings[100],$guestId,$hostId,$base,80);
    foreach ([$unpaidPending,$unpaidConfirmed] as $unpaid) {
        payExpect((float)$bookingModel->detail($unpaid,$guestId)['refund']['refund_amount']===0.0,'Refund preview of an unpaid booking is not zero.');
        $cancelled=$service->cancelByGuest($unpaid,$guestId,'Audit fixture nothing paid');
        $state=payInspect($unpaid);
        payExpect(!$cancelled['was_paid'] && $cancelled['refund_amount']===0.0 && $cancelled['refund_percent']===0.0 && (float)$state['cancel_refund']===0.0,'An unpaid booking recorded a refund: '.json_encode($state,JSON_UNESCAPED_UNICODE));
        payExpect(!str_contains((string)$state['guest_cancel_body'],'hoàn') && (int)$state['admin_refund_notices']===0,'An unpaid cancellation talks about a refund: '.json_encode($state,JSON_UNESCAPED_UNICODE));
        payExpect((float)$bookingModel->detail($unpaid,$guestId)['refund']['refund_amount']===0.0 && (float)$extras->bookingDetail($unpaid,$guestId)['refund_amount']===0.0,'Stored refund of an unpaid booking is not zero.');
    }
    $bookingW=payConfirmedBooking($service,$policyListings[50],$guestId,$hostId,$base,83);
    $lateVnpay=$payments->start($bookingW,$guestId,'vnpay');
    $service->cancelByGuest($bookingW,$guestId,'Audit fixture cancelled while paying');
    payExpect((float)payInspect($bookingW)['cancel_refund']===0.0,'Cancelling before the money arrived recorded a refund.');
    $payments->vnpayResult($lateVnpay['reference'],'220000.00','00','00','14000040','NCB');
    $state=payInspect($bookingW);
    payExpect($state['payment_status']==='paid' && (float)$state['cancel_refund']===220000.0 && (int)$state['admin_refund_notices']>=1 && str_contains((string)$state['admin_refund_body'],'220.000đ'),'Late VNPay money does not go back whole: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $extras->refund($bookingW,$adminId,'Trả lại khoản nhận sau khi hủy');
    $state=payInspect($bookingW);
    payExpect($state['payment_status']==='refunded' && (float)$state['refund_audit_amount']===220000.0,'Admin could not refund late VNPay money: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $bookingX=payConfirmedBooking($service,$policyListings[50],$guestId,$hostId,$base,86);
    $lateTransfer=$payments->start($bookingX,$guestId,'vietqr');
    $payments->submitTransfer((int)$lateTransfer['transaction_id'],$guestId);
    $service->cancelByGuest($bookingX,$guestId,'Audit fixture cancelled after the transfer');
    $payments->decideTransfer((int)$lateTransfer['transaction_id'],$adminId,'confirm','');
    $state=payInspect($bookingX);
    payExpect(str_contains((string)$state['guest_paid_body'],'đã hủy') && str_contains((string)$state['guest_paid_body'],'Admin sẽ hoàn lại'),'The Guest was not told a late transfer goes back: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    payExpect($state['payment_status']==='paid' && (float)$state['cancel_refund']===220000.0 && (int)$state['admin_refund_notices']>=1 && str_contains((string)$state['admin_refund_body'],'đã hủy nhưng vẫn nhận'),'Late transfer does not go back whole: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $extras->refund($bookingX,$adminId,'Trả lại khoản nhận sau khi hủy');
    payExpect(payInspect($bookingX)['payment_status']==='refunded','Admin could not refund a late transfer.');
    echo "PASS unpaid cancel: pending and confirmed record 0 and say nothing about a refund, late VNPay or transfer money is owed back whole\n";

    // ---- Owed and refunded are two facts: cancelling after a refund owes nothing more and keeps the figure that was refunded.
    $refundedFirst=payPaidBooking($service,$payments,$policyListings[50],$guestId,$hostId,$base,98,'14000050');
    $totalsBefore=$extras->adminPaymentTotals($adminId);
    $extras->refund($refundedFirst,$adminId,'Hoàn toàn bộ trước khi hủy');
    payExpect((float)$bookingModel->detail($refundedFirst,$guestId)['refund']['refund_amount']===0.0,'A refunded booking still previews a further refund.');
    $cancelled=$service->cancelByGuest($refundedFirst,$guestId,'Audit fixture cancelled after the refund');
    $state=payInspect($refundedFirst);
    payExpect(!$cancelled['was_paid'] && $cancelled['refund_amount']===0.0 && (float)$state['cancel_refund']===0.0 && (float)$state['refund_audit_amount']===220000.0
        && (int)$state['admin_refund_notices']===0 && str_contains((string)$state['guest_cancel_body'],'không có khoản hoàn thêm') && !str_contains((string)$state['guest_cancel_body'],'Mức hoàn'),
        'Cancelling after a refund re-applied the policy: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $detail=$extras->bookingDetail($refundedFirst,$guestId);
    $row=payAdminRows($adminId,$refundedFirst)[0];
    payExpect((float)$detail['refund_amount']===0.0 && (float)$detail['refunded_amount']===220000.0 && (float)$row['refunded_amount']===220000.0,'Cancelling after a refund changed the refunded figure.');
    $totals=$extras->adminPaymentTotals($adminId);
    payExpect((float)$totals['collected_amount']-(float)$totalsBefore['collected_amount']===-220000.0 && (float)$totals['refunded_amount']-(float)$totalsBefore['refunded_amount']===220000.0,
        'Totals moved when a refunded booking was cancelled: '.json_encode([$totalsBefore,$totals]));
    payRejects([$extras,'refund'],[$refundedFirst,$adminId,'Lần nữa'],'Chỉ hoàn tiền','A refunded booking was refunded again after cancelling');

    // A policy-share refund reports the share; a row refunded by older code (full amount_due, policy share in the cancellation) reports what really went back.
    $oldShape=payPaidBooking($service,$payments,$policyListings[50],$guestId,$hostId,$base,101,'14000051');
    $service->cancelByGuest($oldShape,$guestId,'Audit fixture old refund');
    $totalsBefore=$extras->adminPaymentTotals($adminId);
    $extras->refund($oldShape,$adminId,'Hoàn theo chính sách');
    $detail=$extras->bookingDetail($oldShape,$guestId);
    $totals=$extras->adminPaymentTotals($adminId);
    payExpect((float)$detail['refund_amount']===100000.0 && (float)$detail['refunded_amount']===100000.0 && (float)$totals['refunded_amount']-(float)$totalsBefore['refunded_amount']===100000.0
        && (float)$totals['collected_amount']-(float)$totalsBefore['collected_amount']===-100000.0,'A policy-share refund is reported wrongly: '.json_encode([$detail['refunded_amount'],$totalsBefore,$totals]));
    payExpect((int)$totals['paid_count']===(int)$totalsBefore['paid_count'] && (int)$totals['refunded_count']===(int)$totalsBefore['refunded_count']+1,'The Collected count dropped a booking the kept money comes from: '.json_encode([$totalsBefore,$totals]));
    payCall('CALL sp_test_payment_old_refund(?,?)',[$oldShape,1]);
    $state=payInspect($oldShape);
    payExpect((float)$state['cancel_refund']===100000.0 && (float)$state['refund_audit_amount']===220000.0 && $state['payment_status']==='refunded','The old-shape fixture is wrong: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $detail=$extras->bookingDetail($oldShape,$guestId);
    $row=payAdminRows($adminId,$oldShape)[0];
    $totals=$extras->adminPaymentTotals($adminId);
    payExpect((float)$detail['refund_amount']===100000.0 && (float)$detail['refunded_amount']===220000.0 && (float)$row['refund_amount']===100000.0 && (float)$row['refunded_amount']===220000.0,
        'A row refunded by older code shows the policy share: '.json_encode($row,JSON_UNESCAPED_UNICODE));
    payExpect((float)$totals['refunded_amount']-(float)$totalsBefore['refunded_amount']===220000.0 && (float)$totals['collected_amount']-(float)$totalsBefore['collected_amount']===-220000.0,
        'Totals of a row refunded by older code are wrong: '.json_encode([$totalsBefore,$totals]));
    payExpect((int)$totals['paid_count']===(int)$totalsBefore['paid_count']-1 && (int)$totals['refunded_count']===(int)$totalsBefore['refunded_count']+1,'The Collected count kept a booking with no money left: '.json_encode([$totalsBefore,$totals]));
    payCall('CALL sp_test_payment_old_refund(?,?)',[$oldShape,0]);
    payExpect((float)$extras->bookingDetail($oldShape,$guestId)['refunded_amount']===220000.0 && (float)$extras->adminPaymentTotals($adminId)['refunded_amount']===(float)$totals['refunded_amount'],'A refund without an audit row is not read as the full amount.');
    payExpect($extras->bookingDetail($bookingG,$guestId)['refunded_amount']===null,'A booking that was not refunded reports a refunded figure.');
    echo "PASS owed vs refunded: cancelling after a refund owes nothing and keeps the figure; policy-share refunds and rows refunded by older code report what really went back\n";

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
        // A second payment: distinct Guest message, Admin form on the list and the booking page, CSRF and role guards.
        $bookingK=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,45);
        $kVnpay=$payments->start($bookingK,$guestId,'vnpay');
        $kQr=$payments->start($bookingK,$guestId,'vietqr');
        $payments->submitTransfer((int)$kQr['transaction_id'],$guestId);
        $payments->decideTransfer((int)$kQr['transaction_id'],$adminId,'confirm','');
        $signedK=payVnpayResult($kVnpay,$paymentConfig['vnpay_tmn_code'],$paymentConfig['vnpay_hash_secret'],'00','220000','15000010');
        $response=payHttp($guestClient,$baseUrl,'/payments/vnpay/return?'.http_build_query($signedK));
        payExpect($response['status']===302 && $response['location']==='/bookings/'.$bookingK,'Duplicate VNPay return did not open the booking.');
        $detail=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingK)['body'];
        payExpect(str_contains($detail,'thanh toán trùng') && str_contains($detail,'Admin sẽ hoàn lại') && !str_contains($detail,'Thanh toán VNPay thành công'),'Guest got a plain success for a duplicate payment.');
        $ipn=json_decode(payHttp($anonymous,$baseUrl,'/payments/vnpay/ipn?'.http_build_query($signedK))['body'],true);
        payExpect(($ipn['RspCode']??'')==='02','Repeating a duplicate over IPN did not answer 02.');
        $duplicatePath='/admin/payments/duplicates/'.(int)$kVnpay['transaction_id'];
        payExpect(str_contains(payHttp($adminClient,$baseUrl,'/admin/payments?q='.$bookingK)['body'],$duplicatePath) && str_contains(payHttp($adminClient,$baseUrl,'/bookings/'.$bookingK)['body'],$duplicatePath),'Admin cannot reach the duplicate form.');
        payExpect(!str_contains($detail,$duplicatePath),'Guest was shown the Admin duplicate form.');
        payExpect(payHttp($guestClient,$baseUrl,$duplicatePath,['_token'=>$guestToken,'note'=>'Tự hoàn'])['status']===403,'Guest posted the Admin duplicate action.');
        payHttp($adminClient,$baseUrl,$duplicatePath,['note'=>'Thiếu mã CSRF']);
        payExpect(payAttempt((int)$kVnpay['transaction_id'])['status']==='succeeded','Duplicate action without CSRF token was accepted.');
        $response=payHttp($adminClient,$baseUrl,$duplicatePath,['_token'=>$adminToken,'note'=>'Đã hoàn qua ngân hàng','return_to'=>'detail','booking_id'=>(string)$bookingK]);
        $state=payInspect($bookingK);
        payExpect($response['status']===302 && $response['location']==='/bookings/'.$bookingK && payAttempt((int)$kVnpay['transaction_id'])['status']==='cancelled' && $state['payment_status']==='paid',
            'Admin could not close the duplicate over HTTP: '.json_encode($state));
        // Money landing on a cancelled booking or a taken-down listing: the Guest is told it goes back, on landing and on reload.
        $lateCancelled=payConfirmedBooking($service,$policyListings[50],$guestId,$hostId,$base,119);
        $lateCancelledAttempt=$payments->start($lateCancelled,$guestId,'vnpay');
        $service->cancelByGuest($lateCancelled,$guestId,'Audit fixture');
        $lateCancelledSigned=payVnpayResult(['booking_id'=>$lateCancelled,'reference'=>$lateCancelledAttempt['reference']],$paymentConfig['vnpay_tmn_code'],$paymentConfig['vnpay_hash_secret'],'00','220000','15000030');
        foreach ([1,2] as $visit) {
            payHttp($guestClient,$baseUrl,'/payments/vnpay/return?'.http_build_query($lateCancelledSigned));
            $detail=payHttp($guestClient,$baseUrl,'/bookings/'.$lateCancelled)['body'];
            payExpect(str_contains($detail,'đã hủy. Admin sẽ hoàn lại 220.000đ cho bạn') && !str_contains($detail,'Thanh toán VNPay thành công'),'VNPay on a cancelled booking still shows a plain success (visit '.$visit.').');
        }
        $lateRemoved=payConfirmedBooking($service,$removedListing,$guestId,$hostId,$base,122);
        $lateRemovedAttempt=$payments->start($lateRemoved,$guestId,'vnpay');
        payCall('CALL sp_test_payment_listing_removed(?,?)',[$removedListing,1]);
        payHttp($guestClient,$baseUrl,'/payments/vnpay/return?'.http_build_query(payVnpayResult(['booking_id'=>$lateRemoved,'reference'=>$lateRemovedAttempt['reference']],$paymentConfig['vnpay_tmn_code'],$paymentConfig['vnpay_hash_secret'],'00','220000','15000031')));
        $detail=payHttp($guestClient,$baseUrl,'/bookings/'.$lateRemoved)['body'];
        payCall('CALL sp_test_payment_listing_removed(?,?)',[$removedListing,0]);
        payExpect(str_contains($detail,'đã bị gỡ. Admin sẽ hoàn lại khoản này cho bạn') && !str_contains($detail,'Thanh toán VNPay thành công'),'VNPay on a taken-down listing still shows a plain success.');
        // Reloading a return URL tells the state now, not the state when the money arrived.
        $response=payHttp($guestClient,$baseUrl,'/payments/vnpay/return?'.http_build_query($signedK));
        $detail=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingK)['body'];
        payExpect($response['status']===302 && str_contains($detail,'đã được hoàn lại cho bạn') && !str_contains($detail,'Admin sẽ hoàn lại'),'Reloading the return URL of a closed duplicate still promises a refund.');
        payHttp($adminClient,$baseUrl,'/admin/payments/'.$bookingE.'/refund',['_token'=>$adminToken,'note'=>'Hoàn khoản VNPay']);
        payHttp($guestClient,$baseUrl,'/payments/vnpay/return?'.http_build_query($valid));
        $detail=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingE)['body'];
        payExpect(str_contains($detail,'đã được ghi nhận hoàn tiền') && !str_contains($detail,'Thanh toán VNPay thành công'),'Reloading the return URL of a refunded payment still says plain success.');
        // Closing a duplicate on a refunded booking: the flash fits that state.
        $refundedDup=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,116);
        $refundedDupFirst=$payments->start($refundedDup,$guestId,'vnpay'); $refundedDupSecond=$payments->start($refundedDup,$guestId,'vnpay');
        $payments->vnpayResult($refundedDupFirst['reference'],'220000.00','00','00','15000012','NCB');
        $payments->vnpayResult($refundedDupSecond['reference'],'220000.00','00','00','15000013','NCB');
        payHttp($adminClient,$baseUrl,'/admin/payments/'.$refundedDup.'/refund',['_token'=>$adminToken,'note'=>'Hoàn khoản chính']);
        payHttp($adminClient,$baseUrl,'/admin/payments/duplicates/'.(int)$refundedDupSecond['transaction_id'],['_token'=>$adminToken,'note'=>'Hoàn khoản trùng']);
        $page=payHttp($adminClient,$baseUrl,'/admin/payments')['body'];
        payExpect(str_contains($page,'cũng đã được hoàn trước đó') && !str_contains($page,'Booking vẫn ở trạng thái đã thanh toán'),'Flash after closing a duplicate on a refunded booking is wrong.');
        // A transfer still open on a paid booking offers closing it, not confirming it.
        $bookingL=payConfirmedBooking($service,$listingId,$guestId,$hostId,$base,48);
        $lVnpay=$payments->start($bookingL,$guestId,'vnpay');
        $lQr=$payments->start($bookingL,$guestId,'vietqr');
        $payments->submitTransfer((int)$lQr['transaction_id'],$guestId);
        $payments->vnpayResult($lVnpay['reference'],'220000.00','00','00','15000011','NCB');
        foreach (['/admin/payments','/bookings/'.$bookingL,'/payments/'.(int)$lQr['transaction_id']] as $path) {
            $page=payHttp($adminClient,$baseUrl,$path)['body'];
            payExpect(str_contains($page,'Đóng giao dịch trùng') && !preg_match('/transfers\/'.(int)$lQr['transaction_id'].'"(?:(?!<\/form>).)*value="confirm"/s',$page),'Admin was offered a confirmation on a paid booking: '.$path);
        }
        payHttp($adminClient,$baseUrl,'/admin/payments/transfers/'.(int)$lQr['transaction_id'],['_token'=>$adminToken,'decision'=>'reject','note'=>'Đã thanh toán qua VNPay']);
        $page=payHttp($adminClient,$baseUrl,'/admin/payments')['body'];
        payExpect(str_contains($page,'Đã đóng giao dịch chuyển khoản trùng') && !str_contains($page,'chưa nhận được tiền chuyển khoản'),'Admin was told a closed duplicate never arrived.');
        foreach (['/payments/'.(int)$lQr['transaction_id'],'/bookings/'.$bookingL] as $path) {
            $page=payHttp($guestClient,$baseUrl,$path)['body'];
            payExpect(str_contains($page,'Admin sẽ hoàn lại cho khách') && !str_contains($page,'chưa xác nhận được khoản chuyển'),'Guest page of a closed duplicate transfer is wrong: '.$path);
        }
        // Policy refund on the Admin list and the booking page; a zero refund offers no form.
        $bookingP=payPaidBooking($service,$payments,$policyListings[50],$guestId,$hostId,$base,60,'15000020');
        $bookingQ=payPaidBooking($service,$payments,$policyListings[0],$guestId,$hostId,$base,60,'15000021');
        foreach ([$bookingP,$bookingQ] as $paidBooking) {
            $response=payHttp($guestClient,$baseUrl,'/bookings/'.$paidBooking.'/cancel',['_token'=>$guestToken,'reason'=>'Audit fixture']);
            payExpect($response['status']===302 && payInspect($paidBooking)['booking_status']==='cancelled','Guest could not cancel a paid booking over HTTP.');
        }
        payExpect(str_contains(payHttp($guestClient,$baseUrl,'/bookings')['body'],'không được hoàn'),'Cancelling with no refund does not say so.');
        $page=payHttp($adminClient,$baseUrl,'/admin/payments?q='.$bookingP)['body'];
        payExpect(str_contains($page,'Cần hoàn 100.000đ') && str_contains($page,'Ghi nhận đã hoàn 100.000đ') && str_contains($page,'/admin/payments/'.$bookingP.'/refund'),'Admin list does not show the policy refund.');
        $page=payHttp($adminClient,$baseUrl,'/bookings/'.$bookingP)['body'];
        payExpect(str_contains($page,'Số tiền hoàn theo chính sách: 100.000 VND') && str_contains($page,'/admin/payments/'.$bookingP.'/refund'),'Booking page does not show the policy refund.');
        $page=payHttp($adminClient,$baseUrl,'/admin/payments?q='.$bookingQ)['body'];
        payExpect(str_contains($page,'Không hoàn tiền theo chính sách') && !str_contains($page,'/admin/payments/'.$bookingQ.'/refund'),'Admin list offers a refund of nothing.');
        foreach ([$adminClient,$guestClient] as $client) {
            $page=payHttp($client,$baseUrl,'/bookings/'.$bookingQ)['body'];
            payExpect(str_contains($page,'booking này không được hoàn tiền') && !str_contains($page,'/admin/payments/'.$bookingQ.'/refund'),'Booking page does not show the zero refund clearly.');
        }
        payHttp($adminClient,$baseUrl,'/admin/payments/'.$bookingP.'/refund',['_token'=>$adminToken,'note'=>'Đã hoàn theo chính sách']);
        $page=payHttp($adminClient,$baseUrl,'/admin/payments?q='.$bookingP)['body'];
        payExpect(str_contains($page,'Đã hoàn 100.000đ') && str_contains(payHttp($guestClient,$baseUrl,'/bookings/'.$bookingP)['body'],'Đã hoàn 100.000 VND'),'Refunded booking does not show the policy amount.');
        // A taken-down listing over HTTP: clear message, no way to start, no QR.
        $bookingU=payConfirmedBooking($service,$removedListing,$guestId,$hostId,$base,74);
        $uQr=$payments->start($bookingU,$guestId,'vietqr');
        payCall('CALL sp_test_payment_listing_removed(?,?)',[$removedListing,1]);
        $page=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingU.'/pay')['body'];
        payExpect(str_contains($page,'đã bị gỡ') && !str_contains($page,'/bookings/'.$bookingU.'/pay/vnpay'),'Payment page of a taken-down listing is wrong.');
        payExpect(!str_contains(payHttp($guestClient,$baseUrl,'/bookings/'.$bookingU)['body'],'/bookings/'.$bookingU.'/pay"'),'Booking page offers payment on a taken-down listing.');
        $response=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingU.'/pay/vnpay',['_token'=>$guestToken]);
        payExpect($response['status']===302 && $response['location']==='/bookings/'.$bookingU.'/pay' && str_contains(payHttp($guestClient,$baseUrl,'/bookings/'.$bookingU.'/pay')['body'],'đã bị gỡ'),'Direct VNPay POST on a taken-down listing was not refused.');
        $page=payHttp($guestClient,$baseUrl,'/payments/'.(int)$uQr['transaction_id'])['body'];
        payExpect(str_contains($page,'đã bị gỡ') && !str_contains($page,'img.vietqr.io') && !str_contains($page,'/transferred'),'Transfer page of a taken-down listing still takes money.');
        payCall('CALL sp_test_payment_listing_removed(?,?)',[$removedListing,0]);
        // An unpaid booking over HTTP: no refund figure or wording for the Guest or the Admin; a paid one keeps them.
        $bookingY=payConfirmedBooking($service,$policyListings[100],$guestId,$hostId,$base,89);
        $page=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingY)['body'];
        foreach (['Số tiền hoàn','mức hoàn','Mức hoàn'] as $word) { payExpect(!str_contains($page,$word),'Unpaid booking page shows: '.$word); }
        payExpect(str_contains($page,'/bookings/'.$bookingY.'/cancel') && str_contains($page,'Bạn chắc chắn muốn hủy booking này?'),'Unpaid booking lost its plain cancel button.');
        $response=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingY.'/cancel',['_token'=>$guestToken,'reason'=>'Audit fixture']);
        $page=payHttp($guestClient,$baseUrl,'/bookings')['body'];
        payExpect($response['status']===302 && str_contains($page,'Đã hủy booking.') && !str_contains($page,'Mức hoàn'),'Unpaid cancel flash talks about a refund.');
        foreach ([$guestClient,$adminClient] as $client) {
            $page=payHttp($client,$baseUrl,'/bookings/'.$bookingY)['body'];
            payExpect(str_contains($page,'Audit fixture PAY') || str_contains($page,(string)$bookingY),'Cancelled booking page did not load.');
            foreach (['Số tiền hoàn','mức hoàn','Mức hoàn','hoàn tiền','được hoàn','Chính sách hủy tại thời điểm đặt'] as $word) { payExpect(!str_contains($page,$word),'Cancelled unpaid booking page shows: '.$word); }
        }
        $bookingZ=payPaidBooking($service,$payments,$policyListings[100],$guestId,$hostId,$base,92,'14000042');
        $page=payHttp($guestClient,$baseUrl,'/bookings/'.$bookingZ)['body'];
        payExpect(str_contains($page,'Số tiền hoàn nếu hủy lúc này') && str_contains($page,'Hủy booking theo mức hoàn đang áp dụng?') && str_contains($page,'Chính sách hủy tại thời điểm đặt'),'A paid booking lost its refund figure.');
        payHttp($guestClient,$baseUrl,'/bookings/'.$bookingZ.'/cancel',['_token'=>$guestToken,'reason'=>'Audit fixture']);
        payExpect(str_contains(payHttp($guestClient,$baseUrl,'/bookings')['body'],'Mức hoàn dự kiến: 100'),'A paid cancel lost its refund flash.');
        payExpect(str_contains(payHttp($guestClient,$baseUrl,'/bookings/'.$bookingZ)['body'],'Số tiền hoàn đã ghi nhận'),'A paid cancelled booking lost its recorded refund.');
        // A refunded booking offers no further refund, before or after cancelling, and keeps the figure that went back; a row refunded by older code too.
        $refundedOpen=payPaidBooking($service,$payments,$policyListings[50],$guestId,$hostId,$base,104,'14000052');
        payHttp($adminClient,$baseUrl,'/admin/payments/'.$refundedOpen.'/refund',['_token'=>$adminToken,'note'=>'Hoàn trước khi hủy']);
        $page=payHttp($guestClient,$baseUrl,'/bookings/'.$refundedOpen)['body'];
        payExpect(str_contains($page,'Đã hoàn 220.000 VND') && !str_contains($page,'Số tiền hoàn') && !str_contains($page,'Hủy booking theo mức hoàn đang áp dụng?') && str_contains($page,'Bạn chắc chắn muốn hủy booking này?'),'A refunded booking still offers a further refund.');
        payHttp($guestClient,$baseUrl,'/bookings/'.$refundedOpen.'/cancel',['_token'=>$guestToken,'reason'=>'Audit fixture']);
        foreach ([$guestClient,$adminClient] as $client) {
            $page=payHttp($client,$baseUrl,'/bookings/'.$refundedOpen)['body'];
            payExpect(str_contains($page,'Đã hoàn 220.000 VND') && !str_contains($page,'Số tiền hoàn'),'Cancelling after a refund changed the booking page.');
        }
        payExpect(str_contains(payHttp($adminClient,$baseUrl,'/admin/payments?q='.$refundedOpen)['body'],'Đã hoàn 220.000đ'),'Admin list lost the refunded figure after the cancellation.');
        $refundedOld=payPaidBooking($service,$payments,$policyListings[50],$guestId,$hostId,$base,107,'14000053');
        payHttp($guestClient,$baseUrl,'/bookings/'.$refundedOld.'/cancel',['_token'=>$guestToken,'reason'=>'Audit fixture']);
        payHttp($adminClient,$baseUrl,'/admin/payments/'.$refundedOld.'/refund',['_token'=>$adminToken,'note'=>'Hoàn kiểu cũ']);
        payCall('CALL sp_test_payment_old_refund(?,?)',[$refundedOld,1]);
        payExpect(str_contains(payHttp($guestClient,$baseUrl,'/bookings/'.$refundedOld)['body'],'Đã hoàn 220.000 VND') && str_contains(payHttp($adminClient,$baseUrl,'/admin/payments?q='.$refundedOld)['body'],'Đã hoàn 220.000đ'),'A row refunded by older code shows the wrong figure.');
        // The reject note cannot confirm: all three pages render a textarea, and only an explicit decision counts.
        $bookingK=payConfirmedBooking($service,$policyListings[100],$guestId,$hostId,$base,95);
        $noteTransfer=(int)$payments->start($bookingK,$guestId,'vietqr')['transaction_id'];
        $payments->submitTransfer($noteTransfer,$guestId);
        foreach (['/admin/payments','/bookings/'.$bookingK,'/payments/'.$noteTransfer] as $path) {
            $page=payHttp($adminClient,$baseUrl,$path)['body'];
            payExpect(preg_match('/<textarea[^>]+id="transfer-note-'.$noteTransfer.'"/',$page)===1 && preg_match('/<input[^>]+id="transfer-note-'.$noteTransfer.'"/',$page)===0,'Transfer note on '.$path.' can still submit on Enter.');
        }
        foreach ([['note'=>'Audit Enter in the note'],['decision'=>'','note'=>'Audit'],['decision'=>'maybe','note'=>'Audit'],['decision'=>'confirm ','note'=>'Audit'],['decision'=>['confirm'],'note'=>'Audit']] as $fields) {
            $response=payHttp($adminClient,$baseUrl,'/admin/payments/transfers/'.$noteTransfer,['_token'=>$adminToken]+$fields);
            payExpect($response['status']===302 && payAttempt($noteTransfer)['status']==='submitted' && payInspect($bookingK)['payment_status']==='unpaid','An unclear decision changed the transfer: '.json_encode($fields));
            payExpect(str_contains(payHttp($adminClient,$baseUrl,'/admin/payments')['body'],'Quyết định chuyển khoản không hợp lệ.'),'An unclear decision was not refused with a message: '.json_encode($fields));
        }
        $response=payHttp($adminClient,$baseUrl,'/admin/payments/transfers/'.$noteTransfer,['_token'=>$adminToken,'decision'=>'reject','note'=>"Chưa thấy khoản này\nDòng thứ hai"]);
        payExpect($response['status']===302 && payAttempt($noteTransfer)['status']==='failed' && payInspect($bookingK)['payment_status']==='unpaid','Rejecting with a two-line note failed.');
        foreach ([$anonymous,$guestClient,$hostClient,$adminClient] as $client) { curl_close($client); }
        echo "PASS online payment HTTP: Guest-only pages, CSRF, QR page with account and reference, report, Admin queue/403/confirm, VNPay redirect signature, forged return/IPN 97, valid return, IPN 02/01/04\n";
    }
    $completed=true;
} finally {
    foreach ($policyListings as $policyListing) {
        try { payCall('CALL sp_test_payment_cleanup(?)',[$policyListing]); }
        catch (Throwable $exception) {
            fwrite(STDERR,'Online payment cleanup failed: '.$exception->getMessage()."\n");
            if ($completed) { throw new RuntimeException('Online payment fixture cleanup failed.'); }
        }
    }
    if ($listingId!==null) {
        try { payCall('CALL sp_test_payment_cleanup(?)',[$listingId]); }
        catch (Throwable $exception) {
            fwrite(STDERR,'Online payment cleanup failed: '.$exception->getMessage()."\n");
            if ($completed) { throw new RuntimeException('Online payment fixture cleanup failed.'); }
        }
    }
}
echo "PASS online payment fixtures removed (listing, bookings, attempts, payments, notifications, audit rows)\n";
