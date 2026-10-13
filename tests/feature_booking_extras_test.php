<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';

use App\Models\BookingExtras;
use App\Models\Listing;
use App\Models\User;
use App\Services\BookingService;
use Core\ProcedureConnection;

/*
 * Booking extras: flexible pricing (weekend/holiday/weekly/monthly), promo codes, Instant Book, pay-at-check-in payment status.
 * Works on the workspace dev database directly and inside fresh_setup_test.php (HOME2HOME_TEST_URL). Fixture dates are
 * today+4094..4190 days, aligned to a Monday. Everything created here is removed in `finally` through sp_test_ routines.
 */

function bxExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function bxCall(string $call, array $parameters=[]): array
{
    $statement=ProcedureConnection::connection()->prepare($call);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/** Runs $operation(...$arguments) and requires a business rejection whose message contains $needle. */
function bxRejects(callable $operation, array $arguments, string $needle, string $label): void
{
    try { $operation(...$arguments); }
    catch (DomainException $exception) {
        if ($needle!=='' && !str_contains($exception->getMessage(),$needle)) {
            throw new RuntimeException($label.': unexpected message "'.$exception->getMessage().'"');
        }
        return;
    }
    throw new RuntimeException($label.': the operation was accepted.');
}

function bxDay(DateTimeImmutable $base, int $offset): string
{
    return $base->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d');
}

function bxInspect(int $bookingId): array
{
    $rows=bxCall('CALL sp_test_booking_extras_inspect(?)',[$bookingId]);
    bxExpect(count($rows)===1,'Booking fixture #'.$bookingId.' is missing.');
    return $rows[0];
}

function bxBookingCount(int $listingId): int
{
    return count(bxCall('CALL sp_test_booking_extras_listing_bookings(?)',[$listingId]));
}

function bxAudits(string $action, int $entityId): int
{
    return (int)bxCall('CALL sp_test_booking_extras_audit_count(?,?)',[$action,$entityId])[0]['audits'];
}

/** Creates a fixture promotion through the Admin routine and remembers it for cleanup. */
function bxPromotion(BookingExtras $extras, int $adminId, array &$registry, string $code, array $overrides): int
{
    $today=new DateTimeImmutable('today');
    $data=array_merge(['code'=>$code,'description'=>'Audit fixture promo '.$code,'type'=>'percent','value'=>'10','max'=>null,'min_nights'=>null,
        'min_subtotal'=>null,'from'=>$today->format('Y-m-d'),'to'=>bxDay($today,30),'limit'=>null,'active'=>true],$overrides);
    $registry[$code]=null;
    $id=$extras->savePromotion($adminId,0,$data);
    $registry[$code]=$id;
    return $id;
}

function bxFindPromotion(BookingExtras $extras, int $adminId, string $code): ?array
{
    foreach ($extras->promotions($adminId) as $promotion) { if ($promotion['code']===$code) { return $promotion; } }
    return null;
}

function bxHttp(CurlHandle $curl, string $baseUrl, string $path, ?array $fields=null, string $accept='text/html'): array
{
    curl_setopt_array($curl,[CURLOPT_URL=>$baseUrl.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>60,
        CURLOPT_HTTPHEADER=>['Accept: '.$accept]]);
    if ($fields===null) { curl_setopt($curl,CURLOPT_HTTPGET,true); }
    else { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($fields)); }
    $body=curl_exec($curl);
    if ($body===false) { throw new RuntimeException('HTTP request failed: '.$path.' '.curl_error($curl)); }
    $location=(string)(curl_getinfo($curl,CURLINFO_REDIRECT_URL)?:'');
    return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>(string)$body,'location'=>(string)(parse_url($location,PHP_URL_PATH)??'')];
}

function bxClient(): CurlHandle
{
    $curl=curl_init();
    curl_setopt($curl,CURLOPT_COOKIEFILE,'');
    return $curl;
}

function bxToken(CurlHandle $curl, string $baseUrl, string $path): string
{
    $page=bxHttp($curl,$baseUrl,$path);
    bxExpect($page['status']===200 && (bool)preg_match('/name="csrf-token" content="([^"]+)"/',$page['body'],$match),'CSRF token missing on '.$path);
    return $match[1];
}

function bxLogin(CurlHandle $curl, string $baseUrl, string $email, string $password): void
{
    $page=bxHttp($curl,$baseUrl,'/login');
    bxExpect((bool)preg_match('/name="_token" value="([^"]+)"/',$page['body'],$match),'Login form token missing.');
    $result=bxHttp($curl,$baseUrl,'/login',['_token'=>$match[1],'email'=>$email,'password'=>$password]);
    bxExpect($result['status']===302 && $result['location']!=='/login','HTTP login failed for '.$email);
}

$app=require dirname(__DIR__).'/config/app.php';
$config=require dirname(__DIR__).'/config/database.php';
bxExpect(PHP_SAPI==='cli' && $app['env']==='local' && in_array($config['host'],['localhost','127.0.0.1','::1'],true),'Local test only.');
bxExpect($config['database']!=='db_home2home','Refusing to run against the shared demo database.');

if (($argv[1]??'')==='--worker') {
    // Separate process and PDO session with a deliberately stale snapshot, as in procedure_flow_test.php.
    $db=ProcedureConnection::connection(); $db->beginTransaction();
    bxCall('CALL sp_listing_booking_rules(?)',[(int)$argv[2]]);
    echo "READY\n"; fflush(STDOUT); fgets(STDIN);
    try {
        $result=(new BookingService())->book((int)$argv[2],(int)$argv[3],$argv[4],$argv[5],1);
        $db->commit(); echo json_encode(['created'=>$result['booking_id'],'status'=>$result['status']])."\n";
    } catch (DomainException $exception) {
        if ($db->inTransaction()) { $db->rollBack(); }
        echo json_encode(['blocked'=>true])."\n";
    }
    exit;
}

$users=new User();
$host=$users->findByEmail('host@home2home.test'); $guest=$users->findByEmail('guest@home2home.test'); $admin=$users->findByEmail('admin@home2home.test');
bxExpect($host && $guest && $admin,'Local seed accounts required.');
$hostId=(int)$host['id']; $guestId=(int)$guest['id']; $adminId=(int)$admin['id'];
$listings=new Listing(); $extras=new BookingExtras(); $service=new BookingService();
$listingId=null; $promotions=[]; $holidayDates=[]; $processes=[]; $completed=false;
$suffix=strtoupper(bin2hex(random_bytes(3)));
$today=new DateTimeImmutable('today');
$monday=$today->modify('+4100 days');
$monday=$monday->modify('-'.((int)$monday->format('N')-1).' days');

try {
    // ---- Fixture listing: 100.000đ/night, 20.000đ fee, flexible policy.
    $data=['property_type_id'=>(int)$listings->types()[0]['id'],'cancellation_policy_id'=>(int)$listings->policies()[0]['id'],
        'title'=>'Audit fixture BX '.bin2hex(random_bytes(6)),'address'=>'Test only','city'=>'Audit','description'=>'Booking extras fixture',
        'nightly_price'=>100000,'cleaning_fee'=>20000,'max_guests'=>2,'room_count'=>1,'bedroom_count'=>1,'bed_count'=>1,'check_in_time'=>'14:00','check_out_time'=>'11:00'];
    $listingId=$listings->create($hostId,$data);
    bxCall('CALL sp_test_listing_approve(?)',[$listingId]);

    // ---- Admin holidays (Wednesday D+2 and Friday D+4).
    $holidayDates=[bxDay($monday,2),bxDay($monday,4),bxDay($monday,3),bxDay($monday,90)];
    $firstHoliday=$extras->saveHoliday($adminId,$holidayDates[0],'Audit fixture BX lễ giữa tuần');
    $secondHoliday=$extras->saveHoliday($adminId,$holidayDates[1],'Audit fixture BX lễ thứ Sáu');
    bxExpect($extras->saveHoliday($adminId,$holidayDates[0],'Audit fixture BX lễ đã xác nhận')===$firstHoliday,'Saving an existing holiday date created a duplicate.');
    bxRejects([$extras,'saveHoliday'],[$guestId,$holidayDates[2],'Audit fixture BX guest'],'quyền quản trị','Guest saved a holiday');
    bxRejects([$extras,'saveHoliday'],[$adminId,$holidayDates[2],'   '],'Vui lòng nhập','Blank holiday name accepted');
    bxRejects([$extras,'holidays'],[$guestId],'quyền quản trị','Guest listed Admin holidays');
    $savedHoliday=null;
    foreach ($extras->holidays($adminId) as $holiday) { if ((int)$holiday['id']===$firstHoliday) { $savedHoliday=$holiday; } }
    bxExpect($savedHoliday!==null && $savedHoliday['name']==='Audit fixture BX lễ đã xác nhận' && (int)$savedHoliday['is_demo']===0,'Holiday rename/confirmation failed.');
    bxExpect(bxAudits('save_holiday',$firstHoliday)===2,'Holiday saves were not audited.');
    bxRejects([$extras,'upcomingHolidays'],[0],'không hợp lệ','Invalid holiday limit accepted');
    echo "PASS Admin holidays: create, rename/confirm by date, audit log, Admin-only and input guards\n";

    // ---- Host pricing rules.
    bxRejects([$extras,'saveHostSettings'],[$listingId,$guestId,'150000',null,null,null,false],'quyền','Guest changed booking settings');
    bxRejects([$extras,'saveHostSettings'],[$listingId,$hostId,'-1',null,null,null,false],'lớn hơn 0','Negative weekend price accepted');
    bxRejects([$extras,'saveHostSettings'],[$listingId,$hostId,null,'0',null,null,false],'lớn hơn 0','Free holiday nights accepted');
    bxRejects([$extras,'saveHostSettings'],[$listingId,$hostId,null,null,'95',null,false],'0 đến 90','Discount above 90% accepted');
    bxRejects([$extras,'hostSettings'],[$listingId,$guestId],'quyền','Guest read Host booking settings');
    $extras->saveHostSettings($listingId,$hostId,'150000','300000','10','20',false);
    $settings=$extras->hostSettings($listingId,$hostId);
    bxExpect($settings['moderation_status']==='approved' && (float)$settings['weekend_nightly_rate']===150000.0 && (float)$settings['holiday_nightly_rate']===300000.0
        && (float)$settings['weekly_discount_pct']===10.0 && (float)$settings['monthly_discount_pct']===20.0 && (int)$settings['instant_book']===0,'Host booking settings were not saved as entered.');
    bxExpect((int)$extras->listingRules($listingId)['instant_book']===0 && $extras->listingRules(1)===null,'Public booking rules are wrong.');
    echo "PASS Host pricing rules: owner-only save, range guards, no re-moderation\n";

    // ---- Quotes: Mon D .. Sun D+6 nights = 100k,100k,300k(holiday),100k,300k(holiday beats weekend),150k(weekend),100k.
    $d0=bxDay($monday,0); $d7=bxDay($monday,7);
    $week=$service->quote($listingId,$d0,$d7,1);
    bxExpect($week['nights']===7 && $week['subtotal']===1150000.0 && $week['length_discount']['type']==='weekly' && $week['length_discount']['amount']===115000.0
        && $week['fee']===20000.0 && $week['total']===1055000.0 && $week['instant_book']===false,'7-night weekend/holiday/weekly quote is wrong: '.json_encode($week));
    $labels=array_column($week['breakdown'],'label');
    bxExpect(in_array('4 đêm thường × 100.000đ',$labels,true) && in_array('1 đêm cuối tuần × 150.000đ',$labels,true) && in_array('2 đêm ngày lễ × 300.000đ',$labels,true)
        && in_array('Giảm ở từ 7 đêm (10%)',$labels,true),'Quote breakdown labels are wrong: '.json_encode($labels,JSON_UNESCAPED_UNICODE));
    $sum=0.0;
    foreach ($week['breakdown'] as $row) { if ($row['kind']!=='total') { $sum+=$row['amount']; } }
    bxExpect($sum===1055000.0 && end($week['breakdown'])['amount']===1055000.0,'Breakdown rows do not add up to the total.');
    $six=$service->quote($listingId,$d0,bxDay($monday,6),1);
    bxExpect($six['subtotal']===1050000.0 && $six['length_discount']['type']==='none' && $six['total']===1070000.0,'6-night quote applied a weekly discount: '.json_encode($six));
    $month=$service->quote($listingId,bxDay($monday,14),bxDay($monday,42),1);
    bxExpect($month['subtotal']===3200000.0 && $month['length_discount']['type']==='monthly' && $month['length_discount']['amount']===640000.0 && $month['total']===2580000.0,
        '28-night monthly quote is wrong (monthly must replace weekly): '.json_encode($month));
    $extras->saveHostSettings($listingId,$hostId,'150000',null,'10','20',false);
    bxExpect($service->quote($listingId,$d0,bxDay($monday,6),1)['total']===720000.0,'Without a holiday price a holiday night must fall back to weekend/base price.');
    $extras->saveHostSettings($listingId,$hostId,'150000','300000','10','20',false);
    $extras->deleteHoliday($adminId,$secondHoliday);
    bxExpect($service->quote($listingId,$d0,bxDay($monday,6),1)['total']===920000.0,'Deleted holiday still priced as a holiday.');
    bxExpect(bxAudits('delete_holiday',$secondHoliday)===1,'Holiday deletion was not audited.');
    bxRejects([$extras,'deleteHoliday'],[$adminId,$secondHoliday],'không tồn tại','Deleted holiday deleted twice');
    $secondHoliday=$extras->saveHoliday($adminId,$holidayDates[1],'Audit fixture BX lễ thứ Sáu');
    bxExpect($service->quote($listingId,$d0,$d7,1)['total']===1055000.0,'Re-created holiday was not priced again.');
    $plain=$service->quote(1,$d0,bxDay($monday,2),2);
    bxExpect($plain['total']===2650000.0 && $plain['instant_book']===false && $plain['length_discount']['type']==='none','Listing without rules changed price.');
    echo "PASS flexible pricing: weekend/holiday precedence, weekly (7+) and monthly (28+) not stacked, breakdown sums, unchanged listing without rules\n";

    // ---- Promotions.
    $codeA='TBX'.$suffix.'A'; $codeT='TBX'.$suffix.'T'; $codeE='TBX'.$suffix.'E'; $codeF='TBX'.$suffix.'F'; $codeI='TBX'.$suffix.'I'; $codeS='TBX'.$suffix.'S'; $codeX='TBX'.$suffix.'X';
    $promoA=bxPromotion($extras,$adminId,$promotions,$codeA,['value'=>'20','max'=>'50000','min_nights'=>2,'limit'=>1]);
    bxPromotion($extras,$adminId,$promotions,$codeT,[]);
    bxPromotion($extras,$adminId,$promotions,$codeE,['from'=>bxDay($today,-10),'to'=>bxDay($today,-1)]);
    bxPromotion($extras,$adminId,$promotions,$codeF,['from'=>bxDay($today,5)]);
    $promoI=bxPromotion($extras,$adminId,$promotions,$codeI,['active'=>false]);
    bxPromotion($extras,$adminId,$promotions,$codeS,['min_subtotal'=>'2000000']);
    bxPromotion($extras,$adminId,$promotions,$codeX,['type'=>'fixed','value'=>'5000000','max'=>'1']);
    $base=['code'=>'TBX'.$suffix.'Q','description'=>'Audit fixture promo','type'=>'percent','value'=>'10','max'=>null,'min_nights'=>null,'min_subtotal'=>null,
        'from'=>$today->format('Y-m-d'),'to'=>bxDay($today,30),'limit'=>null,'active'=>true];
    $promotions[$base['code']]=null;
    bxRejects([$extras,'savePromotion'],[$adminId,0,array_merge($base,['code'=>'ab'])],'phải gồm','Malformed promo code accepted');
    bxRejects([$extras,'savePromotion'],[$adminId,0,array_merge($base,['value'=>'150'])],'Giá trị giảm','Percent above 100 accepted');
    bxRejects([$extras,'savePromotion'],[$adminId,0,array_merge($base,['to'=>bxDay($today,-1)])],'Ngày kết thúc','Reversed validity accepted');
    bxRejects([$extras,'savePromotion'],[$guestId,0,$base],'quyền quản trị','Guest created a promotion');
    bxRejects([$extras,'savePromotion'],[$adminId,0,array_merge($base,['code'=>$codeA])],'đã tồn tại','Duplicate promo code accepted');
    bxExpect(bxFindPromotion($extras,$adminId,$codeX)['max_discount_amount']===null,'Fixed promotion kept a percent cap.');
    bxExpect(bxAudits('save_promotion',$promoA)===1,'Promotion save was not audited.');

    $withA=$service->quote($listingId,$d0,$d7,1,strtolower($codeA));
    bxExpect($withA['promo']['code']===$codeA && $withA['promo']['message']===null && $withA['promo']['discount']===50000.0 && $withA['total']===1005000.0,
        'Capped percent promo after weekly discount is wrong: '.json_encode($withA,JSON_UNESCAPED_UNICODE));
    bxExpect(in_array('Mã '.$codeA,array_column($withA['breakdown'],'label'),true),'Promo row missing from breakdown.');
    bxExpect($service->quote($listingId,$d0,$d7,1,$codeT)['total']===951500.0,'Uncapped 10% promo must apply to the subtotal after the weekly discount.');
    bxExpect($service->quote($listingId,$d0,bxDay($monday,6),1,$codeX)['total']===20000.0,'Fixed promo larger than the room subtotal must stop at zero (fee only).');
    $rejectedQuotes=[[$codeA,1,'từ 2 đêm'],[$codeE,7,'đã hết hạn'],[$codeF,7,'chưa đến ngày áp dụng'],[$codeI,7,'đã ngừng áp dụng'],
        [$codeS,7,'tiền phòng tối thiểu 2.000.000đ'],['TBX'.$suffix.'ZZ',7,'không tồn tại'],['ab',7,'không hợp lệ']];
    foreach ($rejectedQuotes as [$code,$nights,$needle]) {
        $quote=$service->quote($listingId,$d0,bxDay($monday,$nights),1,$code);
        bxExpect($quote['promo']['code']===null && str_contains((string)$quote['promo']['message'],$needle) && $quote['promo']['discount']===0.0
            && $quote['total']===($nights===1?120000.0:1055000.0),'Unusable promo '.$code.' was not reported or changed the price: '.json_encode($quote['promo'],JSON_UNESCAPED_UNICODE));
    }
    bxRejects([$service,'quote'],[$listingId,$d0,$d7,1,str_repeat('A',41)],'Mã khuyến mãi','Over-long promo input accepted');
    $extras->togglePromotion($adminId,$promoI,true);
    bxExpect($service->quote($listingId,$d0,$d7,1,$codeI)['promo']['code']===$codeI,'Re-enabled promotion was not applied.');
    $extras->togglePromotion($adminId,$promoI,false);
    bxExpect(bxAudits('toggle_promotion',$promoI)===2,'Promotion toggles were not audited.');
    bxRejects([$extras,'togglePromotion'],[$guestId,$promoI,true],'quyền quản trị','Guest toggled a promotion');
    echo "PASS promo codes: percent cap/min nights/min subtotal/validity/inactive/unknown/format, fixed capped at zero, applied after stay discount\n";

    bxRejects([$service,'book'],[$listingId,$guestId,$d0,$d7,1,$codeE],'đã hết hạn','Booking with an expired promo was created');
    bxExpect(bxBookingCount($listingId)===0,'A rejected promo booking left a row behind.');
    $promoBooking=$service->book($listingId,$guestId,$d0,$d7,1,$codeA);
    bxExpect($promoBooking['status']==='pending','Request-to-book listing created a confirmed booking.');
    $state=bxInspect($promoBooking['booking_id']);
    $expectedNights=implode(',',[$d0.'=100000',bxDay($monday,1).'=100000',bxDay($monday,2).'=300000',bxDay($monday,3).'=100000',
        bxDay($monday,4).'=300000',bxDay($monday,5).'=150000',bxDay($monday,6).'=100000']);
    bxExpect((float)$state['total_amount']===1005000.0 && (float)$state['nights_subtotal']===1150000.0 && $state['length_discount_type']==='weekly'
        && (float)$state['length_discount_amount']===115000.0 && $state['promotion_code']===$codeA && (float)$state['promotion_discount_amount']===50000.0
        && (float)$state['breakdown_total']===1005000.0 && (int)$state['is_instant_book']===0 && $state['payment_status']==='unpaid'
        && (float)$state['amount_due']===1005000.0 && (int)$state['events']===1 && $state['nights']===$expectedNights,'Stored booking price breakdown is wrong: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    $exhausted=$service->quote($listingId,bxDay($monday,14),bxDay($monday,16),1,$codeA);
    bxExpect(str_contains((string)$exhausted['promo']['message'],'hết lượt'),'Usage limit was not enforced in the quote.');
    bxRejects([$service,'book'],[$listingId,$guestId,bxDay($monday,14),bxDay($monday,16),1,$codeA],'hết lượt','Usage limit was not enforced at booking time');
    bxRejects([$extras,'deletePromotion'],[$adminId,$promoA],'không thể xóa','Used promotion was deleted');
    bxRejects([$extras,'savePromotion'],[$adminId,$promoA,array_merge($base,['code'=>'TBX'.$suffix.'R'])],'Không thể đổi mã','Used promotion code was renamed');
    $promotions['TBX'.$suffix.'R']=null;
    bxRejects([$extras,'markPaid'],[$promoBooking['booking_id'],$hostId,false,''],'đã xác nhận hoặc đã hoàn thành','Pending booking was marked paid');
    $service->cancelByGuest($promoBooking['booking_id'],$guestId,'Audit fixture cancellation');
    bxExpect($service->quote($listingId,bxDay($monday,14),bxDay($monday,16),1,$codeA)['promo']['code']===$codeA,'Cancelled booking still consumed the promo use.');
    $listed=bxFindPromotion($extras,$adminId,$codeA);
    bxExpect((int)$listed['used_count']===0 && (int)$listed['booking_count']===1,'Promotion usage counters are wrong.');
    bxExpect($extras->bookingDetail($promoBooking['booking_id'],$guestId)['payment_state']==='not_required','Cancelled booking still expects payment.');
    echo "PASS promo booking: server-side total/breakdown/night snapshot stored, usage limit atomic, used code immutable, cancellation frees the use\n";

    // ---- Instant Book.
    $extras->saveHostSettings($listingId,$hostId,'150000','300000','10','20',true);
    bxExpect((int)$extras->listingRules($listingId)['instant_book']===1 && $service->quote($listingId,$d0,$d7,1)['instant_book']===true,'Instant Book flag not public.');
    $instant=$service->book($listingId,$guestId,bxDay($monday,7),bxDay($monday,9),1);
    bxExpect($instant['status']==='confirmed','Instant Book booking was not confirmed.');
    $state=bxInspect($instant['booking_id']);
    bxExpect($state['status']==='confirmed' && (float)$state['total_amount']===220000.0 && (int)$state['events']===2 && (int)$state['instant_events']===1
        && (int)$state['guest_confirmed_notices']===1 && (int)$state['host_instant_notices']===1 && (int)$state['is_instant_book']===1
        && $state['payment_status']==='unpaid','Instant Book events/notifications are wrong: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    bxRejects([$service,'book'],[$listingId,$guestId,bxDay($monday,8),bxDay($monday,10),1],'','Instant Book allowed an overlapping stay');
    bxExpect($listings->setAvailability($listingId,$hostId,bxDay($monday,11),false,'Audit block'),'Could not block a fixture date.');
    bxRejects([$service,'book'],[$listingId,$guestId,bxDay($monday,11),bxDay($monday,12),1],'','Instant Book allowed a blocked date');
    for ($i=0;$i<2;$i++) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__FILE__,'--worker',(string)$listingId,(string)$guestId,bxDay($monday,50),bxDay($monday,52)],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),null,['bypass_shell'=>true]);
        bxExpect(is_resource($process),'Could not start a concurrency worker.');
        $processes[]=[$process,$pipes];
        bxExpect(trim((string)fgets($pipes[1]))==='READY','Concurrency worker did not initialize.');
    }
    foreach ($processes as [$process,$pipes]) { fwrite($pipes[0],"GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
    $created=0; $blocked=0;
    foreach ($processes as [$process,$pipes]) {
        $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit=proc_close($process);
        bxExpect($exit===0 && $error==='','Concurrency worker failed: '.$error);
        $result=json_decode(trim((string)$output),true,512,JSON_THROW_ON_ERROR);
        if (isset($result['created'])) { $created++; bxExpect($result['status']==='confirmed','Concurrent Instant Book was not confirmed.'); }
        if (!empty($result['blocked'])) { $blocked++; }
    }
    $processes=[];
    bxExpect($created===1 && $blocked===1,'Concurrent Instant Book requests were not serialized ('.$created.' created, '.$blocked.' blocked).');
    echo "PASS Instant Book: confirmed at once with system event + Guest/Host notifications; overlap, blocked date and two-process race rejected\n";

    // ---- Pay at check-in: payment status.
    $instantId=$instant['booking_id'];
    bxRejects([$extras,'markPaid'],[$instantId,$hostId,false,''],'từ ngày nhận phòng','Payment recorded before check-in');
    bxCall('CALL sp_test_booking_extras_backdate(?)',[$instantId]);
    bxRejects([$extras,'markPaid'],[$instantId,$guestId,false,''],'không có quyền','Guest marked a booking paid');
    bxRejects([$extras,'markPaid'],[$instantId,$adminId,false,''],'không có quyền','Non-owner marked a booking paid as Host');
    bxRejects([$extras,'markPaid'],[$instantId,$guestId,true,''],'không có quyền','Guest used the Admin payment path');
    $extras->markPaid($instantId,$hostId,false,'Tiền mặt khi nhận phòng');
    $state=bxInspect($instantId);
    bxExpect($state['payment_status']==='paid' && (int)$state['paid_by']===$hostId && (int)$state['payment_events']===1 && (int)$state['payment_notices']===1
        && (int)$state['payment_audits']===0,'Host payment record is wrong: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    bxRejects([$extras,'markPaid'],[$instantId,$hostId,false,''],'trước đó','Payment recorded twice');
    $detail=$extras->bookingDetail($instantId,$guestId);
    bxExpect($detail['payment_state']==='paid' && (int)$detail['can_mark_paid']===0 && $detail['payment_note']==='Tiền mặt khi nhận phòng','Guest payment detail is wrong.');
    bxRejects([$extras,'bookingDetail'],[$instantId,999999999],'quyền','Stranger read payment detail');
    bxExpect(($extras->paymentMap($guestId,'guest')[$instantId]['payment_state']??'')==='paid' && isset($extras->paymentMap($hostId,'host')[$instantId]),'Payment maps are wrong.');
    bxRejects([$extras,'paymentMap'],[$guestId,'host'],'quyền','Guest listed Host payments');
    bxRejects([$extras,'paymentMap'],[$guestId,'admin'],'không hợp lệ','Unknown payment scope accepted');
    bxRejects([$extras,'refund'],[$instantId,$hostId,'Hoàn tiền'],'quyền quản trị','Host refunded a payment');
    bxRejects([$extras,'refund'],[$instantId,$adminId,'  '],'ghi chú','Refund without a note accepted');
    $extras->refund($instantId,$adminId,'Audit fixture refund');
    $state=bxInspect($instantId);
    bxExpect($state['payment_status']==='refunded' && (int)$state['refunded_by']===$adminId && (int)$state['payment_events']===2 && (int)$state['payment_audits']===1
        && (int)$state['payment_notices']===2,'Admin refund record is wrong: '.json_encode($state,JSON_UNESCAPED_UNICODE));
    bxRejects([$extras,'refund'],[$instantId,$adminId,'Again'],'Chỉ hoàn tiền','Refunded payment refunded twice');
    $rows=$extras->adminPayments($adminId,'refunded',(string)$instantId);
    bxExpect(count($rows)===1 && (int)$rows[0]['booking_id']===$instantId && $rows[0]['payment_state']==='refunded','Admin payment filter/search failed.');
    bxRejects([$extras,'adminPayments'],[$adminId,'bogus',''],'không hợp lệ','Unknown payment filter accepted');
    bxRejects([$extras,'adminPayments'],[$guestId,'',''],'quyền quản trị','Guest listed Admin payments');
    $totals=$extras->adminPaymentTotals($adminId);
    bxExpect(isset($totals['collected_amount'],$totals['outstanding_amount']) && (float)$totals['refunded_amount']>=220000.0,'Admin payment totals are wrong.');
    $legacy=$service->book($listingId,$guestId,bxDay($monday,63),bxDay($monday,64),1);
    bxCall('CALL sp_test_booking_extras_forget_payment(?)',[$legacy['booking_id']]);
    bxCall('CALL sp_test_booking_extras_backdate(?)',[$legacy['booking_id']]);
    $detail=$extras->bookingDetail($legacy['booking_id'],$hostId);
    bxExpect((int)$detail['has_breakdown']===1 && $detail['payment_state']==='unpaid' && (float)$detail['amount_due']===120000.0 && (int)$detail['can_mark_paid']===1,'Booking without a payment row is not shown as unpaid.');
    $extras->markPaid($legacy['booking_id'],$adminId,true,'');
    $state=bxInspect($legacy['booking_id']);
    bxExpect($state['payment_status']==='paid' && (float)$state['amount_due']===120000.0 && (int)$state['payment_audits']===1,'Admin payment on a booking without a payment row failed.');
    echo "PASS pay at check-in: not before check-in, Host owner/Admin only, once only, Admin refund with note + audit, filters/totals, bookings without a payment row\n";

    // ---- HTTP (controllers, CSRF, roles, pages) when a server is available.
    $baseUrl=rtrim((string)(getenv('HOME2HOME_TEST_URL')?:($app['url']?:'')),'/');
    $probe=null;
    if ($baseUrl!=='') {
        $probe=curl_init($baseUrl.'/');
        curl_setopt_array($probe,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>3]);
        $reachable=curl_exec($probe)!==false && (int)curl_getinfo($probe,CURLINFO_RESPONSE_CODE)===200;
        curl_close($probe);
    } else { $reachable=false; }
    if (!$reachable) {
        echo "SKIP booking extras HTTP checks: no server answering at ".($baseUrl===''?'(no HOME2HOME_TEST_URL/APP_URL)':$baseUrl)."\n";
    } else {
        $password='Password123!';
        $anonymous=bxClient();
        $response=bxHttp($anonymous,$baseUrl,'/admin/payments');
        bxExpect($response['status']===302 && $response['location']==='/login','Anonymous user reached Admin payments.');
        $guestClient=bxClient(); bxLogin($guestClient,$baseUrl,'guest@home2home.test',$password);
        $hostClient=bxClient(); bxLogin($hostClient,$baseUrl,'host@home2home.test',$password);
        $adminClient=bxClient(); bxLogin($adminClient,$baseUrl,'admin@home2home.test',$password);
        $guestToken=bxToken($guestClient,$baseUrl,'/bookings');
        $hostToken=bxToken($hostClient,$baseUrl,'/host');
        $adminToken=bxToken($adminClient,$baseUrl,'/admin');
        foreach (['/admin/promotions','/admin/holidays','/admin/payments','/host/listings/'.$listingId.'/pricing'] as $path) {
            bxExpect(bxHttp($guestClient,$baseUrl,$path)['status']===403,'Guest opened '.$path);
        }
        bxExpect(bxHttp($guestClient,$baseUrl,'/admin/payments/'.$instantId.'/paid',['_token'=>$guestToken])['status']===403,'Guest posted an Admin payment action.');
        bxExpect(bxHttp($hostClient,$baseUrl,'/admin/promotions')['status']===403,'Host opened Admin promotions.');

        $page=bxHttp($hostClient,$baseUrl,'/host/listings/'.$listingId.'/pricing');
        bxExpect($page['status']===200 && str_contains($page['body'],'Đặt ngay (Instant Book)') && str_contains($page['body'],'name="weekend_nightly_rate"'),'Host pricing page failed.');
        bxExpect(bxHttp($hostClient,$baseUrl,'/host/listings/999999999/pricing')['status']===404,'Unknown listing pricing page did not 404.');
        bxExpect(str_contains(bxHttp($hostClient,$baseUrl,'/host')['body'],'/host/listings/'.$listingId.'/pricing'),'Host dashboard lacks the pricing link.');
        $form=['weekend_nightly_rate'=>'150000','holiday_nightly_rate'=>'300000','weekly_discount_pct'=>'10','monthly_discount_pct'=>'20'];
        $response=bxHttp($hostClient,$baseUrl,'/host/listings/'.$listingId.'/pricing',$form);
        bxExpect($response['status']===302 && (int)$extras->hostSettings($listingId,$hostId)['instant_book']===1,'Pricing POST without CSRF token changed settings.');
        $response=bxHttp($hostClient,$baseUrl,'/host/listings/'.$listingId.'/pricing',array_merge($form,['_token'=>$hostToken,'weekly_discount_pct'=>'abc']));
        bxExpect($response['status']===302 && str_contains(bxHttp($hostClient,$baseUrl,'/host/listings/'.$listingId.'/pricing')['body'],'Giảm giá theo tuần phải từ 0 đến 90%'),'Invalid pricing input was not rejected with a message.');
        $response=bxHttp($hostClient,$baseUrl,'/host/listings/'.$listingId.'/pricing',['_token'=>$hostToken]+$form);
        bxExpect($response['status']===302 && $response['location']==='/host/listings/'.$listingId.'/pricing' && (int)$extras->hostSettings($listingId,$hostId)['instant_book']===0,'Host could not switch Instant Book off.');
        $response=bxHttp($hostClient,$baseUrl,'/host/listings/'.$listingId.'/pricing',['_token'=>$hostToken,'instant_book'=>'1']+$form);
        bxExpect((int)$extras->hostSettings($listingId,$hostId)['instant_book']===1,'Host could not switch Instant Book on.');

        $query=http_build_query(['check_in'=>bxDay($monday,14),'check_out'=>bxDay($monday,21),'guests'=>1,'promo_code'=>strtolower($codeT)]);
        $quote=json_decode(bxHttp($anonymous,$baseUrl,'/api/listings/'.$listingId.'/quote?'.$query,null,'application/json')['body'],true);
        bxExpect(($quote['success']??false)===true && $quote['data']['promo']['code']===$codeT && (float)$quote['data']['total']===668000.0
            && (float)end($quote['data']['breakdown'])['amount']===668000.0,'Quote API with promo is wrong: '.json_encode($quote,JSON_UNESCAPED_UNICODE));
        $query=http_build_query(['check_in'=>bxDay($monday,14),'check_out'=>bxDay($monday,21),'guests'=>1,'promo_code'=>'TBX'.$suffix.'ZZ']);
        $quote=json_decode(bxHttp($anonymous,$baseUrl,'/api/listings/'.$listingId.'/quote?'.$query,null,'application/json')['body'],true);
        bxExpect(($quote['success']??false)===true && str_contains((string)$quote['data']['promo']['message'],'không tồn tại') && (float)$quote['data']['total']===740000.0,'Quote API did not report an unknown code.');

        $listingPage=bxHttp($guestClient,$baseUrl,'/listings/'.$listingId)['body'];
        bxExpect(str_contains($listingPage,'name="promo_code"') && str_contains($listingPage,'>Đặt ngay</button>') && str_contains($listingPage,'js/booking-extras.js')
            && str_contains($listingPage,'Giảm 10% khi ở từ 7 đêm'),'Listing page lacks promo input / Instant Book button / rules.');
        $before=bxBookingCount($listingId);
        $bookFields=['_token'=>$guestToken,'check_in'=>bxDay($monday,70),'check_out'=>bxDay($monday,72),'guests'=>1];
        $response=bxHttp($guestClient,$baseUrl,'/listings/'.$listingId.'/book',$bookFields+['promo_code'=>$codeE]);
        bxExpect($response['status']===302 && $response['location']==='/listings/'.$listingId && bxBookingCount($listingId)===$before
            && str_contains(bxHttp($guestClient,$baseUrl,'/listings/'.$listingId)['body'],'đã hết hạn'),'Expired promo booking POST was not rejected with a message.');
        $response=bxHttp($guestClient,$baseUrl,'/listings/'.$listingId.'/book',$bookFields+['promo_code'=>$codeT]);
        bxExpect($response['status']===302 && $response['location']==='/bookings' && str_contains(bxHttp($guestClient,$baseUrl,'/bookings')['body'],'Đặt ngay thành công'),'Instant Book POST failed.');
        $httpBookingId=max(array_map('intval',array_column(bxCall('CALL sp_test_booking_extras_listing_bookings(?)',[$listingId]),'id')));
        $state=bxInspect($httpBookingId);
        bxExpect($state['status']==='confirmed' && $state['promotion_code']===$codeT && (float)$state['total_amount']===200000.0,'HTTP booking did not store the server price.');
        $detailPage=bxHttp($guestClient,$baseUrl,'/bookings/'.$httpBookingId)['body'];
        bxExpect(str_contains($detailPage,'Mã khuyến mãi '.$codeT) && str_contains($detailPage,'id="payment-heading"') && str_contains($detailPage,'Đặt ngay'),'Guest booking detail lacks breakdown/payment.');
        bxExpect(str_contains(bxHttp($guestClient,$baseUrl,'/bookings')['body'],'Chưa thanh toán'),'Guest trips page lacks payment status.');

        $response=bxHttp($hostClient,$baseUrl,'/host/bookings/'.$httpBookingId.'/payment',['_token'=>$hostToken]);
        bxExpect($response['status']===302 && $response['location']==='/host' && bxInspect($httpBookingId)['payment_status']==='unpaid'
            && str_contains(bxHttp($hostClient,$baseUrl,'/host')['body'],'từ ngày nhận phòng'),'Future check-in payment POST was not rejected.');
        bxCall('CALL sp_test_booking_extras_backdate(?)',[$httpBookingId]);
        bxExpect(str_contains(bxHttp($hostClient,$baseUrl,'/host')['body'],'/host/bookings/'.$httpBookingId.'/payment'),'Host dashboard lacks the mark-paid action.');
        $response=bxHttp($hostClient,$baseUrl,'/host/bookings/'.$httpBookingId.'/payment',['_token'=>$hostToken,'return_to'=>'detail','note'=>'Chuyển khoản tại quầy']);
        bxExpect($response['status']===302 && $response['location']==='/bookings/'.$httpBookingId && bxInspect($httpBookingId)['payment_status']==='paid','Host mark-paid POST failed.');

        foreach (['/admin/holidays'=>'Audit fixture BX','/admin/promotions'=>$codeA,'/admin/promotions?edit='.$promoA=>'readonly','/admin/payments'=>'Đã thu',
            '/admin/payments?'.http_build_query(['status'=>'paid','q'=>(string)$httpBookingId])=>'#'.$httpBookingId,'/admin'=>'/admin/payments'] as $path=>$needle) {
            $page=bxHttp($adminClient,$baseUrl,$path);
            bxExpect($page['status']===200 && str_contains($page['body'],$needle),'Admin page failed: '.$path);
        }
        $response=bxHttp($adminClient,$baseUrl,'/admin/payments/'.$httpBookingId.'/refund',['_token'=>$adminToken,'note'=>'']);
        bxExpect($response['status']===302 && bxInspect($httpBookingId)['payment_status']==='paid','Refund without note was accepted over HTTP.');
        $response=bxHttp($adminClient,$baseUrl,'/admin/payments/'.$httpBookingId.'/refund',['_token'=>$adminToken,'note'=>'Audit fixture HTTP refund']);
        bxExpect($response['status']===302 && $response['location']==='/admin/payments' && bxInspect($httpBookingId)['payment_status']==='refunded','Admin refund POST failed.');

        $codeH='TBX'.$suffix.'H'; $promotions[$codeH]=null;
        $promoForm=['code'=>strtolower($codeH),'description'=>'Audit fixture HTTP promo','discount_type'=>'fixed','discount_value'=>'50000','max_discount_amount'=>'',
            'min_nights'=>'2','min_subtotal'=>'','valid_from'=>$today->format('Y-m-d'),'valid_to'=>bxDay($today,10),'usage_limit'=>'5','is_active'=>'1'];
        bxHttp($adminClient,$baseUrl,'/admin/promotions',$promoForm);
        bxExpect(bxFindPromotion($extras,$adminId,$codeH)===null,'Promotion POST without CSRF token was saved.');
        $response=bxHttp($adminClient,$baseUrl,'/admin/promotions',['_token'=>$adminToken]+$promoForm);
        $saved=bxFindPromotion($extras,$adminId,$codeH);
        bxExpect($response['status']===302 && $saved!==null && $saved['discount_type']==='fixed' && (int)$saved['min_nights']===2 && (int)$saved['usage_limit']===5,'Admin promotion POST failed.');
        $promotions[$codeH]=(int)$saved['id'];
        $response=bxHttp($adminClient,$baseUrl,'/admin/promotions',['_token'=>$adminToken]+array_merge($promoForm,['code'=>'x!']));
        bxExpect($response['status']===302 && str_contains(bxHttp($adminClient,$baseUrl,'/admin/promotions')['body'],'4–20'),'Invalid promotion code was not rejected over HTTP.');
        bxHttp($adminClient,$baseUrl,'/admin/promotions/'.$saved['id'].'/toggle',['_token'=>$adminToken,'active'=>'0']);
        bxExpect((int)bxFindPromotion($extras,$adminId,$codeH)['is_active']===0,'Admin promotion toggle POST failed.');
        bxHttp($adminClient,$baseUrl,'/admin/promotions/'.$saved['id'].'/delete',['_token'=>$adminToken]);
        bxExpect(bxFindPromotion($extras,$adminId,$codeH)===null && bxAudits('delete_promotion',(int)$saved['id'])===1,'Admin promotion delete POST failed.');

        $response=bxHttp($adminClient,$baseUrl,'/admin/holidays',['_token'=>$adminToken,'holiday_date'=>$holidayDates[3],'name'=>'Audit fixture BX HTTP']);
        $httpHoliday=null;
        foreach ($extras->holidays($adminId) as $holiday) { if ($holiday['holiday_date']===$holidayDates[3]) { $httpHoliday=(int)$holiday['id']; } }
        bxExpect($response['status']===302 && $httpHoliday!==null,'Admin holiday POST failed.');
        bxHttp($adminClient,$baseUrl,'/admin/holidays/'.$httpHoliday.'/delete',['_token'=>$adminToken]);
        bxExpect(bxAudits('delete_holiday',$httpHoliday)===1,'Admin holiday delete POST failed.');
        foreach ([$anonymous,$guestClient,$hostClient,$adminClient] as $client) { curl_close($client); }
        echo "PASS booking extras HTTP: role 403s, CSRF rejection, pricing/promotions/holidays/payments pages and POSTs, quote API promo, Instant Book POST, mark paid/refund\n";
    }
    $completed=true;
} finally {
    foreach ($processes as [$process,$pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        proc_close($process);
    }
    $cleanupErrors=[];
    if ($listingId!==null) {
        try { bxCall('CALL sp_test_booking_extras_cleanup(?)',[$listingId]); }
        catch (Throwable $exception) { $cleanupErrors[]='listing '.$listingId.': '.$exception->getMessage(); }
    }
    foreach ($promotions as $code=>$id) {
        try { bxCall('CALL sp_test_booking_extras_cleanup_promotion(?,?)',[$code,$id]); }
        catch (Throwable $exception) { $cleanupErrors[]='promotion '.$code.': '.$exception->getMessage(); }
    }
    foreach (array_unique($holidayDates) as $date) {
        try { bxCall('CALL sp_test_booking_extras_cleanup_holiday(?)',[$date]); }
        catch (Throwable $exception) { $cleanupErrors[]='holiday '.$date.': '.$exception->getMessage(); }
    }
    if ($cleanupErrors) {
        fwrite(STDERR,'Booking extras cleanup failed: '.implode('; ',$cleanupErrors)."\n");
        if ($completed) { throw new RuntimeException('Booking extras fixture cleanup failed.'); }
    }
}
echo "PASS booking extras fixtures removed (listing, bookings, payments, promotions, holidays, audit rows)\n";
